<?php
/**
 * Bee integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-bee/tests/integration/checks.php
 *
 * There is no live Recombee database here, and there should not be: a suite that needs one cannot
 * run in CI, and a suite that only runs against a happy path never sees a 429. Instead the HTTP
 * layer is swapped for a mock transport, which is *stricter* than a live database — every request
 * Bee makes is captured and asserted on, including the ones a real server would have quietly
 * accepted.
 *
 * The one thing a mock cannot verify is the signature, so that is checked against an independently
 * computed HMAC rather than against Bee's own code.
 *
 * Idempotent and self-cleaning: sources, sync rows, log rows and settings are all restored.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\helpers\Json;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\helpers\Props;
use justinholtweb\bee\helpers\Reql;
use justinholtweb\bee\models\Interaction;
use justinholtweb\bee\models\PropertyMap;
use justinholtweb\bee\models\RecommendationSet;
use justinholtweb\bee\models\Settings;
use justinholtweb\bee\models\Source;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\SyncRecord;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();
$originalEdition = $plugin->edition;
$createdSourceUids = [];

/**
 * Run a closure with a mock transport in place. Returns [$result, $history].
 *
 * @param array $responses Guzzle responses (or exceptions) to hand back, in order
 */
function withMock(array $responses, callable $fn): array
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $client = Plugin::getInstance()->getClient();
    $client->setHttpClient(new GuzzleClient(['handler' => $stack, 'http_errors' => true]));

    try {
        $result = $fn();
    } finally {
        $client->setHttpClient(null);
    }

    return [$result, $history];
}

function jsonResponse(mixed $body, int $status = 200): PsrResponse
{
    return new PsrResponse($status, ['Content-Type' => 'application/json'], Json::encode($body));
}

function requestBody(array $history, int $i): array
{
    $decoded = Json::decodeIfJson((string)$history[$i]['request']->getBody());

    return is_array($decoded) ? $decoded : [];
}

function requestUri(array $history, int $i): string
{
    return (string)$history[$i]['request']->getUri();
}

try {
    // Credentials the suite signs with. Never a real database.
    $settings->databaseId = 'bee-test-db';
    $settings->privateToken = 'not-a-real-token';
    $settings->region = 'eu-west';
    $settings->baseUri = '';
    $settings->enabled = true;
    $settings->dryRun = false;
    $settings->logMode = Settings::LOG_ALL;
    $settings->trackingEnabled = true;
    $settings->consentMode = Settings::CONSENT_ALWAYS;
    $settings->syncSites = '*';
    $settings->retries = 0;
    $settings->attribution = true;

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Item IDs');

    // A *live* entry: `Catalog::syncElement()` deliberately removes anything that is disabled,
    // pending or expired, so a fixture picked with `status(null)` would exercise the removal path
    // for every sync assertion below.
    $entry = Entry::find()->section('news')->status('live')->one();

    if ($entry === null) {
        throw new RuntimeException('The harness has no live news entries to build payloads from.');
    }

    check('an entry gets a prefixed, site-scoped ID while more than one site is synced', function() use ($entry, $settings) {
        $settings->syncSites = '*';
        $id = Ids::forElement($entry);

        return $id === 'e' . $entry->id . '-s' . $entry->siteId ?: "got " . var_export($id, true);
    });

    check('the site suffix disappears when only one site is synced', function() use ($entry, $settings) {
        $settings->syncSites = [$entry->siteId];
        $id = Ids::forElement($entry);
        $settings->syncSites = '*';

        return $id === 'e' . $entry->id ?: "got " . var_export($id, true);
    });

    check('parse() is the exact inverse of forElement()', function() use ($entry) {
        $parsed = Ids::parse(Ids::forElement($entry));

        return $parsed['type'] === Entry::class
            && $parsed['id'] === (int)$entry->id
            && $parsed['siteId'] === (int)$entry->siteId
            ?: 'got ' . Json::encode($parsed);
    });

    check('an ID Bee did not mint parses to null rather than half a match', function() {
        return Ids::parse('zzz9') === null
            && Ids::parse('e') === null
            && Ids::parse('') === null
            && Ids::parse('../../etc/passwd') === null;
    });

    check('user IDs use the UID, not the guessable integer ID', function() {
        $user = new craft\elements\User(['uid' => 'abc-123']);

        return Ids::forUser($user) === 'uabc-123' && Ids::isGuest('gdeadbeef') && !Ids::isGuest('uabc-123');
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Property coercion');

    check('numbers are typed, not stringified', function() {
        return Props::coerce('12.50', Props::TYPE_DOUBLE) === 12.5
            && Props::coerce('7', Props::TYPE_INT) === 7
            && Props::coerce(3.9, Props::TYPE_INT) === 3;
    });

    check('a value that cannot be the declared type is dropped, not guessed at', function() {
        return Props::coerce('twelve', Props::TYPE_DOUBLE) === null
            && Props::coerce('', Props::TYPE_STRING) === null
            && Props::coerce([], Props::TYPE_STRING) === null;
    });

    check('booleans understand the spellings a CMS actually produces', function() {
        return Props::coerce('yes', Props::TYPE_BOOLEAN) === true
            && Props::coerce('0', Props::TYPE_BOOLEAN) === false
            && Props::coerce(1, Props::TYPE_BOOLEAN) === true;
    });

    check('timestamps go out as epoch seconds, whatever came in', function() {
        $dt = new DateTime('2024-03-01 12:00:00', new DateTimeZone('UTC'));

        return Props::coerce($dt, Props::TYPE_TIMESTAMP) === $dt->getTimestamp()
            && Props::coerce('2024-03-01T12:00:00+00:00', Props::TYPE_TIMESTAMP) === $dt->getTimestamp();
    });

    check('sets de-duplicate and drop blanks', function() {
        $set = Props::coerce(['a', 'b', 'a', '', null], Props::TYPE_SET);

        return $set === ['a', 'b'] ?: 'got ' . Json::encode($set);
    });

    check('a root-relative image URL is promoted to absolute', function() {
        $url = Props::coerce('/uploads/x.jpg', Props::TYPE_IMAGE);

        return is_string($url) && str_starts_with($url, 'http') && str_ends_with($url, '/uploads/x.jpg')
            ?: 'got ' . var_export($url, true);
    });

    check('an element is never exploded into its attribute values', function() use ($entry) {
        // Every Craft element is Traversable, so a naive "flatten anything iterable" branch would
        // turn this entry into a list of attributes and lose the title entirely.
        $value = Props::coerce($entry, Props::TYPE_STRING);

        return $value === (string)$entry->title ?: 'got ' . var_export($value, true);
    });

    check('property names are validated against Recombee’s rules', function() {
        return Props::isValidName('salePrice')
            && Props::isValidName('_x1')
            && !Props::isValidName('1price')
            && !Props::isValidName('sale price')
            && !Props::isValidName('!internal');
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('ReQL');

    check('a string literal is quoted and escaped', function() {
        return Reql::value('a "b" c') === '"a \\"b\\" c"' ?: 'got ' . Reql::value('a "b" c');
    });

    check('an injected expression cannot escape the quotes', function() {
        // The whole point: a search term or slug reaching a filter must not be able to change it.
        $hostile = '" or \'enabled\' == true or "';
        $expr = Reql::equals('slug', $hostile);

        return substr_count($expr, '"') === 2 + substr_count($hostile, '"')
            && str_contains($expr, '\\"')
            ?: 'got ' . $expr;
    });

    check('scalars stay bare and arrays become ReQL sets', function() {
        return Reql::value(12) === '12'
            && Reql::value(true) === 'true'
            && Reql::value(null) === 'null'
            && Reql::value(['a', 'b']) === '{"a", "b"}';
    });

    check('all() drops empties and parenthesises the rest', function() {
        return Reql::all(['', '  ']) === null
            && Reql::all(['a == 1']) === 'a == 1'
            && Reql::all(['a == 1', 'b == 2']) === '(a == 1) and (b == 2)';
    });

    check('the live filter covers enabled, postDate and expiryDate', function() {
        $filter = Reql::liveOnly(3);

        return str_contains($filter, "'enabled' == true")
            && str_contains($filter, "'expiryDate'")
            && str_contains($filter, "'postDate'")
            && str_contains($filter, "'siteId' == 3")
            ?: 'got ' . $filter;
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Interaction shape');

    check('each kind posts to its own pluralised endpoint', function() {
        $paths = [];

        foreach (Interaction::KINDS as $kind) {
            $paths[$kind] = (new Interaction(['kind' => $kind]))->path();
        }

        return $paths === [
            'detailview' => 'detailviews/',
            'purchase' => 'purchases/',
            'cartaddition' => 'cartadditions/',
            'bookmark' => 'bookmarks/',
            'rating' => 'ratings/',
            'viewportion' => 'viewportions/',
        ] ?: 'got ' . Json::encode($paths);
    });

    check('a purchase carries amount/price/profit and nothing a purchase cannot have', function() {
        $body = (new Interaction([
            'kind' => Interaction::PURCHASE,
            'userId' => 'u1',
            'itemId' => 'p1',
            'amount' => 2.0,
            'price' => 9.99,
            'duration' => 30,
        ]))->body();

        return isset($body['amount'], $body['price'])
            && !isset($body['duration'])
            ?: 'got ' . Json::encode($body);
    });

    check('a detail view carries duration and nothing else', function() {
        $body = (new Interaction([
            'kind' => Interaction::DETAIL_VIEW,
            'userId' => 'u1',
            'itemId' => 'e1',
            'duration' => 12,
            'price' => 5.0,
        ]))->body();

        return isset($body['duration']) && !isset($body['price']) ?: 'got ' . Json::encode($body);
    });

    check('timestamps are epoch seconds, not an ISO string with a site-local offset', function() {
        $ts = new DateTime('2024-06-01 10:00:00', new DateTimeZone('UTC'));
        $body = (new Interaction([
            'kind' => Interaction::DETAIL_VIEW,
            'userId' => 'u1',
            'itemId' => 'e1',
            'timestamp' => $ts,
        ]))->body();

        return $body['timestamp'] === $ts->getTimestamp() ?: 'got ' . var_export($body['timestamp'], true);
    });

    check('ratings outside Recombee’s -1…1 scale are refused', function() {
        $bad = new Interaction(['kind' => Interaction::RATING, 'userId' => 'u', 'itemId' => 'i', 'rating' => 5.0]);
        $good = new Interaction(['kind' => Interaction::RATING, 'userId' => 'u', 'itemId' => 'i', 'rating' => 0.5]);

        return !$bad->validate() && $good->validate();
    });

    check('the dedupe key separates kinds, users, items and timestamps', function() {
        $a = new Interaction(['kind' => 'detailview', 'userId' => 'u1', 'itemId' => 'e1']);
        $b = new Interaction(['kind' => 'detailview', 'userId' => 'u2', 'itemId' => 'e1']);

        return $a->dedupeKey() !== $b->dedupeKey();
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Request signing');

    check('the signature matches an independently computed HMAC', function() use ($plugin, $settings) {
        $url = $plugin->getClient()->signedUrl('POST', 'items/e1');
        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        // Recombee signs the path plus the query *including* hmac_timestamp, but not hmac_sign.
        $signed = $parts['path'] . '?hmac_timestamp=' . $query['hmac_timestamp'];
        $expected = hash_hmac('sha1', $signed, $settings->privateToken);

        return $query['hmac_sign'] === $expected ?: "signed \"$signed\", got {$query['hmac_sign']}, expected $expected";
    });

    check('an existing query string is signed too, with & rather than ?', function() use ($plugin, $settings) {
        $url = $plugin->getClient()->signedUrl('PUT', 'items/properties/price', ['type' => 'double']);
        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        $signed = $parts['path'] . '?type=double&hmac_timestamp=' . $query['hmac_timestamp'];

        return $query['hmac_sign'] === hash_hmac('sha1', $signed, $settings->privateToken)
            ?: 'got ' . $url;
    });

    check('the path is prefixed with the database ID', function() use ($plugin) {
        return str_contains($plugin->getClient()->signedUrl('GET', 'items/list/'), '/bee-test-db/items/list/');
    });

    check('the region picks the host', function() use ($plugin, $settings) {
        $eu = $plugin->getClient()->baseUrl();
        $settings->region = 'us-west';
        $us = $plugin->getClient()->baseUrl();
        $settings->region = 'eu-west';

        return $eu === 'https://rapi-eu-west.recombee.com' && $us === 'https://rapi-us-west.recombee.com'
            ?: "$eu / $us";
    });

    check('a base URI override wins over the region', function() use ($plugin, $settings) {
        $settings->baseUri = 'my-recombee.example.com';
        $url = $plugin->getClient()->baseUrl();
        $settings->baseUri = '';

        return $url === 'https://my-recombee.example.com' ?: "got $url";
    });

    check('the token never appears in the log', function() use ($plugin, $settings) {
        Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();

        withMock([jsonResponse('ok')], fn() => $plugin->getClient()->post('items/e1', ['title' => 'x']));

        $rows = (new craft\db\Query())->from(Table::LOG)->all();
        $dump = Json::encode($rows);

        return $rows !== [] && !str_contains($dump, $settings->privateToken) && !str_contains($dump, 'hmac_sign')
            ?: 'log leaked something: ' . substr($dump, 0, 400);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Transport');

    check('a 5xx is retried and then succeeds', function() use ($plugin, $settings) {
        $settings->retries = 2;

        [$result, $history] = withMock([
            jsonResponse(['error' => 'boom'], 503),
            jsonResponse('ok'),
        ], fn() => $plugin->getClient()->post('items/e1', ['title' => 'x']));

        $settings->retries = 0;

        return $result === 'ok' && count($history) === 2 ?: 'attempts: ' . count($history);
    });

    check('a 4xx is not retried — it would fail identically forever', function() use ($plugin, $settings) {
        $settings->retries = 3;
        $attempts = 0;

        try {
            [, $history] = withMock([
                jsonResponse(['error' => 'no such property'], 400),
                jsonResponse('ok'),
                jsonResponse('ok'),
                jsonResponse('ok'),
            ], fn() => $plugin->getClient()->post('items/e1', ['bad' => 1]));
        } catch (ApiException $e) {
            $attempts = 1;
            $message = $e->getMessage();
        } finally {
            $settings->retries = 0;
        }

        return $attempts === 1 && str_contains($message ?? '', 'no such property')
            ?: 'got ' . ($message ?? 'no exception');
    });

    check('a 401 says what to check rather than repeating Guzzle', function() use ($plugin) {
        try {
            withMock([new PsrResponse(401, [], '')], fn() => $plugin->getClient()->get('items/list/'));
        } catch (ApiException $e) {
            return str_contains($e->getMessage(), 'region') ?: 'got ' . $e->getMessage();
        }

        return 'no exception';
    });

    check('a batch is chunked at the batch size and results stay aligned to requests', function() use ($plugin, $settings) {
        $settings->batchSize = 2;

        $requests = array_map(static fn($i) => [
            'method' => 'POST',
            'path' => "/items/e$i",
            'params' => ['title' => "t$i"],
        ], range(1, 5));

        [$results, $history] = withMock([
            jsonResponse([['code' => 200, 'json' => 'ok'], ['code' => 200, 'json' => 'ok']]),
            jsonResponse([['code' => 400, 'json' => 'bad'], ['code' => 200, 'json' => 'ok']]),
            jsonResponse([['code' => 200, 'json' => 'ok']]),
        ], fn() => $plugin->getClient()->batch($requests));

        $settings->batchSize = 500;

        return count($history) === 3
            && count($results) === 5
            && $results[2]['code'] === 400
            && requestBody($history, 0)['requests'][0]['path'] === '/items/e1'
            ?: 'chunks=' . count($history) . ' results=' . count($results);
    });

    check('a chunk that fails wholesale still returns one result per request', function() use ($plugin, $settings) {
        $settings->batchSize = 3;

        $requests = array_map(static fn($i) => ['method' => 'POST', 'path' => "/items/e$i", 'params' => []], range(1, 3));

        [$results] = withMock([new PsrResponse(500, [], 'nope')], fn() => $plugin->getClient()->batch($requests));

        $settings->batchSize = 500;

        return count($results) === 3 && $results[0]['code'] === 500 ?: 'got ' . Json::encode($results);
    });

    check('dry run sends nothing but still logs a payload', function() use ($plugin, $settings) {
        Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
        $settings->dryRun = true;

        [$result, $history] = withMock([], fn() => $plugin->getClient()->post('items/e1', ['title' => 'x']));

        $settings->dryRun = false;
        $logged = (new craft\db\Query())->from(Table::LOG)->count();

        return $history === [] && $result === 'ok' && (int)$logged === 1
            ?: 'requests=' . count($history) . ' logged=' . $logged;
    });

    check('an unconfigured install refuses to build a request at all', function() use ($plugin, $settings) {
        $settings->databaseId = '';

        try {
            $plugin->getClient()->get('items/list/');

            return 'no exception';
        } catch (ApiException $e) {
            return str_contains($e->getMessage(), 'not connected') ?: 'got ' . $e->getMessage();
        } finally {
            $settings->databaseId = 'bee-test-db';
        }
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Sources');

    $source = new Source([
        'name' => 'Bee test news',
        'elementType' => Entry::class,
        'groupUids' => [$entry->getSection()->uid],
        'liveOnly' => true,
        'properties' => [
            new PropertyMap(['name' => 'headline', 'type' => Props::TYPE_STRING, 'kind' => PropertyMap::KIND_ATTRIBUTE, 'value' => 'title']),
            new PropertyMap(['name' => 'wordCount', 'type' => Props::TYPE_INT, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'wordCount']),
            new PropertyMap(['name' => 'computed', 'type' => Props::TYPE_STRING, 'kind' => PropertyMap::KIND_TWIG, 'value' => '{{ object.title|upper }}']),
        ],
    ]);

    check('a source saves into project config', function() use ($plugin, $source, &$createdSourceUids) {
        $saved = $plugin->getSources()->save($source);
        $createdSourceUids[] = $source->uid;

        return $saved && $plugin->getSources()->get($source->uid)?->name === 'Bee test news'
            ?: 'errors: ' . Json::encode($source->getErrors());
    });

    check('a source claims the elements in its scope and refuses the ones outside it', function() use ($plugin, $entry, $source) {
        $other = Entry::find()->section('news')->status('live')->id(['not', $entry->id])->one() ?? $entry;
        $stranger = Entry::find()->status(null)->section(['not', 'news'])->one();

        $claims = $plugin->getSources()->forElement($entry)?->uid === $source->uid
            && $plugin->getSources()->forElement($other)?->uid === $source->uid;

        $refuses = $stranger === null || $plugin->getSources()->forElement($stranger)?->uid !== $source->uid;

        return ($claims && $refuses) ?: "claims=$claims refuses=$refuses";
    });

    check('a mapping cannot redefine a property Bee maintains itself', function() {
        $bad = new PropertyMap(['name' => 'postDate', 'type' => Props::TYPE_TIMESTAMP, 'kind' => PropertyMap::KIND_FIELD, 'value' => 'x']);

        return !$bad->validate() && $bad->hasErrors('name');
    });

    check('two mappings writing to the same property is a validation error', function() {
        $dupe = new Source([
            'name' => 'dupe',
            'elementType' => Entry::class,
            'properties' => [
                new PropertyMap(['name' => 'x', 'type' => Props::TYPE_STRING, 'kind' => PropertyMap::KIND_ATTRIBUTE, 'value' => 'title']),
                new PropertyMap(['name' => 'X', 'type' => Props::TYPE_STRING, 'kind' => PropertyMap::KIND_ATTRIBUTE, 'value' => 'slug']),
            ],
        ]);

        return !$dupe->validate() && $dupe->hasErrors('properties');
    });

    check('declared properties include the eleven built-ins plus the mappings', function() use ($plugin, $source) {
        $declared = $plugin->getSources()->declaredProperties();

        foreach (['title', 'url', 'imageUrl', 'enabled', 'postDate', 'expiryDate', 'headline', 'wordCount'] as $name) {
            if (!isset($declared['properties'][$name])) {
                return "missing $name";
            }
        }

        return $declared['conflicts'] === [] ?: 'unexpected conflicts: ' . Json::encode($declared['conflicts']);
    });

    check('two sources disagreeing about a property type is reported as a conflict', function() use ($plugin, $source, &$createdSourceUids) {
        $clash = new Source([
            'name' => 'Bee test clash',
            'elementType' => Entry::class,
            'properties' => [
                new PropertyMap(['name' => 'headline', 'type' => Props::TYPE_INT, 'kind' => PropertyMap::KIND_ATTRIBUTE, 'value' => 'id']),
            ],
        ]);

        $plugin->getSources()->save($clash);
        $createdSourceUids[] = $clash->uid;

        $conflicts = $plugin->getSources()->declaredProperties()['conflicts'];

        $plugin->getSources()->delete($clash->uid);
        $createdSourceUids = array_diff($createdSourceUids, [$clash->uid]);

        return isset($conflicts['headline']) ?: 'got ' . Json::encode($conflicts);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Catalog payloads');

    check('buildItem produces the built-ins plus every enabled mapping', function() use ($plugin, $entry, $source) {
        $values = $plugin->getCatalog()->buildItem($entry, $source);

        foreach (['title', 'itemType', 'siteId', 'sourceHandle', 'enabled', 'headline', 'computed'] as $key) {
            if (!array_key_exists($key, $values)) {
                return "missing $key from " . Json::encode(array_keys($values));
            }
        }

        return $values['title'] === (string)$entry->title
            && $values['itemType'] === 'entry'
            && $values['sourceHandle'] === 'news'
            && $values['computed'] === mb_strtoupper((string)$entry->title)
            ?: 'got ' . Json::encode($values);
    });

    check('nulls are stripped rather than sent', function() use ($plugin, $entry, $source) {
        $values = $plugin->getCatalog()->buildItem($entry, $source);

        foreach ($values as $key => $value) {
            if ($value === null) {
                return "$key is null";
            }
        }

        return true;
    });

    check('a broken mapping loses one property, not the whole item', function() use ($plugin, $entry, $source) {
        $broken = clone $source;
        $broken->properties = array_merge($source->properties, [
            new PropertyMap(['name' => 'oops', 'type' => Props::TYPE_STRING, 'kind' => PropertyMap::KIND_FIELD, 'value' => 'noSuchFieldHandle']),
        ]);

        $values = $plugin->getCatalog()->buildItem($entry, $broken);

        return !isset($values['oops']) && isset($values['title'], $values['headline'])
            ?: 'got ' . Json::encode(array_keys($values));
    });

    check('the content fingerprint is order-independent and change-sensitive', function() use ($plugin) {
        $catalog = $plugin->getCatalog();

        return $catalog->contentHash(['a' => 1, 'b' => 2]) === $catalog->contentHash(['b' => 2, 'a' => 1])
            && $catalog->contentHash(['a' => 1]) !== $catalog->contentHash(['a' => 2]);
    });

    check('the disabled half of a mapping is not sent', function() use ($plugin, $entry, $source) {
        $off = clone $source;
        $off->properties = [new PropertyMap([
            'name' => 'headline', 'type' => Props::TYPE_STRING,
            'kind' => PropertyMap::KIND_ATTRIBUTE, 'value' => 'title', 'enabled' => false,
        ])];

        return !isset($plugin->getCatalog()->buildItem($entry, $off)['headline']);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Catalog sync');

    check('a first sync posts Set Item Values with cascadeCreate and records the item', function() use ($plugin, $entry) {
        Craft::$app->getDb()->createCommand()->delete(Table::SYNC, ['elementId' => $entry->id])->execute();

        [$status, $history] = withMock([jsonResponse('ok')], fn() => $plugin->getCatalog()->syncElement($entry));

        $body = requestBody($history, 0);
        $record = SyncRecord::findOne(['elementId' => $entry->id, 'siteId' => $entry->siteId]);

        return $status === SyncRecord::STATUS_SYNCED
            && ($body['!cascadeCreate'] ?? null) === true
            && str_contains(requestUri($history, 0), '/items/' . Ids::forElement($entry))
            && $record?->status === SyncRecord::STATUS_SYNCED
            && $record->contentHash !== null
            ?: "status=$status body=" . Json::encode($body);
    });

    check('an unchanged element costs no API call at all', function() use ($plugin, $entry) {
        [$status, $history] = withMock([], fn() => $plugin->getCatalog()->syncElement($entry));

        return $status === 'unchanged' && $history === [] ?: "status=$status requests=" . count($history);
    });

    check('--force re-sends it anyway', function() use ($plugin, $entry) {
        [$status, $history] = withMock([jsonResponse('ok')], fn() => $plugin->getCatalog()->syncElement($entry, true));

        return $status === SyncRecord::STATUS_SYNCED && count($history) === 1 ?: "status=$status";
    });

    check('a failure is recorded against the row with Recombee’s own message', function() use ($plugin, $entry) {
        [$status] = withMock(
            [jsonResponse(['error' => 'Property headline does not exist'], 400)],
            fn() => $plugin->getCatalog()->syncElement($entry, true),
        );

        $record = SyncRecord::findOne(['elementId' => $entry->id, 'siteId' => $entry->siteId]);

        return $status === SyncRecord::STATUS_FAILED
            && str_contains((string)$record->error, 'does not exist')
            ?: "status=$status error=" . var_export($record->error ?? null, true);
    });

    check('an element that leaves the catalog is deleted from Recombee, not just forgotten', function() use ($plugin, $entry, $source) {
        // Put it back in a synced state first.
        withMock([jsonResponse('ok')], fn() => $plugin->getCatalog()->syncElement($entry, true));

        // Then narrow the source so nothing claims it.
        $narrowed = $plugin->getSources()->get($source->uid);
        $narrowed->groupUids = ['no-such-section-uid'];
        $plugin->getSources()->save($narrowed);

        [$status, $history] = withMock([jsonResponse('ok')], fn() => $plugin->getCatalog()->syncElement($entry));

        $narrowed->groupUids = [$entry->getSection()->uid];
        $plugin->getSources()->save($narrowed);

        return $status === SyncRecord::STATUS_DELETED
            && $history !== []
            && $history[0]['request']->getMethod() === 'DELETE'
            ?: "status=$status requests=" . count($history);
    });

    check('bulk sync batches, and reports each element’s outcome', function() use ($plugin, $entry, $settings) {
        $settings->batchSize = 50;
        $entries = Entry::find()->section('news')->status('live')->limit(3)->all();

        Craft::$app->getDb()->createCommand()
            ->delete(Table::SYNC, ['elementId' => array_map(static fn($e) => $e->id, $entries)])
            ->execute();

        [$tally, $history] = withMock([
            jsonResponse(array_fill(0, count($entries), ['code' => 200, 'json' => 'ok'])),
        ], fn() => $plugin->getCatalog()->syncElements($entries));

        $settings->batchSize = 500;

        return count($history) === 1
            && $tally['synced'] === count($entries)
            && count(requestBody($history, 0)['requests']) === count($entries)
            ?: 'tally=' . Json::encode($tally) . ' requests=' . count($history);
    });

    check('properties are created for anything Recombee does not have yet, and type changes are refused', function() use ($plugin) {
        [$result, $history] = withMock([
            jsonResponse([
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'headline', 'type' => 'int'],
            ]),
            jsonResponse('ok'), jsonResponse('ok'), jsonResponse('ok'), jsonResponse('ok'),
            jsonResponse('ok'), jsonResponse('ok'), jsonResponse('ok'), jsonResponse('ok'),
            jsonResponse('ok'), jsonResponse('ok'), jsonResponse('ok'), jsonResponse('ok'),
        ], fn() => $plugin->getCatalog()->syncProperties());

        // `headline` exists as an int here but Bee declares it a string, so it must be reported
        // rather than recreated — Recombee cannot recast without dropping the values.
        return isset($result['conflicts']['headline'])
            && !in_array('title', $result['created'], true)
            && in_array('url', $result['created'], true)
            ?: 'got ' . Json::encode($result);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Interactions');

    check('Lite sends detail views and purchases', function() use ($plugin, $entry) {
        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_LITE);

        [$sent, $history] = withMock([jsonResponse('ok')], fn() => $plugin->getInteractions()->detailView(
            $entry,
            ['userId' => 'utest', 'duration' => 12],
        ));

        return $sent && str_contains(requestUri($history, 0), '/detailviews/')
            && requestBody($history, 0)['duration'] === 12
            ?: 'sent=' . var_export($sent, true);
    });

    check('Lite does not send cart additions, bookmarks, ratings or view portions', function() use ($plugin, $entry) {
        $results = [];

        foreach (['cartAddition', 'bookmark'] as $method) {
            [$sent, $history] = withMock([], fn() => $plugin->getInteractions()->$method($entry, ['userId' => 'utest']));
            $results[$method] = $sent || $history !== [];
        }

        [$sent, $history] = withMock([], fn() => $plugin->getInteractions()->rating($entry, 0.5, ['userId' => 'utest']));
        $results['rating'] = $sent || $history !== [];

        return $results === ['cartAddition' => false, 'bookmark' => false, 'rating' => false]
            ?: 'got ' . Json::encode($results);
    });

    check('Pro sends the full interaction set', function() use ($plugin, $entry) {
        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_PRO);

        [$sent, $history] = withMock([jsonResponse('ok')], fn() => $plugin->getInteractions()->cartAddition(
            $entry,
            ['userId' => 'utest', 'amount' => 2, 'price' => 4.5],
        ));

        return $sent && str_contains(requestUri($history, 0), '/cartadditions/') ?: 'not sent';
    });

    check('the same interaction twice in one request is sent once', function() use ($plugin, $entry) {
        $ts = new DateTime('2024-01-01 00:00:00');

        [, $history] = withMock([jsonResponse('ok'), jsonResponse('ok')], function() use ($plugin, $entry, $ts) {
            $plugin->getInteractions()->detailView($entry, ['userId' => 'udupe', 'timestamp' => $ts]);
            $plugin->getInteractions()->detailView($entry, ['userId' => 'udupe', 'timestamp' => $ts]);
        });

        return count($history) === 1 ?: 'requests=' . count($history);
    });

    check('a 409 from Recombee counts as recorded, not as a failure', function() use ($plugin, $entry) {
        [$sent] = withMock([jsonResponse(['error' => 'duplicate'], 409)], fn() => $plugin->getInteractions()->detailView(
            $entry,
            ['userId' => 'u409', 'timestamp' => new DateTime('2024-02-02 02:02:02')],
        ));

        return $sent === true ?: 'got ' . var_export($sent, true);
    });

    check('a failing interaction never throws into the caller', function() use ($plugin, $entry) {
        [$sent] = withMock([new PsrResponse(500, [], 'down')], fn() => $plugin->getInteractions()->detailView(
            $entry,
            ['userId' => 'u500', 'timestamp' => new DateTime('2024-03-03 03:03:03')],
        ));

        return $sent === false;
    });

    check('an interaction naming an item Bee never synced is refused before it is sent', function() use ($plugin) {
        [$sent, $history] = withMock([], fn() => $plugin->getInteractions()->detailView('', ['userId' => 'u1']));

        return $sent === false && $history === [];
    });

    check('the before-record event can drop an interaction', function() use ($plugin, $entry) {
        $handler = static function(justinholtweb\bee\events\InteractionEvent $event): void {
            $event->isValid = false;
        };

        $plugin->getInteractions()->on(
            justinholtweb\bee\services\Interactions::EVENT_BEFORE_RECORD,
            $handler,
        );

        [$sent, $history] = withMock([], fn() => $plugin->getInteractions()->detailView($entry, [
            'userId' => 'uevent',
            'timestamp' => new DateTime('2024-04-04 04:04:04'),
        ]));

        $plugin->getInteractions()->off(justinholtweb\bee\services\Interactions::EVENT_BEFORE_RECORD, $handler);

        return $sent === false && $history === [];
    });

    check('recordMany batches and counts duplicates as sent', function() use ($plugin, $entry) {
        $interactions = [];

        foreach ([1, 2, 3] as $i) {
            $interactions[] = $plugin->getInteractions()->build(Interaction::PURCHASE, Ids::forElement($entry), [
                'userId' => "ubatch$i",
                'amount' => 1,
                'price' => 10,
                'timestamp' => new DateTime("2024-05-0$i 00:00:00"),
            ]);
        }

        [$tally, $history] = withMock([
            jsonResponse([['code' => 200, 'json' => 'ok'], ['code' => 409, 'json' => 'dup'], ['code' => 500, 'json' => 'boom']]),
        ], fn() => $plugin->getInteractions()->recordMany($interactions));

        return count($history) === 1 && $tally['sent'] === 2 && $tally['failed'] === 1
            ?: 'got ' . Json::encode($tally);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Recommendations');

    check('a response becomes a set of Craft elements in Recombee’s order', function() use ($plugin, $entry) {
        $entries = Entry::find()->section('news')->status('live')->limit(3)->all();
        $ids = array_map(static fn($e) => Ids::forElement($e), $entries);
        $shuffled = [$ids[2], $ids[0], $ids[1]];

        [$set] = withMock([
            jsonResponse(['recommId' => 'r-1', 'recomms' => array_map(static fn($id) => ['id' => $id], $shuffled)]),
        ], fn() => $plugin->getRecommendations()->toUser(['userId' => 'uorder', 'count' => 3]));

        $resolved = array_map(static fn($e) => Ids::forElement($e), $set->elements());

        return $resolved === $shuffled ?: 'got ' . Json::encode($resolved) . ' wanted ' . Json::encode($shuffled);
    });

    check('an item Recombee still knows about but Craft has deleted is dropped, not fatal', function() use ($plugin, $entry) {
        [$set] = withMock([
            jsonResponse(['recommId' => 'r-2', 'recomms' => [['id' => 'e999999999'], ['id' => Ids::forElement($entry)]]]),
        ], fn() => $plugin->getRecommendations()->toUser(['userId' => 'ughost', 'count' => 2]));

        return count($set->elements()) === 1 ?: 'got ' . count($set->elements());
    });

    check('the live filter is added to every request unless the caller opts out', function() use ($plugin) {
        [, $history] = withMock([jsonResponse(['recommId' => 'r', 'recomms' => []])],
            fn() => $plugin->getRecommendations()->toUser(['userId' => 'ufilter']));

        $with = requestBody($history, 0)['filter'] ?? '';

        [, $history2] = withMock([jsonResponse(['recommId' => 'r', 'recomms' => []])],
            fn() => $plugin->getRecommendations()->toUser(['userId' => 'ufilter', 'live' => false]));

        return str_contains($with, "'enabled' == true") && !isset(requestBody($history2, 0)['filter'])
            ?: "with=$with";
    });

    check('a caller’s own filter is combined with the live filter, not replaced by it', function() use ($plugin) {
        [, $history] = withMock([jsonResponse(['recommId' => 'r', 'recomms' => []])],
            fn() => $plugin->getRecommendations()->toUser(['userId' => 'u', 'filter' => "'price' > 10"]));

        $filter = requestBody($history, 0)['filter'];

        return str_contains($filter, "'enabled' == true") && str_contains($filter, "'price' > 10") && str_contains($filter, ' and ')
            ?: "got $filter";
    });

    check('related items post to the item endpoint and carry a target user', function() use ($plugin, $entry) {
        [, $history] = withMock([jsonResponse(['recommId' => 'r', 'recomms' => []])],
            fn() => $plugin->getRecommendations()->toItem($entry, ['userId' => 'urelated']));

        return str_contains(requestUri($history, 0), '/recomms/items/' . Ids::forElement($entry) . '/items/')
            && requestBody($history, 0)['targetUserId'] === 'urelated'
            ?: 'got ' . requestUri($history, 0);
    });

    check('search hits the search endpoint, carries the query, and never rotates results', function() use ($plugin) {
        [, $history] = withMock([jsonResponse(['recommId' => 'r', 'recomms' => []])],
            fn() => $plugin->getRecommendations()->search('winter boots', ['userId' => 'usearch']));

        $body = requestBody($history, 0);

        return str_contains(requestUri($history, 0), '/search/users/usearch/items/')
            && $body['searchQuery'] === 'winter boots'
            && !isset($body['rotationRate'])
            ?: 'got ' . Json::encode($body);
    });

    check('an empty search query never leaves the server', function() use ($plugin) {
        [$set, $history] = withMock([], fn() => $plugin->getRecommendations()->search('   ', ['userId' => 'u']));

        return $history === [] && $set->isEmpty();
    });

    check('a recommender outage returns an empty set rather than throwing into a template', function() use ($plugin) {
        [$set] = withMock([new PsrResponse(503, [], 'down')], fn() => $plugin->getRecommendations()->toUser(['userId' => 'udown']));

        return $set instanceof RecommendationSet && $set->failed && $set->isEmpty() && count($set) === 0;
    });

    check('the recommendation timeout is the short one, and recommendations are not retried', function() use ($plugin, $settings) {
        $settings->retries = 3;

        [, $history] = withMock([
            new PsrResponse(500, [], 'x'),
            jsonResponse(['recommId' => 'r', 'recomms' => []]),
        ], fn() => $plugin->getRecommendations()->toUser(['userId' => 'utimeout']));

        $settings->retries = 0;

        // One attempt only: a page render must not wait through a retry ladder.
        return count($history) === 1 ?: 'attempts=' . count($history);
    });

    check('the attribution ledger records what was handed out, and finds it again', function() use ($plugin, $entry) {
        Craft::$app->getDb()->createCommand()->delete(Table::RECOMMS)->execute();
        $itemId = Ids::forElement($entry);

        withMock([jsonResponse(['recommId' => 'r-ledger', 'recomms' => [['id' => $itemId]]])],
            fn() => $plugin->getRecommendations()->toUser(['userId' => 'uledger', 'scenario' => 'homepage']));

        $row = (new craft\db\Query())->from(Table::RECOMMS)->where(['recommId' => 'r-ledger'])->one();

        return $row !== null
            && $row['scenario'] === 'homepage'
            && $plugin->getRecommendations()->attributionFor('uledger', $itemId) === 'r-ledger'
            ?: 'row=' . Json::encode($row);
    });

    check('an interaction that follows a recommendation carries its recommId automatically', function() use ($plugin, $entry) {
        [, $history] = withMock([jsonResponse('ok')], fn() => $plugin->getInteractions()->detailView($entry, [
            'userId' => 'uledger',
            'timestamp' => new DateTime('2024-07-07 07:07:07'),
        ]));

        return (requestBody($history, 0)['recommId'] ?? null) === 'r-ledger'
            ?: 'got ' . Json::encode(requestBody($history, 0));
    });

    check('the ledger is not written for anonymous callers', function() use ($plugin, $entry) {
        $before = (new craft\db\Query())->from(Table::RECOMMS)->count();

        withMock([jsonResponse(['recommId' => 'r-anon', 'recomms' => [['id' => Ids::forElement($entry)]]])],
            fn() => $plugin->getRecommendations()->toItem($entry, ['userId' => 'anonymous']));

        return (int)(new craft\db\Query())->from(Table::RECOMMS)->count() === (int)$before;
    });

    check('Lite gets no search, no segments and no next page', function() use ($plugin) {
        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_LITE);

        [$search, $h1] = withMock([], fn() => $plugin->getRecommendations()->search('x', ['userId' => 'u']));
        [$segment, $h2] = withMock([], fn() => $plugin->getRecommendations()->toItemSegment('brand-1', ['userId' => 'u']));
        [$next, $h3] = withMock([], fn() => $plugin->getRecommendations()->next('r-1'));

        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_PRO);

        return $search->isEmpty() && $segment->isEmpty() && $next->isEmpty() && $h1 === [] && $h2 === [] && $h3 === [];
    });

    check('Lite still gets user and item recommendations', function() use ($plugin, $entry) {
        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_LITE);

        [$set, $history] = withMock([jsonResponse(['recommId' => 'r', 'recomms' => [['id' => Ids::forElement($entry)]]])],
            fn() => $plugin->getRecommendations()->toUser(['userId' => 'ulite']));

        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_PRO);

        return count($history) === 1 && count($set->elements()) === 1;
    });

    check('advanced ReQL knobs are Pro-only', function() use ($plugin) {
        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_LITE);

        [, $lite] = withMock([jsonResponse(['recommId' => 'r', 'recomms' => []])],
            fn() => $plugin->getRecommendations()->toUser(['userId' => 'u', 'booster' => "'price' * 2"]));

        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_PRO);

        [, $pro] = withMock([jsonResponse(['recommId' => 'r', 'recomms' => []])],
            fn() => $plugin->getRecommendations()->toUser(['userId' => 'u', 'booster' => "'price' * 2"]));

        return !isset(requestBody($lite, 0)['booster']) && isset(requestBody($pro, 0)['booster']);
    });

    check('a RecommendationSet hands templates attribution attributes', function() use ($entry) {
        $set = RecommendationSet::fromResponse(
            ['recommId' => 'r-attr', 'recomms' => [['id' => Ids::forElement($entry)]]],
            ['siteId' => $entry->siteId],
        );

        $attrs = $set->attributes($entry);

        return $attrs['data-bee-recomm-id'] === 'r-attr' && $attrs['data-bee-item-id'] === Ids::forElement($entry);
    });

    check('a bare string recommendation list parses as well as an object one', function() {
        $set = RecommendationSet::fromResponse(['recommId' => 'r', 'recomms' => ['e1', 'e2']]);

        return $set->ids() === ['e1', 'e2'];
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Identity and consent');

    check('consent gating with no cookie configured fails closed', function() use ($plugin, $settings) {
        $settings->consentMode = Settings::CONSENT_COOKIE;
        $settings->consentCookieName = '';
        $closed = $plugin->getIdentity()->hasConsent();
        $settings->consentMode = Settings::CONSENT_ALWAYS;
        $settings->consentCookieName = '';

        return $closed === false;
    });

    check('a merge names the target first and cascades the user into existence', function() use ($plugin) {
        [$merged, $history] = withMock([jsonResponse('ok')], fn() => $plugin->getIdentity()->merge('gabc', 'uxyz'));

        $uri = requestUri($history, 0);

        return $merged
            && str_contains($uri, '/users/uxyz/merge/gabc')
            && str_contains($uri, 'cascadeCreate=true')
            ?: "got $uri";
    });

    check('merging a user into themselves is a no-op', function() use ($plugin) {
        [$merged, $history] = withMock([], fn() => $plugin->getIdentity()->merge('u1', 'u1'));

        return $merged === false && $history === [];
    });

    check('nothing to merge is not an error', function() use ($plugin) {
        [$merged] = withMock([jsonResponse(['error' => 'no such user'], 404)],
            fn() => $plugin->getIdentity()->merge('gnope', 'uxyz'));

        return $merged === false;
    });

    check('a console request has no visitor, so nothing is tracked into a random profile', function() use ($plugin) {
        return $plugin->getIdentity()->currentUserId() === null;
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Log');

    check('“failures only” records failures and skips successes', function() use ($plugin, $settings) {
        Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
        $settings->logMode = Settings::LOG_ERRORS;

        withMock([jsonResponse('ok')], fn() => $plugin->getClient()->post('items/e1', []));

        $afterSuccess = (int)(new craft\db\Query())->from(Table::LOG)->count();

        try {
            withMock([jsonResponse(['error' => 'x'], 400)], fn() => $plugin->getClient()->post('items/e1', []));
        } catch (ApiException) {
        }

        $afterFailure = (int)(new craft\db\Query())->from(Table::LOG)->count();
        $settings->logMode = Settings::LOG_ALL;

        return $afterSuccess === 0 && $afterFailure === 1 ?: "success=$afterSuccess failure=$afterFailure";
    });

    check('“nothing” records nothing', function() use ($plugin, $settings) {
        Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
        $settings->logMode = Settings::LOG_NONE;

        try {
            withMock([jsonResponse(['error' => 'x'], 400)], fn() => $plugin->getClient()->post('items/e1', []));
        } catch (ApiException) {
        }

        $settings->logMode = Settings::LOG_ALL;

        return (int)(new craft\db\Query())->from(Table::LOG)->count() === 0;
    });

    check('stats come back as numbers, not PDO strings', function() use ($plugin) {
        $stats = $plugin->getLog()->stats();

        return is_int($stats['total']) && is_int($stats['failures']) ?: 'got ' . Json::encode($stats);
    });

    check('pruning drops old rows and leaves recent ones', function() use ($plugin) {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete(Table::LOG)->execute();

        $db->createCommand()->insert(Table::LOG, [
            'method' => 'GET', 'path' => 'old', 'status' => 200, 'durationMs' => 1, 'success' => true,
            'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime('-100 days')),
            'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-100 days')),
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();

        $plugin->getLog()->record('GET', 'new', 200, 1.0);

        $pruned = $plugin->getLog()->prune(30);

        return $pruned === 1 && (int)(new craft\db\Query())->from(Table::LOG)->count() === 1
            ?: "pruned=$pruned remaining=" . (new craft\db\Query())->from(Table::LOG)->count();
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Diagnostics');

    check('offline checks never make a request', function() use ($plugin) {
        [$checks, $history] = withMock([], fn() => $plugin->getDiagnostics()->run(false));

        return $history === [] && count($checks) > 5 ?: 'checks=' . count($checks);
    });

    check('every check carries a fix when it is not OK', function() use ($plugin) {
        [$checks] = withMock([], fn() => $plugin->getDiagnostics()->run(false));

        foreach ($checks as $c) {
            if ($c['status'] === justinholtweb\bee\services\Diagnostics::ERROR && $c['fix'] === null) {
                return 'no fix on ' . $c['key'];
            }
        }

        return true;
    });

    check('a missing credential is reported as an error', function() use ($plugin, $settings) {
        $settings->databaseId = '';
        [$checks] = withMock([], fn() => $plugin->getDiagnostics()->run(false));
        $settings->databaseId = 'bee-test-db';

        foreach ($checks as $c) {
            if ($c['key'] === 'credentials') {
                return $c['status'] === justinholtweb\bee\services\Diagnostics::ERROR ?: 'got ' . $c['status'];
            }
        }

        return 'no credentials check';
    });

    check('literal credentials are flagged as a warning, not an error', function() use ($plugin, $settings) {
        [$checks] = withMock([], fn() => $plugin->getDiagnostics()->run(false));

        foreach ($checks as $c) {
            if ($c['key'] === 'credentials') {
                return $c['status'] === justinholtweb\bee\services\Diagnostics::WARNING ?: 'got ' . $c['status'];
            }
        }

        return 'no credentials check';
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Structure');

    check('only services/Commerce.php imports a Commerce class', function() {
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'Commerce.php') {
                continue;
            }

            if (preg_match('/^use\s+craft\\\\commerce\\\\/m', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        // Fully-qualified inline references guarded by class_exists() are fine; a `use` statement is
        // what turns "Commerce is optional" into a fatal on a content-only site.
        return $offenders === [] ?: 'imports in: ' . implode(', ', $offenders);
    });

    check('every source file parses and the plugin declares both editions', function() use ($plugin) {
        return Plugin::editions() === ['lite', 'pro'];
    });

    check('the runtime ships as one dependency-free file', function() {
        $js = file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/runtime/dist/bee.js');

        return $js !== false
            && !str_contains($js, 'require(')
            && !str_contains($js, 'import ')
            && str_contains($js, 'window.Bee = Bee');
    });

    check('the runtime config is page-specific, never visitor-specific', function() use ($plugin) {
        $config = $plugin->runtimeConfig();

        // A CSRF token or a user ID in here would make every page carrying the runtime
        // uncacheable, which would be a strange price to pay for telemetry.
        $encoded = Json::encode($config);

        return array_keys($config) === ['endpoint', 'delay']
            && str_contains($config['endpoint'], '/bee/track')
            && !str_contains(strtolower($encoded), 'csrf')
            && !str_contains(strtolower($encoded), 'user')
            ?: 'got ' . $encoded;
    });

    check('the runtime asset bundle points at a file that exists', function() {
        $bundle = new justinholtweb\bee\web\assets\runtime\RuntimeAsset();

        return $bundle->js === ['bee.js'] && is_file($bundle->sourcePath . '/bee.js')
            ?: 'sourcePath=' . $bundle->sourcePath;
    });

    check('the runtime never names a user', function() {
        $js = file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/runtime/dist/bee.js');

        // The identity is resolved server-side. If the browser could name the user, the public
        // tracking endpoint would let anyone write into anyone's profile.
        return !str_contains($js, 'userId') ?: 'the runtime mentions userId';
    });

    check('every translatable string is listed in the translation file', function() {
        $translations = require dirname(__DIR__, 2) . '/src/translations/en/bee.php';
        $missing = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $file) {
            if (!in_array($file->getExtension(), ['php', 'twig'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (preg_match_all("/Craft::t\(\s*'bee'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $contents, $matches)) {
                foreach ($matches[1] as $string) {
                    $string = str_replace(["\\'", '\\\\'], ["'", '\\'], $string);

                    if (!isset($translations[$string])) {
                        $missing[] = $string;
                    }
                }
            }
        }

        return $missing === [] ?: count($missing) . ' missing, e.g. “' . $missing[0] . '”';
    });
} finally {
    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Cleanup');

    foreach ($createdSourceUids as $uid) {
        Plugin::getInstance()->getSources()->delete($uid);
    }

    Craft::$app->getProjectConfig()->flush();

    Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::RECOMMS)->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::SYNC)->execute();

    Craft::$app->getPlugins()->switchEdition('bee', $originalEdition);
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $originalSettings);
    Craft::$app->getProjectConfig()->flush();

    echo "  ✓ sources, rows, edition and settings restored\n";

    echo "\n";
    echo $failed === 0
        ? "\033[32mAll $passed checks passed.\033[0m\n"
        : "\033[31m$failed of " . ($passed + $failed) . " checks failed.\033[0m\n";

    exit($failed === 0 ? 0 : 1);
}
