<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\helpers\Json;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\models\Settings;
use justinholtweb\bee\Plugin;
use yii\base\Component;

/**
 * The only place an HTTP request leaves this plugin.
 *
 * Everything — catalog pushes, interactions, recommendations, the diagnostics screen, the console
 * commands — goes through `request()`. That is what makes signing, retries, the dry-run switch and
 * the connection log impossible to bypass, and it is why the log can be trusted as a complete
 * record of what Bee did.
 *
 * The wire protocol was taken from Recombee's own PHP client rather than guessed:
 * HMAC-SHA1 over the path *including* the query string and the appended `hmac_timestamp`, with
 * `hmac_sign` appended last and not itself signed.
 */
class Client extends Component
{
    /** Recombee's regional API hosts. */
    public const HOSTS = [
        'us-west' => 'rapi-us-west.recombee.com',
        'eu-west' => 'rapi-eu-west.recombee.com',
        'ap-se' => 'rapi-ap-se.recombee.com',
        'ca-east' => 'rapi-ca-east.recombee.com',
    ];

    private ?GuzzleClient $guzzle = null;

    /**
     * Swap the HTTP client.
     *
     * Two real uses: the integration suite, which drives every code path against a mock transport
     * rather than a live Recombee database; and an install that has to route outbound traffic
     * through a proxy with its own Guzzle configuration.
     */
    public function setHttpClient(?GuzzleClient $client): void
    {
        $this->guzzle = $client;
    }

    /** Requests made this request cycle, for the diagnostics footer. */
    private int $callCount = 0;

    private float $totalMs = 0.0;

    // ─── Verb shorthands ─────────────────────────────────────────────────────────────────────

    public function get(string $path, array $query = [], array $opts = []): mixed
    {
        return $this->request('GET', $path, null, $query, $opts);
    }

    public function put(string $path, array $body = [], array $query = [], array $opts = []): mixed
    {
        return $this->request('PUT', $path, $body, $query, $opts);
    }

    public function post(string $path, array $body = [], array $query = [], array $opts = []): mixed
    {
        return $this->request('POST', $path, $body, $query, $opts);
    }

    public function delete(string $path, array $query = [], array $opts = []): mixed
    {
        return $this->request('DELETE', $path, null, $query, $opts);
    }

    /**
     * Send many requests as one.
     *
     * Each request is `['method' => 'PUT', 'path' => '/items/e42', 'params' => [...]]` — paths here
     * are *database-relative*, exactly as Recombee wants them inside a batch.
     *
     * Chunked at the configured batch size and flattened back into one result list, so callers can
     * hand over ten thousand items and forget about it. The result at index N always corresponds to
     * the request at index N, including for chunks that failed wholesale.
     */
    public function batch(array $requests, array $opts = []): array
    {
        if ($requests === []) {
            return [];
        }

        $settings = $this->settings();
        $size = max(1, $settings->batchSize);
        $results = [];

        foreach (array_chunk(array_values($requests), $size) as $chunk) {
            try {
                $response = $this->request('POST', 'batch/', ['requests' => $chunk], [], $opts + [
                    // A batch of 500 writes legitimately takes longer than a single one.
                    'timeout' => max($settings->timeout, 1000 * count($chunk) / 10),
                    'label' => 'batch(' . count($chunk) . ')',
                ]);

                // Recombee answers with one {code, json} per sub-request, in order.
                foreach ($chunk as $i => $sub) {
                    $results[] = $response[$i] ?? ['code' => 0, 'json' => null];
                }
            } catch (ApiException $e) {
                // The chunk failed as a unit. Record that against every request in it rather than
                // losing the alignment between requests and results.
                foreach ($chunk as $sub) {
                    $results[] = ['code' => $e->status ?: 0, 'json' => $e->getMessage()];
                }
            }
        }

        return $results;
    }

    /**
     * @param array|null $body JSON body, or null for none
     * @param array $query Query-string parameters (signed along with the path)
     * @param array $opts timeout (ms), retries, label, failOpen
     * @throws ApiException
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], array $opts = []): mixed
    {
        $settings = $this->settings();
        $label = $opts['label'] ?? ($method . ' ' . $path);
        $timeout = (int)($opts['timeout'] ?? $settings->timeout);
        $retries = (int)($opts['retries'] ?? $settings->retries);

        if (!$settings->isConfigured()) {
            throw new ApiException(
                Craft::t('bee', 'Bee is not connected to a Recombee database yet.'),
                0,
                null,
                $path,
            );
        }

        if ($settings->dryRun) {
            Plugin::getInstance()->getLog()->record(
                $method,
                $path,
                200,
                0.0,
                $body,
                ['dryRun' => true],
                $label,
            );

            return $this->dryRunResponse($path, $body);
        }

        $url = $this->signedUrl($method, $path, $query);
        $started = microtime(true);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->guzzle()->request($method, $url, array_filter([
                    'json' => $body,
                    'timeout' => $timeout / 1000,
                    'connect_timeout' => min(5.0, $timeout / 1000),
                    'http_errors' => true,
                ], static fn($v) => $v !== null));

                $ms = (microtime(true) - $started) * 1000;
                $this->tally($ms);

                $raw = (string)$response->getBody();
                $decoded = $this->decode($raw);

                Plugin::getInstance()->getLog()->record(
                    $method,
                    $path,
                    $response->getStatusCode(),
                    $ms,
                    $body,
                    $decoded,
                    $label,
                );

                return $decoded;
            } catch (ConnectException $e) {
                $retryable = true;
                $status = 0;
                $responseBody = null;
                $message = $e->getMessage();
            } catch (RequestException $e) {
                $status = $e->getResponse()?->getStatusCode() ?? 0;
                $responseBody = $e->getResponse() ? (string)$e->getResponse()->getBody() : null;
                $message = $this->errorMessage($status, $responseBody, $e->getMessage());
                // 4xx is Bee's fault and will fail identically forever. 429 is the exception:
                // Recombee rate-limits per minute, and backing off is exactly the right response.
                $retryable = $status === 0 || $status === 429 || $status >= 500;
            } catch (\Throwable $e) {
                $retryable = false;
                $status = 0;
                $responseBody = null;
                $message = $e->getMessage();
            }

            $ms = (microtime(true) - $started) * 1000;

            if ($retryable && $attempt <= $retries) {
                // Exponential, with a floor high enough that a 429 actually clears.
                usleep((int)(($status === 429 ? 1_000_000 : 250_000) * (2 ** ($attempt - 1))));
                continue;
            }

            $this->tally($ms);

            Plugin::getInstance()->getLog()->record(
                $method,
                $path,
                $status,
                $ms,
                $body,
                $responseBody,
                $label,
                $message,
            );

            throw new ApiException($message, $status, $responseBody, $path);
        }
    }

    /**
     * Build the signed absolute URL.
     *
     * The order here is load-bearing and matches Recombee's clients exactly:
     *
     *   1. `/{databaseId}/{path}` plus any query string
     *   2. append `?hmac_timestamp=…` (or `&…` if there is already a query)
     *   3. HMAC-SHA1 that whole string with the private token
     *   4. append `&hmac_sign=…`, which is *not* part of the signed string
     *
     * Sign anything else — including signing before the query string is added — and every request
     * comes back 401 with no indication of why.
     */
    public function signedUrl(string $method, string $path, array $query = []): string
    {
        $settings = $this->settings();
        $uri = '/' . $settings->getParsedValue('databaseId') . '/' . ltrim($path, '/');

        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $uri .= (str_contains($uri, '?') ? '&' : '?') . 'hmac_timestamp=' . time();
        $sign = hash_hmac('sha1', $uri, $settings->getParsedValue('privateToken'));

        return $this->baseUrl() . $uri . '&hmac_sign=' . $sign;
    }

    public function baseUrl(): string
    {
        $settings = $this->settings();
        $override = $settings->getParsedValue('baseUri');

        if ($override !== '') {
            return rtrim(str_starts_with($override, 'http') ? $override : 'https://' . $override, '/');
        }

        return 'https://' . (self::HOSTS[$settings->region] ?? self::HOSTS['us-west']);
    }

    /**
     * A cheap authenticated call, used by the settings screen and `bee/diagnostics/check`.
     */
    public function ping(): array
    {
        $started = microtime(true);
        $this->get('items/properties/list/', [], ['label' => 'ping', 'retries' => 0, 'timeout' => 8000]);

        return [
            'ok' => true,
            'ms' => round((microtime(true) - $started) * 1000),
            'host' => parse_url($this->baseUrl(), PHP_URL_HOST),
        ];
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getTotalMs(): float
    {
        return $this->totalMs;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function tally(float $ms): void
    {
        $this->callCount++;
        $this->totalMs += $ms;
    }

    private function guzzle(): GuzzleClient
    {
        return $this->guzzle ??= Craft::createGuzzleClient([
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'craft-bee/' . Plugin::getInstance()->getVersion() . ' (Craft ' . Craft::$app->getVersion() . ')',
            ],
        ]);
    }

    /**
     * Recombee returns JSON for everything, but a bare `"ok"` for most writes. Decode uniformly and
     * let callers deal in PHP values.
     */
    private function decode(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }

        try {
            return Json::decode($raw);
        } catch (\Throwable) {
            return $raw;
        }
    }

    /**
     * Recombee puts the useful part of an error in the body, not the status line.
     */
    private function errorMessage(int $status, ?string $body, string $fallback): string
    {
        if ($body !== null && $body !== '') {
            $decoded = $this->decode($body);

            if (is_array($decoded) && isset($decoded['error'])) {
                return sprintf('%d: %s', $status, is_string($decoded['error']) ? $decoded['error'] : Json::encode($decoded['error']));
            }

            if (is_string($decoded) && $decoded !== '') {
                return sprintf('%d: %s', $status, mb_substr($decoded, 0, 500));
            }
        }

        if ($status === 401) {
            return Craft::t('bee', '401: Recombee rejected the credentials. Check the database ID, the private token and the region.');
        }

        return $fallback;
    }

    /**
     * What a request "returns" in dry-run mode. Shaped like the real thing so calling code takes
     * the same path it would in production — a dry run that returns null would exercise all the
     * null branches instead of the ones that matter.
     */
    private function dryRunResponse(string $path, ?array $body): mixed
    {
        if (str_starts_with($path, 'batch')) {
            return array_map(
                static fn() => ['code' => 200, 'json' => 'ok'],
                $body['requests'] ?? [],
            );
        }

        if (str_contains($path, 'recomms/') || str_contains($path, 'search/')) {
            return ['recommId' => 'dry-run', 'recomms' => [], 'numberNextRecommsCalls' => 0];
        }

        return 'ok';
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
