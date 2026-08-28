<?php

namespace justinholtweb\bee\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\models\Interaction;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\SyncRecord;
use yii\web\Response;

/**
 * The public endpoint the front-end runtime posts to.
 *
 * This is the only part of Bee a stranger can reach, so it is written defensively:
 *
 *   - **The user is never taken from the request.** It is resolved from the session or the signed
 *     cookie. A caller who could name the user could write into someone else's profile, or read a
 *     stranger's recommendations back out of it.
 *   - **The item must already be in the catalog.** Otherwise `cascadeCreate` would let anyone mint
 *     propertyless ghost items in the merchant's Recombee database, forever.
 *   - **Rate limited per client.** Interactions are cheap to send and metered by Recombee.
 *   - **CSRF is off, deliberately.** A beacon cannot carry a token, and a token in the page would
 *     make every page uncacheable. The blast radius of a forged request is "a visitor's own profile
 *     records a view they didn't make", which is not worth breaking static caching for.
 */
class TrackController extends Controller
{
    /**
     * Craft 5 wants the *bitmask* here, not a boolean. `['record' => true]` throws
     * "Invalid $allowAnonymous value" from the controller constructor — which surfaces as a 500 on
     * the tracking endpoint and nowhere else, because nothing else instantiates this controller.
     */
    public array|int|bool $allowAnonymous = [
        'record' => self::ALLOW_ANONYMOUS_LIVE,
        'recommend' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    public $enableCsrfValidation = false;

    private const RATE_LIMIT = 120;

    public function actionRecord(): Response
    {
        $this->requirePostRequest();

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->trackingEnabled || !$settings->isConfigured()) {
            return $this->asJson(['ok' => false, 'reason' => 'disabled']);
        }

        if (!$this->rateLimit()) {
            Craft::$app->getResponse()->setStatusCode(429);

            return $this->asJson(['ok' => false, 'reason' => 'rate-limited']);
        }

        $payload = $this->payload();
        $kind = (string)($payload['kind'] ?? '');
        $itemId = (string)($payload['itemId'] ?? '');

        if (!in_array($kind, Interaction::KINDS, true)) {
            return $this->asJson(['ok' => false, 'reason' => 'unknown-kind']);
        }

        if (!$this->itemExists($itemId)) {
            return $this->asJson(['ok' => false, 'reason' => 'unknown-item']);
        }

        $userId = Plugin::getInstance()->getIdentity()->currentUserId();

        if ($userId === null) {
            // Tracking is on but this visitor is not trackable — no consent, or guests excluded.
            return $this->asJson(['ok' => false, 'reason' => 'no-identity']);
        }

        $options = ['userId' => $userId];

        foreach (['duration', 'amount', 'price', 'rating', 'portion', 'recommId'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== null) {
                $options[$key] = is_numeric($payload[$key]) ? $payload[$key] + 0 : (string)$payload[$key];
            }
        }

        $interaction = Plugin::getInstance()->getInteractions()->build($kind, $itemId, $options);
        $sent = Plugin::getInstance()->getInteractions()->record($interaction);

        return $this->asJson(['ok' => $sent]);
    }

    /**
     * Recommendations as JSON, for sites that render their carousels client-side. Pro.
     *
     * Returns rendered element data rather than raw Recombee IDs, because the only thing a browser
     * can do with `p1234-s2` is ask for another round trip.
     */
    public function actionRecommend(): Response
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!Plugin::getInstance()->isPro() || !$settings->isConfigured()) {
            return $this->asJson(['ok' => false, 'items' => []]);
        }

        if (!$this->rateLimit()) {
            Craft::$app->getResponse()->setStatusCode(429);

            return $this->asJson(['ok' => false, 'items' => []]);
        }

        $payload = $this->payload();
        $request = Craft::$app->getRequest();

        $options = [
            'count' => min(100, max(1, (int)($payload['count'] ?? $request->getParam('count') ?? $settings->defaultCount))),
        ];

        $scenario = $payload['scenario'] ?? $request->getParam('scenario');

        if (is_string($scenario) && $scenario !== '') {
            $options['scenario'] = $scenario;
        }

        // A `filter` from the browser is *not* accepted. ReQL runs server-side inside Recombee, so
        // an attacker-supplied filter is a way to enumerate the catalog past whatever the site
        // intends to show.
        $itemId = $payload['itemId'] ?? $request->getParam('itemId');
        $recommendations = Plugin::getInstance()->getRecommendations();

        $set = is_string($itemId) && $itemId !== '' && $this->itemExists($itemId)
            ? $recommendations->toItem($itemId, $options)
            : $recommendations->toUser($options);

        $items = [];

        foreach ($set->elements() as $element) {
            $items[] = [
                'id' => \justinholtweb\bee\helpers\Ids::forElement($element),
                'title' => (string)$element->title,
                'url' => $element->getUrl(),
            ];
        }

        return $this->asJson([
            'ok' => !$set->failed,
            'recommId' => $set->recommId,
            'items' => $items,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The request body, whether it arrived as JSON (fetch) or as a blob (sendBeacon).
     *
     * `getBodyParams()` does not decode a `sendBeacon` blob — the browser sends it with the Blob's
     * own content type and Yii's JSON parser is only wired to the exact `application/json` type, so
     * a beacon would otherwise arrive as an empty parameter list and every unload event would be
     * silently dropped.
     */
    private function payload(): array
    {
        $request = Craft::$app->getRequest();
        $params = $request->getBodyParams();

        if ($params !== []) {
            return $params;
        }

        $raw = $request->getRawBody();

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = Json::decode($raw);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether this item ID is one Bee actually put in the catalog.
     */
    private function itemExists(string $itemId): bool
    {
        if ($itemId === '' || strlen($itemId) > 255) {
            return false;
        }

        return (new Query())
            ->from(Table::SYNC)
            ->where(['itemId' => $itemId, 'status' => SyncRecord::STATUS_SYNCED])
            ->exists();
    }

    private function rateLimit(): bool
    {
        $request = Craft::$app->getRequest();
        $key = 'bee:rate:' . sha1((string)$request->getUserIP() . '|' . (string)$request->getUserAgent());
        $cache = Craft::$app->getCache();
        $count = (int)$cache->get($key);

        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        $cache->set($key, $count + 1, 60);

        return true;
    }
}
