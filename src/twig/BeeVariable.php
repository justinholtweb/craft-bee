<?php

namespace justinholtweb\bee\twig;

use craft\base\ElementInterface;
use craft\helpers\Html;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\models\RecommendationSet;
use justinholtweb\bee\Plugin;
use Twig\Markup;

/**
 * `craft.bee` — everything a template needs.
 *
 * Nothing here throws. A recommender that can take a page down with it is worse than no recommender,
 * so every method degrades to an empty set, a false, or a null.
 */
class BeeVariable
{
    /**
     * Personalised recommendations for the visitor.
     *
     *     {% for product in craft.bee.recommend({ count: 8, scenario: 'homepage' }) %}
     */
    public function recommend(array $options = []): RecommendationSet
    {
        return Plugin::getInstance()->getRecommendations()->toUser($options);
    }

    /**
     * Items related to this one — "more like this", "customers also bought".
     *
     *     {% for product in craft.bee.related(product, { count: 4, scenario: 'product-detail' }) %}
     */
    public function related(ElementInterface|string $item, array $options = []): RecommendationSet
    {
        return Plugin::getInstance()->getRecommendations()->toItem($item, $options);
    }

    /**
     * The best items in a segment of a context segmentation. Pro.
     */
    public function segment(string $segmentId, array $options = []): RecommendationSet
    {
        return Plugin::getInstance()->getRecommendations()->toItemSegment($segmentId, $options);
    }

    /**
     * Recombee's search, personalised to the visitor. Pro.
     *
     *     {% set results = craft.bee.search(craft.app.request.getParam('q'), { count: 24 }) %}
     */
    public function search(?string $query, array $options = []): RecommendationSet
    {
        return Plugin::getInstance()->getRecommendations()->search((string)$query, $options);
    }

    // ─── Tracking ────────────────────────────────────────────────────────────────────────────

    /**
     * Record a detail view server-side.
     *
     * Only needed when the front-end runtime is switched off, or on a page whose "item" is not the
     * one the runtime would infer. Note that this makes the response visitor-specific, so do not
     * call it from a statically cached template.
     */
    public function trackView(ElementInterface|string $item, array $options = []): bool
    {
        return Plugin::getInstance()->getInteractions()->detailView($item, $options);
    }

    public function trackCartAdd(ElementInterface|string $item, array $options = []): bool
    {
        return Plugin::getInstance()->getInteractions()->cartAddition($item, $options);
    }

    public function trackPurchase(ElementInterface|string $item, array $options = []): bool
    {
        return Plugin::getInstance()->getInteractions()->purchase($item, $options);
    }

    public function trackBookmark(ElementInterface|string $item, array $options = []): bool
    {
        return Plugin::getInstance()->getInteractions()->bookmark($item, $options);
    }

    /**
     * A rating on Recombee's scale, which runs from -1.0 to 1.0 rather than 1 to 5.
     * Pass `stars` instead to have a 1–5 rating converted.
     */
    public function trackRating(ElementInterface|string $item, float $rating, array $options = []): bool
    {
        return Plugin::getInstance()->getInteractions()->rating($item, $rating, $options);
    }

    /**
     * Convert a 1–N star rating to Recombee's -1…1 scale.
     */
    public function stars(float $stars, float $outOf = 5.0): float
    {
        if ($outOf <= 1) {
            return 0.0;
        }

        return round(((($stars - 1) / ($outOf - 1)) * 2) - 1, 4);
    }

    public function trackViewPortion(ElementInterface|string $item, float $portion, array $options = []): bool
    {
        return Plugin::getInstance()->getInteractions()->viewPortion($item, $portion, $options);
    }

    // ─── Markup helpers ──────────────────────────────────────────────────────────────────────

    /**
     * Attribution attributes for a recommended item.
     *
     *     <a href="{{ p.url }}" {{ craft.bee.attribution(recs, p) }}>
     *
     * The runtime reads these on click and sends the `recommId` with the resulting detail view,
     * which is the only way Recombee can score its own suggestions.
     */
    public function attribution(RecommendationSet $set, ElementInterface|string $item): Markup
    {
        return new Markup(Html::renderTagAttributes($set->attributes($item)), 'UTF-8');
    }

    /**
     * The attributes that tell the runtime what this page is about, when it cannot infer it.
     *
     *     <body {{ craft.bee.pageItem(entry) }}>
     */
    public function pageItem(ElementInterface|string $item): Markup
    {
        $itemId = $item instanceof ElementInterface ? Ids::forElement($item) : $item;

        return new Markup(
            $itemId !== null ? Html::renderTagAttributes(['data-bee-page-item' => $itemId]) : '',
            'UTF-8',
        );
    }

    /**
     * The Recombee item ID for an element, for templates that talk to the API themselves.
     */
    public function itemId(ElementInterface $element): ?string
    {
        return Ids::forElement($element);
    }

    // ─── State ───────────────────────────────────────────────────────────────────────────────

    public function getUserId(): ?string
    {
        return Plugin::getInstance()->getIdentity()->currentUserId(false);
    }

    public function getIsConfigured(): bool
    {
        return Plugin::getInstance()->getSettings()->isConfigured();
    }

    public function getIsPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }

    public function getHasConsent(): bool
    {
        return Plugin::getInstance()->getIdentity()->hasConsent();
    }
}
