<?php
/**
 * Seeds the plugin-testing install so Bee's CP screens render with plausible data for screenshots.
 *
 *     ddev exec php /var/www/craft-bee/tests/tmp/seed.php
 *
 * Nothing here talks to Recombee. The plugin is put in dry-run, so the sync table is filled by
 * Bee's own catalog code — real item IDs, real content hashes, real statuses — while every request
 * stops at the client. The connection log and the attribution ledger are written directly, shaped
 * exactly like the rows Bee writes itself.
 */
$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\models\Source;
use justinholtweb\bee\Plugin;

$plugin = Plugin::getInstance();
$db = Craft::$app->getDb();
$pc = Craft::$app->getProjectConfig();

// --- 1. settings ---------------------------------------------------------
require __DIR__ . '/_settings.php';
bee_set_settings([
    'databaseId'      => 'northwind-store',
    'privateToken'    => str_repeat('a1b2c3d4', 8),
    'region'          => 'us-west',
    'dryRun'          => true,
    'logMode'         => 'all',
    'autoSync'        => true,
    'syncMode'        => 'queue',
    'trackingEnabled' => true,
    'attribution'     => true,
    'defaultCount'    => 6,
]);
if ($plugin->edition !== Plugin::EDITION_PRO) {
    Craft::$app->plugins->switchEdition('bee', Plugin::EDITION_PRO);
}
$pc->saveModifiedConfigData();
echo "settings saved (dry-run, edition pro)\n";

// --- 2. catalog sources --------------------------------------------------
foreach ($plugin->getSources()->all() as $existing) {
    $plugin->getSources()->delete($existing->uid);
}

$productType = \craft\commerce\Plugin::getInstance()->productTypes->getProductTypeByHandle('tpProducts');
$newsSection = Craft::$app->entries->getSectionByHandle('news');
$newsType    = $newsSection->getEntryTypes()[0];

$sources = [
    new Source([
        'name' => 'Products',
        'enabled' => true,
        'elementType' => \craft\commerce\elements\Product::class,
        'groupUids' => [$productType->uid],
        'liveOnly' => true,
        'properties' => [
            ['name' => 'price',       'type' => 'double',  'kind' => 'special', 'value' => 'commerce:price'],
            ['name' => 'salePrice',   'type' => 'double',  'kind' => 'special', 'value' => 'commerce:promotionalPrice'],
            ['name' => 'onSale',      'type' => 'boolean', 'kind' => 'special', 'value' => 'commerce:onSale'],
            ['name' => 'sku',         'type' => 'string',  'kind' => 'special', 'value' => 'commerce:sku'],
            ['name' => 'inStock',     'type' => 'boolean', 'kind' => 'special', 'value' => 'commerce:inStock'],
            ['name' => 'productType', 'type' => 'string',  'kind' => 'special', 'value' => 'commerce:productType'],
            ['name' => 'categories',  'type' => 'set',     'kind' => 'special', 'value' => 'categories'],
            ['name' => 'margin',      'type' => 'double',  'kind' => 'twig',
             'value' => '{{ (element.defaultPrice - (element.defaultVariant.price ?? 0) * 0.62)|round(2) }}'],
        ],
        'sortOrder' => 1,
    ]),
    new Source([
        'name' => 'News articles',
        'enabled' => true,
        'elementType' => \craft\elements\Entry::class,
        'groupUids' => [$newsSection->uid],
        'typeUids' => [$newsType->uid],
        'liveOnly' => true,
        'properties' => [
            ['name' => 'author',         'type' => 'string', 'kind' => 'special', 'value' => 'author'],
            ['name' => 'categories',     'type' => 'set',    'kind' => 'special', 'value' => 'categories'],
            ['name' => 'tags',           'type' => 'set',    'kind' => 'special', 'value' => 'tags'],
            ['name' => 'wordCount',      'type' => 'int',    'kind' => 'special', 'value' => 'wordCount'],
            ['name' => 'readingMinutes', 'type' => 'int',    'kind' => 'special', 'value' => 'readingMinutes'],
            ['name' => 'section',        'type' => 'string', 'kind' => 'twig',    'value' => '{{ element.section.name }}'],
        ],
        'sortOrder' => 2,
    ]),
];
foreach ($sources as $s) {
    $plugin->getSources()->save($s) or print("  ! {$s->name}: " . Json::encode($s->getErrors()) . "\n");
}
$pc->saveModifiedConfigData();
echo "sources: " . count($plugin->getSources()->all()) . "\n";
