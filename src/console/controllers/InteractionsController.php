<?php

namespace justinholtweb\bee\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\bee\Plugin;
use yii\console\ExitCode;

/**
 * `php craft bee/interactions/…`
 */
class InteractionsController extends Controller
{
    public $defaultAction = 'backfill';

    /** Only orders on or after this date, e.g. `2024-01-01`. Empty means everything. */
    public string $since = '';

    /** Stop after this many orders. 0 means no limit. */
    public int $limit = 0;

    public function options($actionID): array
    {
        return match ($actionID) {
            'backfill' => array_merge(parent::options($actionID), ['since', 'limit']),
            default => parent::options($actionID),
        };
    }

    /**
     * Replay historic Commerce orders into Recombee as purchases.
     *
     * The single highest-value thing to run on a new install. A fresh Recombee database has no
     * history and recommends accordingly, while the Craft install is sitting on years of orders.
     *
     * Safe to run twice: each purchase carries its order's original timestamp, and Recombee keys an
     * interaction on (user, item, timestamp), so a second pass is refused as a duplicate rather
     * than doubling everyone's history.
     */
    public function actionBackfill(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->isConfigured()) {
            $this->stderr("Bee is not connected to a Recombee database.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        if (!$plugin->isPro()) {
            $this->stderr("Backfilling historic orders is a Pro feature.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        if (!Plugin::commerceIsReady()) {
            $this->stderr("Craft Commerce is not installed.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $since = $this->since !== '' ? new DateTime($this->since) : null;
        $shown = false;

        $result = $plugin->getCommerce()->backfillOrders(
            $since,
            $this->limit > 0 ? $this->limit : null,
            function(int $done, int $total) use (&$shown): void {
                if (!$shown) {
                    Console::startProgress(0, max(1, $total));
                    $shown = true;
                }

                Console::updateProgress($done, max(1, $total));
            },
        );

        if ($shown) {
            Console::endProgress();
        }

        $this->stdout(sprintf(
            "%d order(s): %d purchase(s) sent, %d skipped, %d failed.\n",
            $result['orders'], $result['sent'], $result['skipped'], $result['failed'],
        ), $result['failed'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        if ($result['skipped'] > 0) {
            $this->stdout("Skipped line items are ones whose product is not in any catalog source.\n");
        }

        return ExitCode::OK;
    }
}
