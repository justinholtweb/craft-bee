<?php

namespace justinholtweb\bee\gql\resolvers;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\gql\queries\Bee;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\models\RecommendationSet;
use justinholtweb\bee\models\Source;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\SyncRecord;

/**
 * Resolvers for Bee's GraphQL queries.
 *
 * Thin on purpose: every request goes through {@see \justinholtweb\bee\services\Recommendations},
 * so the live filter, the timeout, the ledger and `EVENT_AFTER_RECOMMEND` behave exactly as they
 * do for `craft.bee.*`. What this layer adds is the GraphQL side of trust:
 *
 *   - the options are built from a fixed list, so nothing a caller sends can become a `userId` or a
 *     ReQL expression;
 *   - the site, the source element and every returned element are checked against the schema's
 *     own read scopes, the same ones Craft applies to `entries` or `products` — a token that cannot
 *     query the Staff section does not get Staff entries back by asking Bee for them;
 *   - nothing throws. A recommender outage turns into `failed: true`, never into a GraphQL error
 *     that takes the rest of the query down with it.
 */
class Recommendations
{
    public static function recommend(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        return self::guard('user', $arguments, static function(array $options) {
            return Plugin::getInstance()->getRecommendations()->toUser($options);
        });
    }

    public static function related(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        return self::guard('item', $arguments, static function(array $options) use ($arguments) {
            $element = self::sourceElement($arguments, (int)$options['siteId']);

            if ($element === null) {
                return null;
            }

            return Plugin::getInstance()->getRecommendations()->toItem($element, $options);
        });
    }

    public static function search(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        return self::guard('search', $arguments, static function(array $options) use ($arguments) {
            $query = trim((string)($arguments['query'] ?? ''));

            // A search box submitted empty is not worth a metered request.
            if ($query === '' || mb_strlen($query) > 255) {
                return null;
            }

            return Plugin::getInstance()->getRecommendations()->search($query, $options);
        });
    }

    /**
     * Whether a GraphQL document asks for anything of Bee's.
     *
     * Craft caches GraphQL results keyed on the schema, the query and its variables — not on the
     * visitor. A personalised answer cached under that key would be served to the next visitor who
     * sends the same query, so {@see \justinholtweb\bee\Plugin} switches caching off for any document
     * this matches. A field name cannot be hidden from the document text (an alias still names the
     * field), and a false positive only costs one uncached response.
     */
    public static function isBeeQuery(string $query): bool
    {
        return (bool)preg_match('/\bbee(?:Recommend|Related|Search)\b/', $query);
    }

    /**
     * Whether the active schema may read this element — the same scopes Craft's own element
     * queries are limited to.
     *
     * @param array<string, string[]> $pairs from `GqlHelper::extractAllowedEntitiesFromSchema('read')`
     */
    public static function readable(ElementInterface $element, array $pairs): bool
    {
        if ($element instanceof Entry) {
            // A nested entry has no section; it is reachable only through its owner's field.
            $section = $element->getSection();

            return $section !== null && in_array($section->uid, $pairs['sections'] ?? [], true);
        }

        if ($element instanceof Category) {
            return in_array($element->getGroup()->uid, $pairs['categorygroups'] ?? [], true);
        }

        if ($element instanceof Asset) {
            $volume = $element->getVolume();

            return in_array($volume->uid, $pairs['volumes'] ?? [], true);
        }

        if ($element instanceof User) {
            $allowed = $pairs['usergroups'] ?? [];

            if (in_array('everyone', $allowed, true)) {
                return true;
            }

            foreach ($element->getGroups() as $group) {
                if (in_array($group->uid, $allowed, true)) {
                    return true;
                }
            }

            return false;
        }

        // Commerce is optional, so its classes are named rather than imported. `instanceof` against
        // a class that is not installed is simply false.
        if ($element instanceof \craft\commerce\elements\Variant) {
            $product = $element->getOwner();

            return $product !== null && self::readable($product, $pairs);
        }

        if ($element instanceof \craft\commerce\elements\Product) {
            return in_array($element->getType()->uid, $pairs['productTypes'] ?? [], true);
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Build the options from the allowed arguments, run the request, and shape the answer.
     *
     * @param callable(array): ?RecommendationSet $request
     */
    private static function guard(string $kind, array $arguments, callable $request): array
    {
        $scenario = isset($arguments['scenario']) && is_string($arguments['scenario']) && trim($arguments['scenario']) !== ''
            ? trim($arguments['scenario'])
            : null;

        try {
            $plugin = Plugin::getInstance();

            if (!$plugin->isPro() || !$plugin->getSettings()->isConfigured()) {
                return self::empty($kind, $scenario);
            }

            $siteId = self::siteId($arguments);

            if ($siteId === null) {
                return self::empty($kind, $scenario);
            }

            // The whole list. `userId`, `filter`, `booster` and the rest of the template surface are
            // not here, and nothing below reads `$arguments` for them.
            $options = [
                'count' => min(Bee::MAX_COUNT, max(1, (int)($arguments['count'] ?? $plugin->getSettings()->defaultCount))),
                'siteId' => $siteId,
            ];

            if ($scenario !== null) {
                $options['scenario'] = $scenario;
            }

            $set = $request($options);

            if (!$set instanceof RecommendationSet) {
                return self::empty($kind, $scenario);
            }

            return self::payload($set);
        } catch (\Throwable $e) {
            Craft::warning('Bee could not answer a GraphQL recommendation query: ' . $e->getMessage(), 'bee');

            return self::empty($kind, $scenario);
        }
    }

    /**
     * The site the elements are resolved in — the named one, or the current one — provided the
     * schema can read it.
     */
    private static function siteId(array $arguments): ?int
    {
        $sites = Craft::$app->getSites();
        $handle = $arguments['site'] ?? null;
        $site = is_string($handle) && $handle !== '' ? $sites->getSiteByHandle($handle) : $sites->getCurrentSite();

        if ($site === null) {
            return null;
        }

        $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read');

        return in_array($site->uid, $pairs['sites'] ?? [], true) ? (int)$site->id : null;
    }

    /**
     * The element `beeRelated` was asked about, or null if the caller may not use it.
     *
     * It has to be live, readable by the schema, and already in Bee's catalog. The last one is not
     * tidiness: Recombee's item endpoints create the item when it does not exist, so an unchecked ID
     * would let a caller mint empty items in the merchant's database — the same reason the public
     * tracking endpoint checks the sync table.
     */
    private static function sourceElement(array $arguments, int $siteId): ?ElementInterface
    {
        $elementId = $arguments['elementId'] ?? null;
        $itemId = $arguments['itemId'] ?? null;
        $elementType = null;

        if (is_string($itemId) && $itemId !== '') {
            $parsed = Ids::parse($itemId);

            if ($parsed === null) {
                return null;
            }

            $elementType = $parsed['type'];
            $elementId = $parsed['id'];
            $siteId = $parsed['siteId'] ?? $siteId;

            // A site-scoped ID names its own site; that site has to be readable as well.
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read');

            if ($site === null || !in_array($site->uid, $pairs['sites'] ?? [], true)) {
                return null;
            }
        }

        if (!is_int($elementId) || $elementId <= 0) {
            return null;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, $elementType, $siteId);

        if ($element === null || !Source::isLive($element)) {
            return null;
        }

        if (!self::readable($element, GqlHelper::extractAllowedEntitiesFromSchema('read'))) {
            return null;
        }

        $canonical = Ids::forElement($element);

        if ($canonical === null || (is_string($itemId) && $itemId !== '' && $canonical !== $itemId)) {
            return null;
        }

        $synced = (new Query())
            ->from(Table::SYNC)
            ->where(['itemId' => $canonical, 'status' => SyncRecord::STATUS_SYNCED])
            ->exists();

        return $synced ? $element : null;
    }

    /**
     * The set as the `BeeRecommendations` type reads it, keeping only what the schema may see.
     *
     * `itemIds` is rebuilt from the kept elements rather than copied from Recombee's answer, so a
     * hidden item's ID does not leak out through the list that sits beside it.
     */
    private static function payload(RecommendationSet $set): array
    {
        $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read');
        $elements = [];
        $itemIds = [];

        foreach ($set->elements() as $element) {
            $itemId = Ids::forElement($element);

            if ($itemId === null || !self::readable($element, $pairs)) {
                continue;
            }

            $elements[] = $element;
            $itemIds[] = $itemId;
        }

        return [
            'recommId' => $set->recommId,
            'scenario' => $set->scenario,
            'kind' => $set->kind,
            'abGroup' => $set->abGroup,
            'failed' => $set->failed,
            'itemIds' => $itemIds,
            'elements' => $elements,
        ];
    }

    private static function empty(string $kind, ?string $scenario): array
    {
        return [
            'recommId' => null,
            'scenario' => $scenario,
            'kind' => $kind,
            'abGroup' => null,
            'failed' => true,
            'itemIds' => [],
            'elements' => [],
        ];
    }
}
