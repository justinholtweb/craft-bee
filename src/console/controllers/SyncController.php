<?php

namespace justinholtweb\bee\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\Plugin;
use justinholtweb\bee\records\SyncRecord;
use yii\console\ExitCode;

/**
 * `php craft bee/sync/…`
 */
class SyncController extends Controller
{
    public $defaultAction = 'catalog';

    /** Re-send everything, even items whose fingerprint has not changed. */
    public bool $force = false;

    /** Limit to one source, by UID or name. */
    public string $source = '';

    /** Build and print payloads without sending anything. */
    public bool $dryRun = false;

    /**
     * Yii reads these off `options()`, and a `null` default on a typed property is a fatal the
     * moment the option is omitted. Empty string is the "not given" value throughout.
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'catalog' => array_merge(parent::options($actionID), ['force', 'source', 'dryRun']),
            'element' => array_merge(parent::options($actionID), ['force']),
            default => parent::options($actionID),
        };
    }

    /**
     * Sync every catalog source.
     */
    public function actionCatalog(): int
    {
        $plugin = Plugin::getInstance();

        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }

        if ($this->dryRun) {
            $plugin->getSettings()->dryRun = true;
            $this->stdout("Dry run: nothing will be sent.\n", Console::FG_YELLOW);
        }

        $sources = $plugin->getSources()->enabled();

        if ($this->source !== '') {
            $sources = array_filter($sources, fn($s) => $s->uid === $this->source || strcasecmp($s->name, $this->source) === 0);

            if ($sources === []) {
                $this->stderr("No enabled source matches “{$this->source}”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }
        }

        if ($sources === []) {
            $this->stderr("No enabled catalog sources. Add one under Bee → Catalog.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        // Properties first. An item carrying a property Recombee has never heard of is rejected in
        // full, so a first sync that skipped this would report a hundred per cent failure.
        $this->stdout("Checking item properties… ");

        try {
            $result = $plugin->getCatalog()->syncProperties();
        } catch (ApiException $e) {
            $this->stdout("\n");
            $this->stderr('Could not read the property list: ' . $e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout(sprintf("%d created.\n", count($result['created'])), Console::FG_GREEN);

        if ($result['conflicts'] !== []) {
            foreach ($result['conflicts'] as $name => $conflict) {
                $this->stderr(sprintf(
                    "  ! %s is %s in Recombee but %s here. Recombee cannot recast it without dropping its values.\n",
                    $name,
                    $conflict['remote'],
                    $conflict['local'],
                ), Console::FG_YELLOW);
            }
        }

        $totals = ['synced' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($sources as $source) {
            $query = $plugin->getCatalog()->elementQuery($source);
            $count = (int)$query->count();

            $this->stdout(sprintf("\n%s — %d element(s)\n", $source->name, $count), Console::FG_CYAN);

            if ($count === 0) {
                continue;
            }

            Console::startProgress(0, $count);

            $result = $plugin->getCatalog()->syncElements(
                $query->each(200),
                $this->force,
                static fn(int $done) => Console::updateProgress($done, $count),
            );

            Console::endProgress();

            foreach ($result as $key => $value) {
                $totals[$key] += $value;
            }

            $this->stdout(sprintf(
                "  %d synced, %d unchanged, %d removed, %d failed, %d skipped\n",
                $result['synced'], $result['unchanged'], $result['removed'], $result['failed'], $result['skipped'],
            ));
        }

        $this->stdout(sprintf(
            "\nDone: %d synced, %d unchanged, %d removed, %d failed, %d skipped.\n",
            $totals['synced'], $totals['unchanged'], $totals['removed'], $totals['failed'], $totals['skipped'],
        ), $totals['failed'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return $totals['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Create every declared item property in Recombee.
     */
    public function actionProperties(): int
    {
        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }

        try {
            $result = Plugin::getInstance()->getCatalog()->syncProperties();
        } catch (ApiException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        foreach ($result['created'] as $name) {
            $this->stdout("  + {$name}\n", Console::FG_GREEN);
        }

        foreach ($result['conflicts'] as $name => $conflict) {
            $this->stderr(sprintf("  ! %s: %s here, %s in Recombee\n", $name, $conflict['local'], $conflict['remote']), Console::FG_YELLOW);
        }

        $this->stdout(sprintf("%d created, %d conflict(s).\n", count($result['created']), count($result['conflicts'])));

        return $result['conflicts'] === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sync one element by ID.
     *
     * @param int $elementId
     * @param int|null $siteId
     */
    public function actionElement(int $elementId, ?int $siteId = null): int
    {
        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if ($element === null) {
            $this->stderr("No element {$elementId}.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $status = Plugin::getInstance()->getCatalog()->syncElement($element, $this->force);
        $this->stdout("{$element->title}: {$status}\n");

        return $status === SyncRecord::STATUS_FAILED ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Delete the Recombee items behind rows that are no longer part of any source.
     */
    public function actionPurge(): int
    {
        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }

        $itemIds = array_unique((new Query())
            ->select(['itemId'])
            ->from(Table::SYNC)
            ->where(['status' => [SyncRecord::STATUS_EXCLUDED, SyncRecord::STATUS_FAILED]])
            ->column());

        if ($itemIds === []) {
            $this->stdout("Nothing to purge.\n");

            return ExitCode::OK;
        }

        if ($this->interactive && !$this->confirm(sprintf('Delete %d item(s) from Recombee?', count($itemIds)))) {
            return ExitCode::OK;
        }

        $catalog = Plugin::getInstance()->getCatalog();
        $deleted = 0;

        foreach ($itemIds as $itemId) {
            if ($catalog->deleteItem($itemId)) {
                $deleted++;
            }
        }

        $this->stdout("Deleted {$deleted} item(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function guard(): bool
    {
        if (!Plugin::getInstance()->getSettings()->isConfigured()) {
            $this->stderr("Bee is not connected to a Recombee database. Set BEE_DATABASE_ID and BEE_PRIVATE_TOKEN.\n", Console::FG_RED);

            return false;
        }

        return true;
    }
}
