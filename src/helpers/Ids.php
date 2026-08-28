<?php

namespace justinholtweb\bee\helpers;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User as UserElement;
use justinholtweb\bee\Plugin;

/**
 * Item and user IDs.
 *
 * Recombee has one flat catalog keyed by string IDs, and Craft has elements that live in sites.
 * Every ID Bee ever sends is minted here, so the console backfill, the save handler and the
 * front-end runtime cannot disagree about what an item is called — a disagreement that would
 * quietly split one product's interaction history across two Recombee items.
 */
abstract class Ids
{
    /**
     * Short, stable prefixes. These are part of the wire format: changing one orphans every item
     * already in the customer's Recombee database, so they are frozen.
     */
    public const PREFIXES = [
        'craft\\elements\\Entry' => 'e',
        'craft\\elements\\Category' => 'c',
        'craft\\elements\\Asset' => 'a',
        'craft\\elements\\User' => 'u',
        'craft\\commerce\\elements\\Product' => 'p',
        'craft\\commerce\\elements\\Variant' => 'v',
    ];

    /**
     * The Recombee item ID for an element, or null if Bee does not know how to name it.
     *
     * When more than one site is being synced the site ID is part of the item ID: the same entry
     * in two languages is two catalog items with different titles, and merging them would make the
     * recommender fight itself.
     */
    public static function forElement(ElementInterface $element): ?string
    {
        $prefix = self::prefixFor($element);

        if ($prefix === null) {
            return null;
        }

        $id = $prefix . $element->id;

        if (self::siteScoped()) {
            $id .= '-s' . $element->siteId;
        }

        return $id;
    }

    /**
     * The inverse: turn an item ID back into [elementType, elementId, siteId].
     *
     * Recommendations come back as bare IDs, so this is what lets Bee hand a template real Craft
     * elements instead of strings.
     */
    public static function parse(string $itemId): ?array
    {
        if (!preg_match('/^([a-z]+)(\d+)(?:-s(\d+))?$/', $itemId, $m)) {
            return null;
        }

        $type = array_search($m[1], self::PREFIXES, true);

        if ($type === false) {
            return null;
        }

        return [
            'type' => $type,
            'id' => (int)$m[2],
            'siteId' => isset($m[3]) ? (int)$m[3] : null,
        ];
    }

    /**
     * The Recombee user ID for a signed-in Craft user.
     *
     * Deliberately the user's UID and not their integer ID: user IDs are guessable, and this string
     * travels to a third party and back into page markup.
     */
    public static function forUser(UserElement $user): string
    {
        return 'u' . $user->uid;
    }

    /**
     * The Recombee user ID for an anonymous visitor, given the token Bee minted for them.
     */
    public static function forGuest(string $token): string
    {
        return 'g' . $token;
    }

    public static function isGuest(string $userId): bool
    {
        return str_starts_with($userId, 'g');
    }

    public static function prefixFor(ElementInterface $element): ?string
    {
        foreach (self::PREFIXES as $class => $prefix) {
            if ($element instanceof $class) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Whether item IDs carry a site ID. True as soon as more than one site is being synced.
     */
    public static function siteScoped(): bool
    {
        return count(Plugin::getInstance()->getSettings()->syncedSiteIds()) > 1;
    }

    /**
     * The element classes Bee can sync, in the order they are offered in the CP.
     */
    public static function supportedTypes(): array
    {
        $types = [Entry::class, Category::class, Asset::class];

        if (Plugin::commerceIsReady()) {
            $types[] = 'craft\\commerce\\elements\\Product';
            $types[] = 'craft\\commerce\\elements\\Variant';
        }

        return $types;
    }
}
