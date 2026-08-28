<?php

namespace justinholtweb\bee\db;

/**
 * Bee's tables, in one place so a typo is a fatal rather than a silent no-op.
 */
abstract class Table
{
    /** Per-element sync state: which Craft element is which Recombee item, and whether it is current. */
    public const SYNC = '{{%bee_sync}}';

    /** The connection log — every request Bee makes, with timing and outcome. */
    public const LOG = '{{%bee_log}}';

    /** The attribution ledger: recommendations handed out, so interactions can be tied back to them. */
    public const RECOMMS = '{{%bee_recomms}}';
}
