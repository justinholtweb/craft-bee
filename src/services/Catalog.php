<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\helpers\Props;
use justinholtweb\bee\models\Source;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\SyncRecord;
use yii\base\Component;

/**
 * The catalog: turning Craft elements into Recombee items and keeping the two in step.
 *
 * `buildItem()` is the single place an element becomes a payload. The element-save handler, the
 * queue job, the console backfill and the CP "Preview payload" button all call it, so what a
 * merchant sees in the preview is byte-for-byte what Recombee receives. Every previous version of
 * this that had two builders eventually had two behaviours.
 */
class Catalog extends Component
{
    /** @var array<string, array<string, string>>|null Memoised remote property list. */
    private ?array $remoteProperties = null;

    // ─── Building ────────────────────────────────────────────────────────────────────────────

    /**
     * The Recombee property values for an element.
     *
     * Built-ins first, then the source's mappings. Built-ins are not overridable: the live-content
     * filter applied to every recommendation depends on `enabled`, `postDate` and `expiryDate`
     * meaning what Bee thinks they mean.
     *
     * @return array<string, mixed>
     */
    public function buildItem(ElementInterface $element, Source $source): array
    {
        $values = [
            'title' => Props::coerce($element->title ?? (string)$element, Props::TYPE_STRING),
            'url' => Props::coerce($this->urlFor($element), Props::TYPE_STRING),
            'imageUrl' => Props::coerce($this->special('image', $element), Props::TYPE_IMAGE),
            'itemType' => $this->shortTypeName($element),
            'siteId' => (int)$element->siteId,
            'sourceHandle' => Props::coerce($this->handleFor($element), Props::TYPE_STRING),
            'slug' => Props::coerce($element->slug ?? null, Props::TYPE_STRING),
            'enabled' => $element->getStatus() === Element::STATUS_ENABLED || $element->getStatus() === 'live',
            'postDate' => Props::coerce($element->postDate ?? $element->dateCreated ?? null, Props::TYPE_TIMESTAMP),
            'expiryDate' => Props::coerce($element->expiryDate ?? null, Props::TYPE_TIMESTAMP),
            'updatedAt' => Props::coerce($element->dateUpdated ?? null, Props::TYPE_TIMESTAMP),
        ];

        foreach ($source->properties as $property) {
            if (!$property->enabled) {
                continue;
            }

            $values[$property->name] = Props::coerce($property->extract($element), $property->type);
        }

        // Recombee treats an omitted property and a null one identically, and null costs bytes on
        // every request in a bulk sync.
        return array_filter($values, static fn($v) => $v !== null && $v !== []);
    }

    /**
     * A stable fingerprint of a built payload.
     *
     * This is what makes re-syncing cheap: Craft fires a save for all sorts of reasons that do not
     * change any mapped value (a resave, a propagation, a structure move), and every one of those
     * would otherwise be an API call.
     */
    public function contentHash(array $values): string
    {
        ksort($values);

        return sha1(Json::encode($values));
    }

    /**
     * Canned mappers, so the common cases are a dropdown rather than a Twig snippet.
     *
     * Commerce keys are delegated, not implemented here — `services\Commerce` is the only file in
     * the plugin allowed to import a Commerce class, and a test asserts it.
     */
    public function special(string $key, ElementInterface $element): mixed
    {
        if (str_starts_with($key, 'commerce:')) {
            return Plugin::getInstance()->getCommerce()->special(substr($key, 9), $element);
        }

        return match ($key) {
            'image' => $this->firstImageUrl($element),
            'images' => $this->allImageUrls($element),
            'author' => $element instanceof Entry ? $element->getAuthor()?->getFriendlyName() : null,
            'categories' => $this->relatedTitles($element, Category::class),
            'categoryIds' => $this->relatedIds($element, Category::class),
            'tags' => $this->relatedTitles($element, \craft\elements\Tag::class),
            'wordCount' => $this->wordCount($element),
            'readingMinutes' => max(1, (int)ceil($this->wordCount($element) / 200)),
            'ancestors' => method_exists($element, 'getAncestors')
                ? array_map(static fn($a) => (string)$a->title, $element->getAncestors()->all())
                : null,
            'kind' => $element instanceof Asset ? $element->kind : null,
            default => null,
        };
    }

    /**
     * Every canned mapper Bee offers, for the CP dropdown.
     */
    public function specialOptions(): array
    {
        $options = [
            'image' => Craft::t('bee', 'First image URL'),
            'images' => Craft::t('bee', 'All image URLs'),
            'author' => Craft::t('bee', 'Author name'),
            'categories' => Craft::t('bee', 'Related category titles'),
            'categoryIds' => Craft::t('bee', 'Related category IDs'),
            'tags' => Craft::t('bee', 'Related tag titles'),
            'wordCount' => Craft::t('bee', 'Word count'),
            'readingMinutes' => Craft::t('bee', 'Reading time (minutes)'),
            'ancestors' => Craft::t('bee', 'Ancestor titles'),
            'kind' => Craft::t('bee', 'Asset kind'),
        ];

        if (Plugin::commerceIsReady()) {
            foreach (Plugin::getInstance()->getCommerce()->specialOptions() as $key => $label) {
                $options['commerce:' . $key] = $label;
            }
        }

        return $options;
    }

    // ─── Syncing ─────────────────────────────────────────────────────────────────────────────

    /**
     * Push one element, or remove it if it no longer belongs in the catalog.
     *
     * Returns one of the `SyncRecord::STATUS_*` values, plus `'unchanged'` when the fingerprint
     * matched and nothing was sent.
     */
    public function syncElement(ElementInterface $element, bool $force = false): string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->syncsSite((int)$element->siteId)) {
            return 'skipped';
        }

        $itemId = Ids::forElement($element);

        if ($itemId === null) {
            return 'skipped';
        }

        $source = Plugin::getInstance()->getSources()->forElement($element);

        if ($source === null || !$source->includes($element)) {
            // Not (or no longer) part of the catalog. Anything already pushed has to come back out,
            // or a disabled entry keeps being recommended forever.
            return $this->removeElement($element, $itemId);
        }

        $values = $this->buildItem($element, $source);
        $hash = $this->contentHash($values);
        $record = $this->recordFor($element, $itemId, $source);

        if (!$force && $record->contentHash === $hash && $record->status === SyncRecord::STATUS_SYNCED) {
            return 'unchanged';
        }

        try {
            Plugin::getInstance()->getClient()->post(
                'items/' . rawurlencode($itemId),
                $values + ['!cascadeCreate' => true],
                [],
                ['label' => 'sync ' . $itemId],
            );

            $record->contentHash = $hash;
            $record->status = SyncRecord::STATUS_SYNCED;
            $record->error = null;
            $record->syncedAt = Db::prepareDateForDb(new DateTime());
            $record->save(false);

            return SyncRecord::STATUS_SYNCED;
        } catch (ApiException $e) {
            $record->status = SyncRecord::STATUS_FAILED;
            $record->error = mb_substr($e->getMessage(), 0, 1000);
            $record->save(false);

            return SyncRecord::STATUS_FAILED;
        }
    }

    /**
     * Push many elements as batched `Set Item Values` calls.
     *
     * @param iterable<ElementInterface> $elements
     * @param callable|null $progress fn(int $done, int $total)
     * @return array{synced: int, unchanged: int, removed: int, failed: int, skipped: int}
     */
    public function syncElements(iterable $elements, bool $force = false, ?callable $progress = null): array
    {
        $tally = ['synced' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0, 'skipped' => 0];
        $settings = Plugin::getInstance()->getSettings();
        $sources = Plugin::getInstance()->getSources();
        $queued = [];
        $done = 0;

        $flush = function() use (&$queued, &$tally): void {
            if ($queued === []) {
                return;
            }

            $results = Plugin::getInstance()->getClient()->batch(array_map(
                static fn(array $q) => $q['request'],
                $queued,
            ));

            foreach ($queued as $i => $item) {
                $code = (int)($results[$i]['code'] ?? 0);
                /** @var SyncRecord $record */
                $record = $item['record'];

                if ($code >= 200 && $code < 300) {
                    $record->contentHash = $item['hash'];
                    $record->status = SyncRecord::STATUS_SYNCED;
                    $record->error = null;
                    $record->syncedAt = Db::prepareDateForDb(new DateTime());
                    $tally['synced']++;
                } else {
                    $record->status = SyncRecord::STATUS_FAILED;
                    $record->error = mb_substr(is_string($results[$i]['json'] ?? null)
                        ? $results[$i]['json']
                        : Json::encode($results[$i]['json'] ?? null), 0, 1000);
                    $tally['failed']++;
                }

                $record->save(false);
            }

            $queued = [];
        };

        foreach ($elements as $element) {
            $done++;

            if (!$settings->syncsSite((int)$element->siteId) || ($itemId = Ids::forElement($element)) === null) {
                $tally['skipped']++;
                $progress && $progress($done);
                continue;
            }

            $source = $sources->forElement($element);

            if ($source === null || !$source->includes($element)) {
                $status = $this->removeElement($element, $itemId);
                $tally[$status === SyncRecord::STATUS_DELETED ? 'removed' : 'skipped']++;
                $progress && $progress($done);
                continue;
            }

            $values = $this->buildItem($element, $source);
            $hash = $this->contentHash($values);
            $record = $this->recordFor($element, $itemId, $source);

            if (!$force && $record->contentHash === $hash && $record->status === SyncRecord::STATUS_SYNCED) {
                $tally['unchanged']++;
                $progress && $progress($done);
                continue;
            }

            $queued[] = [
                'record' => $record,
                'hash' => $hash,
                'request' => [
                    'method' => 'POST',
                    'path' => '/items/' . rawurlencode($itemId),
                    'params' => $values + ['!cascadeCreate' => true],
                ],
            ];

            if (count($queued) >= $settings->batchSize) {
                $flush();
            }

            $progress && $progress($done);
        }

        $flush();

        return $tally;
    }

    /**
     * Delete the Recombee item for an element and mark the row.
     *
     * Recombee cascades: deleting an item removes its interactions too. That is the intended
     * behaviour — an item nobody can buy should not keep influencing what everyone else is shown.
     */
    public function removeElement(ElementInterface $element, ?string $itemId = null): string
    {
        $itemId ??= Ids::forElement($element);

        if ($itemId === null) {
            return 'skipped';
        }

        $record = SyncRecord::findOne(['elementId' => $element->id, 'siteId' => $element->siteId]);

        // Never pushed, so there is nothing on the far side to remove.
        if ($record === null || $record->status === SyncRecord::STATUS_EXCLUDED || $record->status === SyncRecord::STATUS_DELETED) {
            return 'skipped';
        }

        return $this->deleteItem($itemId) ? SyncRecord::STATUS_DELETED : SyncRecord::STATUS_FAILED;
    }

    /**
     * Delete an item by ID, updating any sync row that names it.
     */
    public function deleteItem(string $itemId): bool
    {
        try {
            Plugin::getInstance()->getClient()->delete(
                'items/' . rawurlencode($itemId),
                [],
                ['label' => 'delete ' . $itemId],
            );
        } catch (ApiException $e) {
            // Already gone is the outcome we wanted.
            if (!$e->isClientError()) {
                return false;
            }
        }

        Craft::$app->getDb()->createCommand()->update(Table::SYNC, [
            'status' => SyncRecord::STATUS_DELETED,
            'contentHash' => null,
            'error' => null,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ], ['itemId' => $itemId])->execute();

        return true;
    }

    /**
     * Forget an element entirely — used when the element itself is deleted, before Craft's cascade
     * takes the sync row with it.
     */
    public function forgetElement(int $elementId): void
    {
        $rows = (new Query())
            ->select(['itemId'])
            ->from(Table::SYNC)
            ->where(['elementId' => $elementId])
            ->column();

        foreach (array_unique($rows) as $itemId) {
            $this->deleteItem($itemId);
        }

        Craft::$app->getDb()->createCommand()->delete(Table::SYNC, ['elementId' => $elementId])->execute();
    }

    // ─── Properties ──────────────────────────────────────────────────────────────────────────

    /**
     * The properties defined in the Recombee database, as `name => type`.
     */
    public function remoteProperties(bool $refresh = false): array
    {
        if ($this->remoteProperties !== null && !$refresh) {
            return $this->remoteProperties;
        }

        $response = Plugin::getInstance()->getClient()->get('items/properties/list/', [], ['label' => 'list properties']);
        $properties = [];

        foreach ((array)$response as $property) {
            if (isset($property['name'], $property['type'])) {
                $properties[$property['name']] = $property['type'];
            }
        }

        return $this->remoteProperties = $properties;
    }

    /**
     * Create every property the sources declare that Recombee does not have yet.
     *
     * Types are never changed in place. Recombee cannot recast a property without dropping its
     * values, so a type change is reported as a conflict for a human to resolve rather than
     * silently destroying data.
     *
     * @return array{created: string[], conflicts: array<string, array{local: string, remote: string}>}
     */
    public function syncProperties(): array
    {
        $declared = Plugin::getInstance()->getSources()->declaredProperties()['properties'];
        $remote = $this->remoteProperties(true);
        $created = [];
        $conflicts = [];

        foreach ($declared as $name => $type) {
            if (!isset($remote[$name])) {
                Plugin::getInstance()->getClient()->put(
                    'items/properties/' . rawurlencode($name),
                    [],
                    ['type' => $type],
                    ['label' => 'add property ' . $name],
                );
                $created[] = $name;
                continue;
            }

            if ($remote[$name] !== $type) {
                $conflicts[$name] = ['local' => $type, 'remote' => $remote[$name]];
            }
        }

        $this->remoteProperties = null;

        return ['created' => $created, 'conflicts' => $conflicts];
    }

    /**
     * How many items are in the Recombee catalog.
     */
    public function remoteItemCount(): ?int
    {
        try {
            $response = Plugin::getInstance()->getClient()->get(
                'items/list/',
                ['count' => 0, 'returnProperties' => 'false'],
                ['label' => 'count items', 'retries' => 0],
            );

            // Recombee answers a count-0 listing with the total in a header-shaped envelope on some
            // plans and an empty array on others; fall back to counting what Bee believes it sent.
            if (is_array($response) && isset($response['numberOfItems'])) {
                return (int)$response['numberOfItems'];
            }
        } catch (ApiException) {
            return null;
        }

        return null;
    }

    // ─── Local state ─────────────────────────────────────────────────────────────────────────

    public function syncQuery(): Query
    {
        return (new Query())->from(['s' => Table::SYNC]);
    }

    /**
     * Counts by status, for the catalog screen.
     */
    public function statusCounts(): array
    {
        $rows = (new Query())
            ->select(['status', 'c' => 'COUNT(*)'])
            ->from(Table::SYNC)
            ->groupBy(['status'])
            ->pairs();

        return array_map('intval', $rows);
    }

    /**
     * An element query for everything a source could claim, scoped to the synced sites.
     */
    public function elementQuery(Source $source, ?int $siteId = null): \craft\elements\db\ElementQueryInterface
    {
        /** @var string|ElementInterface $class */
        $class = $source->elementType;
        $query = $class::find()
            ->siteId($siteId ?? Plugin::getInstance()->getSettings()->syncedSiteIds())
            // Disabled elements have to come back too: that is how the sync learns to *remove* them.
            ->status(null)
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($source->elementType === Entry::class) {
            $query->drafts(false)->revisions(false);
        }

        return $query;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function recordFor(ElementInterface $element, string $itemId, Source $source): SyncRecord
    {
        $record = SyncRecord::findOne(['elementId' => $element->id, 'siteId' => $element->siteId]) ?? new SyncRecord();

        $record->elementId = (int)$element->id;
        $record->siteId = (int)$element->siteId;
        $record->itemId = $itemId;
        $record->elementType = $source->elementType;

        return $record;
    }

    private function urlFor(ElementInterface $element): ?string
    {
        try {
            return $element->getUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    private function handleFor(ElementInterface $element): ?string
    {
        foreach (['getSection', 'getVolume', 'getGroup', 'getType'] as $getter) {
            if (!method_exists($element, $getter)) {
                continue;
            }

            try {
                $group = $element->$getter();
            } catch (\Throwable) {
                continue;
            }

            if ($group !== null && isset($group->handle)) {
                return (string)$group->handle;
            }
        }

        return null;
    }

    private function shortTypeName(ElementInterface $element): string
    {
        $class = get_class($element);

        return strtolower(substr($class, strrpos($class, '\\') + 1));
    }

    private function firstImageUrl(ElementInterface $element): ?string
    {
        if ($element instanceof Asset) {
            return $element->kind === Asset::KIND_IMAGE ? $element->getUrl() : null;
        }

        $urls = $this->allImageUrls($element);

        return $urls[0] ?? null;
    }

    /**
     * Every image asset related to the element, in field order.
     *
     * Reads the element's own field layout rather than guessing at handles, so this works on a site
     * whose image field is called `heroPicture` without anybody configuring anything.
     */
    private function allImageUrls(ElementInterface $element): array
    {
        $urls = [];

        try {
            $layout = $element->getFieldLayout();
        } catch (\Throwable) {
            return [];
        }

        if ($layout === null) {
            return [];
        }

        foreach ($layout->getCustomFields() as $field) {
            if (!$field instanceof \craft\fields\Assets) {
                continue;
            }

            try {
                $value = $element->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            if ($value instanceof \craft\elements\db\ElementQueryInterface) {
                $value = $value->kind(Asset::KIND_IMAGE)->limit(10)->all();
            } elseif ($value instanceof \craft\elements\ElementCollection) {
                $value = $value->all();
            }

            foreach ((array)$value as $asset) {
                if ($asset instanceof Asset && $asset->kind === Asset::KIND_IMAGE && ($url = $asset->getUrl())) {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function relatedTitles(ElementInterface $element, string $class): array
    {
        return array_map(static fn($e) => (string)$e->title, $this->relatedElements($element, $class));
    }

    private function relatedIds(ElementInterface $element, string $class): array
    {
        return array_map(static fn($e) => (string)$e->id, $this->relatedElements($element, $class));
    }

    private function relatedElements(ElementInterface $element, string $class): array
    {
        try {
            /** @var string|ElementInterface $class */
            return $class::find()
                ->relatedTo($element)
                ->siteId($element->siteId)
                ->status(null)
                ->limit(50)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function wordCount(ElementInterface $element): int
    {
        $text = '';

        try {
            $layout = $element->getFieldLayout();
        } catch (\Throwable) {
            return 0;
        }

        foreach ($layout?->getCustomFields() ?? [] as $field) {
            try {
                $value = $element->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            if (is_string($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $text .= ' ' . strip_tags((string)$value);
            }
        }

        return str_word_count($text);
    }
}
