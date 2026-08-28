<?php

namespace justinholtweb\bee\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\bee\Plugin;

/**
 * Push a known set of elements to Recombee.
 *
 * Takes IDs rather than elements: a job is serialised into the database and may run minutes later,
 * and a stale serialised element would push values that are no longer true.
 */
class SyncElements extends BaseJob
{
    /** @var array<int, array{0: int, 1: int}> [elementId, siteId] pairs. */
    public array $elements = [];

    public string $elementType = '';

    public bool $force = false;

    public function execute($queue): void
    {
        if ($this->elements === []) {
            return;
        }

        /** @var string|\craft\base\ElementInterface $class */
        $class = $this->elementType;

        if (!class_exists($class)) {
            return;
        }

        $catalog = Plugin::getInstance()->getCatalog();
        $total = count($this->elements);
        $done = 0;

        // Grouped by site so each site is one element query rather than one per element.
        $bySite = [];

        foreach ($this->elements as [$elementId, $siteId]) {
            $bySite[$siteId][] = $elementId;
        }

        foreach ($bySite as $siteId => $ids) {
            $elements = $class::find()
                ->id($ids)
                ->siteId($siteId)
                // Disabled elements have to be loaded too — that is how the sync learns to delete
                // the Recombee item for something that was just unpublished.
                ->status(null)
                ->drafts(null)
                ->limit(null)
                ->all();

            $found = [];

            foreach ($elements as $element) {
                $found[$element->id] = true;
                $catalog->syncElement($element, $this->force);
                $this->setProgress($queue, ++$done / $total);
            }

            // An element that no longer loads was deleted between the save and this job running.
            foreach (array_diff($ids, array_keys($found)) as $missingId) {
                $catalog->forgetElement((int)$missingId);
                $this->setProgress($queue, ++$done / $total);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('bee', 'Syncing {count} item(s) to Recombee', ['count' => count($this->elements)]);
    }
}
