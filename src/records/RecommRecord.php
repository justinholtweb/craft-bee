<?php

namespace justinholtweb\bee\records;

use craft\db\ActiveRecord;
use justinholtweb\bee\db\Table;

/**
 * The attribution ledger.
 *
 * Recombee can only report on how well its recommendations perform if the interactions that follow
 * them carry the `recommId` they came from. Craft pages are cached, redirected and reloaded, so the
 * recommId cannot live in a request variable — it is written here when a recommendation is handed
 * out and looked up again when an interaction arrives for one of its items.
 *
 * @property int $id
 * @property string $recommId
 * @property string $userId
 * @property string|null $scenario
 * @property string $kind
 * @property string|null $sourceItemId
 * @property string $itemIds
 * @property string|null $abGroup
 * @property string $dateCreated
 */
class RecommRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RECOMMS;
    }
}
