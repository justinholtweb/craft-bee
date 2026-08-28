<?php

namespace justinholtweb\bee\jobs;

use Craft;
use craft\queue\BaseJob;
use DateTime;
use justinholtweb\bee\Plugin;

/**
 * Replay historic Commerce orders into Recombee as purchases.
 *
 * Safe to run more than once: Recombee keys an interaction on (user, item, timestamp), and this
 * sends each order's original `dateOrdered`, so a second run lands on the same key and is refused
 * as a duplicate rather than doubling every customer's history.
 */
class BackfillOrders extends BaseJob
{
    /** ISO date string, or null for everything. */
    public ?string $since = null;

    public ?int $limit = null;

    public function execute($queue): void
    {
        if (!Plugin::commerceIsReady() || !Plugin::getInstance()->isPro()) {
            return;
        }

        Plugin::getInstance()->getCommerce()->backfillOrders(
            $this->since !== null ? new DateTime($this->since) : null,
            $this->limit,
            function(int $done, int $total) use ($queue): void {
                $this->setProgress($queue, $total > 0 ? min(1, $done / $total) : 1);
            },
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('bee', 'Backfilling Commerce orders into Recombee');
    }
}
