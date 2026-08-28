<?php

namespace justinholtweb\bee\migrations;

use craft\db\Migration;
use justinholtweb\bee\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::RECOMMS);
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::SYNC);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::SYNC, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'itemId' => $this->string(255)->notNull(),
            'elementType' => $this->string(255)->notNull(),
            'contentHash' => $this->char(40)->null(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'error' => $this->text()->null(),
            'syncedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'method' => $this->string(10)->notNull(),
            'path' => $this->string(255)->notNull(),
            'label' => $this->string(255)->null(),
            'status' => $this->integer()->notNull()->defaultValue(0),
            'durationMs' => $this->float()->notNull()->defaultValue(0),
            'success' => $this->boolean()->notNull()->defaultValue(false),
            'request' => $this->mediumText()->null(),
            'response' => $this->mediumText()->null(),
            'error' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::RECOMMS, [
            'id' => $this->primaryKey(),
            'recommId' => $this->string(64)->notNull(),
            'userId' => $this->string(255)->notNull(),
            'scenario' => $this->string(255)->null(),
            'kind' => $this->string(32)->notNull(),
            'sourceItemId' => $this->string(255)->null(),
            'itemIds' => $this->text()->notNull(),
            'abGroup' => $this->string(64)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // One row per element per site. This unique index *is* the guarantee that a save storm
        // cannot leave two competing sync states for the same catalog item.
        $this->createIndex(null, Table::SYNC, ['elementId', 'siteId'], true);
        $this->createIndex(null, Table::SYNC, ['itemId'], false);
        $this->createIndex(null, Table::SYNC, ['status'], false);
        $this->createIndex(null, Table::SYNC, ['elementType'], false);

        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
        $this->createIndex(null, Table::LOG, ['success'], false);

        // Attribution is looked up by recommId (from the runtime) and swept by date.
        $this->createIndex(null, Table::RECOMMS, ['recommId'], true);
        $this->createIndex(null, Table::RECOMMS, ['userId', 'dateCreated'], false);
        $this->createIndex(null, Table::RECOMMS, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        // Deleting an element takes its sync row with it. The Recombee item is deleted separately,
        // by the delete handler, *before* this cascade runs — the row is what knows the item ID.
        $this->addForeignKey(null, Table::SYNC, ['elementId'], \craft\db\Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SYNC, ['siteId'], \craft\db\Table::SITES, ['id'], 'CASCADE', 'CASCADE');
    }
}
