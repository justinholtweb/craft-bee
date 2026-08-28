<?php

namespace justinholtweb\bee\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\bee\Plugin;

/**
 * A full sync of one catalog source, one page at a time.
 *
 * Re-queues itself rather than looping to the end. A catalog of two hundred thousand products would
 * otherwise be one job that runs for an hour, holds a connection, and loses all its progress if the
 * worker is restarted mid-way.
 */
class SyncSource extends BaseJob
{
    public string $sourceUid = '';

    public bool $force = false;

    public int $offset = 0;

    public int $pageSize = 500;

    /** Carried across pages so the final tally is the whole source, not the last page. */
    public array $tally = [];

    public function execute($queue): void
    {
        $source = Plugin::getInstance()->getSources()->get($this->sourceUid);

        if ($source === null) {
            return;
        }

        $catalog = Plugin::getInstance()->getCatalog();
        $query = $catalog->elementQuery($source);
        $total = (int)$query->count();

        if ($total === 0) {
            return;
        }

        $elements = (clone $query)->offset($this->offset)->limit($this->pageSize)->all();

        if ($elements === []) {
            return;
        }

        $result = $catalog->syncElements($elements, $this->force, function(int $done) use ($queue, $total): void {
            $this->setProgress($queue, min(1, ($this->offset + $done) / $total));
        });

        foreach ($result as $key => $value) {
            $this->tally[$key] = ($this->tally[$key] ?? 0) + $value;
        }

        $nextOffset = $this->offset + $this->pageSize;

        if ($nextOffset < $total) {
            Craft::$app->getQueue()->push(new self([
                'sourceUid' => $this->sourceUid,
                'force' => $this->force,
                'offset' => $nextOffset,
                'pageSize' => $this->pageSize,
                'tally' => $this->tally,
            ]));
        }
    }

    protected function defaultDescription(): ?string
    {
        $source = Plugin::getInstance()->getSources()->get($this->sourceUid);

        return Craft::t('bee', 'Syncing “{name}” to Recombee', ['name' => $source?->name ?? $this->sourceUid]);
    }
}
