<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\events\RecommendationEvent;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\helpers\Reql;
use justinholtweb\bee\models\RecommendationSet;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\RecommRecord;
use yii\base\Component;

/**
 * Asking Recombee for things, and turning the answer back into Craft.
 *
 * Two rules run through all of it:
 *
 *   1. **Never throw into a template.** A recommender being slow or down is not a reason for a
 *      product page to 500. Every public method returns an empty `RecommendationSet` marked
 *      `failed` instead, and the reason lands in the log.
 *   2. **Never trust the catalog to be current.** The catalog is push-based, so a "still live"
 *      ReQL filter is added to every request unless the caller opts out. Without it the first
 *      symptom of a sync problem is recommending something that was unpublished an hour ago.
 */
class Recommendations extends Component
{
    public const EVENT_AFTER_RECOMMEND = 'afterRecommend';

    /** recommId → item IDs handed out this request, so attribution works without a round-trip. */
    private array $inflight = [];

    // ─── Requests ────────────────────────────────────────────────────────────────────────────

    /**
     * Personalised recommendations for the current visitor.
     *
     * @param array $options count, scenario, userId, siteId, filter, booster, live, and the
     *                       Recombee passthroughs listed in `bodyFor()`
     */
    public function toUser(array $options = []): RecommendationSet
    {
        $userId = $options['userId'] ?? Plugin::getInstance()->getIdentity()->currentUserId();

        if (!is_string($userId) || $userId === '') {
            // No visitor to personalise for — a guest with tracking off, or consent not given.
            // An empty set is the honest answer; the template falls back to whatever it falls back to.
            return $this->emptySet('user', $options);
        }

        return $this->send(
            sprintf('recomms/users/%s/items/', rawurlencode($userId)),
            $options,
            ['kind' => 'user', 'userId' => $userId],
        );
    }

    /**
     * Related items — "more like this", "customers also bought".
     *
     * The visitor is passed as `targetUserId` when there is one, which is what turns a generic
     * similar-items list into a personalised one.
     */
    public function toItem(ElementInterface|string $item, array $options = []): RecommendationSet
    {
        $itemId = $item instanceof ElementInterface ? Ids::forElement($item) : $item;

        if ($itemId === null || $itemId === '') {
            return $this->emptySet('item', $options);
        }

        $userId = $options['userId'] ?? Plugin::getInstance()->getIdentity()->currentUserId(false);

        if (is_string($userId) && $userId !== '') {
            $options['targetUserId'] = $userId;
        }

        // Recombee requires a target user for this endpoint. Anonymous callers get a stable
        // throwaway ID rather than being denied a related-items list entirely.
        $options['targetUserId'] ??= 'anonymous';

        if ($item instanceof ElementInterface && !isset($options['siteId'])) {
            $options['siteId'] = (int)$item->siteId;
        }

        return $this->send(
            sprintf('recomms/items/%s/items/', rawurlencode($itemId)),
            $options,
            ['kind' => 'item', 'userId' => (string)$options['targetUserId'], 'sourceItemId' => $itemId],
        );
    }

    /**
     * Items most relevant to a segment of a context segmentation — "the best of this brand for
     * this shopper". Pro.
     */
    public function toItemSegment(string $segmentId, array $options = []): RecommendationSet
    {
        if (!Plugin::getInstance()->isPro()) {
            return $this->emptySet('segment', $options);
        }

        $userId = $options['targetUserId']
            ?? $options['userId']
            ?? Plugin::getInstance()->getIdentity()->currentUserId(false)
            ?? 'anonymous';

        $options['targetUserId'] = $userId;
        $options['contextSegmentId'] = $segmentId;

        return $this->send(
            'recomms/item-segments/items/',
            $options,
            ['kind' => 'segment', 'userId' => (string)$userId, 'sourceItemId' => $segmentId],
        );
    }

    /**
     * Recombee's own search, personalised to the visitor. Pro.
     */
    public function search(string $query, array $options = []): RecommendationSet
    {
        if (!Plugin::getInstance()->isPro()) {
            return $this->emptySet('search', $options);
        }

        $query = trim($query);

        if ($query === '') {
            return $this->emptySet('search', $options);
        }

        $userId = $options['userId']
            ?? Plugin::getInstance()->getIdentity()->currentUserId(false)
            ?? 'anonymous';

        $options['searchQuery'] = $query;
        $options['count'] ??= 20;

        return $this->send(
            sprintf('search/users/%s/items/', rawurlencode((string)$userId)),
            $options,
            ['kind' => 'search', 'userId' => (string)$userId],
        );
    }

    /**
     * The next page of an existing recommendation. Pro.
     *
     * Recombee keeps a recommId alive for thirty minutes, which is what makes this cheaper and more
     * coherent than asking for a bigger list and slicing it.
     */
    public function next(?string $recommId, ?int $count = null, ?int $siteId = null): RecommendationSet
    {
        if (!Plugin::getInstance()->isPro() || $recommId === null || $recommId === '') {
            return new RecommendationSet(['failed' => true, 'siteId' => $siteId]);
        }

        try {
            $response = Plugin::getInstance()->getClient()->post(
                'recomms/next/items/' . rawurlencode($recommId),
                ['count' => $count ?? Plugin::getInstance()->getSettings()->defaultCount],
                [],
                ['label' => 'next ' . $recommId, 'timeout' => Plugin::getInstance()->getSettings()->recommendTimeout],
            );
        } catch (\Throwable $e) {
            Craft::warning('Bee could not fetch the next page of recommendations: ' . $e->getMessage(), 'bee');

            return new RecommendationSet(['failed' => true, 'siteId' => $siteId]);
        }

        return RecommendationSet::fromResponse($response, ['siteId' => $siteId, 'kind' => 'user']);
    }

    // ─── Attribution ─────────────────────────────────────────────────────────────────────────

    /**
     * The recommId that put this item in front of this visitor, if Bee handed it to them recently.
     *
     * This is what closes the loop. Recombee can only report — and only *learn* from — a click or a
     * purchase if it knows which recommendation preceded it, and a Craft page is cached, reloaded
     * and redirected far too much for the recommId to survive in a request variable.
     */
    public function attributionFor(string $userId, string $itemId): ?string
    {
        if (!Plugin::getInstance()->isPro() || !Plugin::getInstance()->getSettings()->attribution) {
            return null;
        }

        if ($userId === '' || $itemId === '') {
            return null;
        }

        // Anything handed out during this same request has not been read back from the database yet.
        foreach (array_reverse($this->inflight, true) as $recommId => $ids) {
            if (in_array($itemId, $ids, true)) {
                return $recommId;
            }
        }

        $rows = (new Query())
            ->select(['recommId', 'itemIds'])
            ->from(Table::RECOMMS)
            ->where(['userId' => $userId])
            // Recombee's own recommId lifetime is thirty minutes; attributing beyond that is noise.
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb(new DateTime('-30 minutes'))])
            ->orderBy(['id' => SORT_DESC])
            ->limit(25)
            ->all();

        foreach ($rows as $row) {
            $ids = Json::decodeIfJson($row['itemIds']);

            if (is_array($ids) && in_array($itemId, $ids, true)) {
                return $row['recommId'];
            }
        }

        return null;
    }

    /**
     * Performance of the recommendations Bee handed out, by scenario.
     */
    public function report(int $days = 30): array
    {
        $since = Db::prepareDateForDb(new DateTime("-{$days} days"));

        return (new Query())
            ->select([
                'scenario' => 'COALESCE([[scenario]], \'(none)\')',
                'kind',
                'sets' => 'COUNT(*)',
                'users' => 'COUNT(DISTINCT [[userId]])',
            ])
            ->from(Table::RECOMMS)
            ->where(['>=', 'dateCreated', $since])
            ->groupBy(['scenario', 'kind'])
            ->orderBy(['sets' => SORT_DESC])
            ->all();
    }

    public function pruneLedger(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->attributionRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(Table::RECOMMS, ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$days} days"))])
            ->execute();
    }

    // ─── Element resolution ──────────────────────────────────────────────────────────────────

    /**
     * Turn Recombee item IDs into Craft elements, preserving Recombee's order.
     *
     * One query per element type, not one per item: a six-item related-products block that fired
     * six element queries would cost more than the recommendation call it decorates.
     *
     * @param string[] $itemIds
     * @return ElementInterface[]
     */
    public function resolveElements(array $itemIds, ?int $siteId = null): array
    {
        if ($itemIds === []) {
            return [];
        }

        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $byType = [];
        $order = [];

        foreach ($itemIds as $position => $itemId) {
            $parsed = Ids::parse($itemId);

            if ($parsed === null) {
                continue;
            }

            // A site-scoped item ID names its own site; an unscoped one belongs to whatever site
            // the caller is rendering.
            $itemSiteId = $parsed['siteId'] ?? $siteId;
            $byType[$parsed['type']][$itemSiteId][] = $parsed['id'];
            $order[$parsed['type'] . ':' . $itemSiteId . ':' . $parsed['id']] = $position;
        }

        $found = [];

        foreach ($byType as $class => $sites) {
            if (!class_exists($class)) {
                continue;
            }

            foreach ($sites as $querySiteId => $ids) {
                try {
                    /** @var string|ElementInterface $class */
                    $elements = $class::find()
                        ->id($ids)
                        ->siteId($querySiteId)
                        ->status(null)
                        ->limit(null)
                        ->all();
                } catch (\Throwable $e) {
                    Craft::warning('Bee could not resolve recommended elements: ' . $e->getMessage(), 'bee');
                    continue;
                }

                foreach ($elements as $element) {
                    $key = $class . ':' . $querySiteId . ':' . $element->id;

                    if (isset($order[$key])) {
                        $found[$order[$key]] = $element;
                    }
                }
            }
        }

        // Recombee's ranking is the product; re-sorting to Craft's default would throw it away.
        ksort($found);

        return array_values($found);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function send(string $path, array $options, array $context): RecommendationSet
    {
        $settings = Plugin::getInstance()->getSettings();
        $siteId = (int)($options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id);

        if (!$settings->isConfigured()) {
            return $this->emptySet($context['kind'] ?? 'user', $options);
        }

        try {
            $response = Plugin::getInstance()->getClient()->post(
                $path,
                $this->bodyFor($options, $siteId),
                [],
                [
                    'label' => ($context['kind'] ?? 'recomm') . ' ' . ($context['sourceItemId'] ?? $context['userId'] ?? ''),
                    // Recommendations render into a page. A slow recommender must degrade to no
                    // recommendations, never to a slow page.
                    'timeout' => $settings->recommendTimeout,
                    'retries' => 0,
                ],
            );
        } catch (\Throwable $e) {
            Craft::warning('Bee could not fetch recommendations: ' . $e->getMessage(), 'bee');

            return $this->emptySet($context['kind'] ?? 'user', $options);
        }

        $set = RecommendationSet::fromResponse($response, [
            'siteId' => $siteId,
            'scenario' => $options['scenario'] ?? null,
        ] + $context);

        $this->ledger($set, $context);

        $event = new RecommendationEvent(['set' => $set, 'params' => $options]);
        $this->trigger(self::EVENT_AFTER_RECOMMEND, $event);

        return $event->set;
    }

    /**
     * Build the Recombee request body.
     *
     * Only keys Recombee knows are forwarded. A typo'd option silently ignored is better than a
     * 400 that takes a product page's recommendations away, and the unknown key is still visible in
     * the log.
     */
    private function bodyFor(array $options, int $siteId): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $pro = Plugin::getInstance()->isPro();

        $body = [
            'count' => max(1, (int)($options['count'] ?? $settings->defaultCount)),
            'cascadeCreate' => true,
        ];

        foreach (['scenario', 'searchQuery', 'targetUserId', 'contextSegmentId', 'returnProperties', 'includedProperties'] as $key) {
            if (isset($options[$key])) {
                $body[$key] = $options[$key];
            }
        }

        // The advanced ReQL surface is where Pro earns its keep: custom logic, boosters, diversity
        // and expert settings are the knobs a merchandiser reaches for once the basics work.
        if ($pro) {
            foreach (['booster', 'logic', 'diversity', 'expertSettings', 'reqlExpressions', 'returnAbGroup'] as $key) {
                if (isset($options[$key])) {
                    $body[$key] = $options[$key];
                }
            }
        }

        $filters = [];

        if (($options['live'] ?? $settings->filterLive) !== false) {
            // Site scoping only makes sense when item IDs are site-scoped; otherwise one catalog
            // item legitimately serves every site.
            $filters[] = Reql::liveOnly(Ids::siteScoped() ? $siteId : null);
        }

        if (isset($options['filter']) && is_string($options['filter']) && trim($options['filter']) !== '') {
            $filters[] = trim($options['filter']);
        }

        if (($filter = Reql::all($filters)) !== null) {
            $body['filter'] = $filter;
        }

        if ($settings->minRelevance !== '') {
            $body['minRelevance'] = $settings->minRelevance;
        }

        if ($settings->rotationRate > 0) {
            $body['rotationRate'] = $settings->rotationRate;
            $body['rotationTime'] = $settings->rotationTime;
        }

        foreach (['minRelevance', 'rotationRate', 'rotationTime'] as $key) {
            if (isset($options[$key])) {
                $body[$key] = $options[$key];
            }
        }

        // Search has no rotation: a search result that shuffles between page loads reads as broken.
        if (isset($body['searchQuery'])) {
            unset($body['rotationRate'], $body['rotationTime']);
        }

        return $body;
    }

    /**
     * Write the handed-out recommendation to the attribution ledger.
     */
    private function ledger(RecommendationSet $set, array $context): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!Plugin::getInstance()->isPro() || !$settings->attribution) {
            return;
        }

        if ($set->recommId === null || $set->recommId === '' || $set->isEmpty()) {
            return;
        }

        $userId = (string)($context['userId'] ?? '');

        if ($userId === '' || $userId === 'anonymous') {
            return;
        }

        $this->inflight[$set->recommId] = $set->ids();

        try {
            $record = new RecommRecord();
            $record->recommId = $set->recommId;
            $record->userId = $userId;
            $record->scenario = $set->scenario;
            $record->kind = $set->kind;
            $record->sourceItemId = $context['sourceItemId'] ?? null;
            $record->itemIds = Json::encode($set->ids());
            $record->abGroup = $set->abGroup;
            $record->save(false);
        } catch (\Throwable $e) {
            // The in-request memo above still covers the common case, and a ledger write is never
            // worth failing a page render for.
            Craft::warning('Bee could not write to the attribution ledger: ' . $e->getMessage(), 'bee');
        }
    }

    private function emptySet(string $kind, array $options): RecommendationSet
    {
        return new RecommendationSet([
            'kind' => $kind,
            'failed' => true,
            'scenario' => $options['scenario'] ?? null,
            'siteId' => isset($options['siteId']) ? (int)$options['siteId'] : null,
        ]);
    }
}
