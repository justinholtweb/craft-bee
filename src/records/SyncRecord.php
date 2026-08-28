<?php

namespace justinholtweb\bee\records;

use craft\db\ActiveRecord;
use justinholtweb\bee\db\Table;

/**
 * One row per (element, site) that Bee has pushed, or tried to.
 *
 * `contentHash` is what stops a nightly re-sync from rewriting an unchanged catalog — and, more
 * importantly, what stops an element saved forty times an hour from spending forty API calls.
 *
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property string $itemId
 * @property string $elementType
 * @property string|null $contentHash
 * @property string $status
 * @property string|null $error
 * @property string|null $syncedAt
 */
class SyncRecord extends ActiveRecord
{
    public const STATUS_SYNCED = 'synced';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_DELETED = 'deleted';

    public static function tableName(): string
    {
        return Table::SYNC;
    }
}
