<?php
/**
 * Undoes seed.php. Run this before the integration suites — the sources it creates declare
 * properties, and the property test counts the requests a sync makes across every declared
 * property, so leaving them in place fails two checks that have nothing wrong with them.
 *
 *     ddev exec php /var/www/craft-bee/tests/shots/teardown.php
 */
$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require __DIR__ . '/_settings.php';

use justinholtweb\bee\db\Table;
use justinholtweb\bee\Plugin;

$plugin = Plugin::getInstance();
foreach ($plugin->getSources()->all() as $s) {
    $plugin->getSources()->delete($s->uid);
}
bee_set_settings([
    'databaseId' => '', 'privateToken' => '', 'baseUri' => '',
    'dryRun' => false, 'logMode' => 'errors',
]);
$db = Craft::$app->getDb();
foreach ([Table::SYNC, Table::LOG, Table::RECOMMS] as $t) {
    $db->createCommand()->delete($t)->execute();
}
echo "sources removed, settings cleared, tables emptied\n";
