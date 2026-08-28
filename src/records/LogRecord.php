<?php

namespace justinholtweb\bee\records;

use craft\db\ActiveRecord;
use justinholtweb\bee\db\Table;

/**
 * @property int $id
 * @property string $method
 * @property string $path
 * @property string|null $label
 * @property int $status
 * @property float $durationMs
 * @property string|null $request
 * @property string|null $response
 * @property string|null $error
 * @property bool $success
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
