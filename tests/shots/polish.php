<?php
$root = getcwd(); require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require __DIR__ . '/_settings.php';
bee_set_settings([
    'databaseId'   => '$BEE_DATABASE_ID',
    'privateToken' => '$BEE_PRIVATE_TOKEN',
    'baseUri'      => 'http://rapi-us-west.recombee.com:8899',
    'dryRun'       => false,
    'logMode'      => 'all',
]);
$s = \justinholtweb\bee\Plugin::getInstance()->getSettings();
echo "configured=" . var_export($s->isConfigured(), true) . " db=" . $s->getParsedValue('databaseId') . "\n";
echo "baseUrl=" . \justinholtweb\bee\Plugin::getInstance()->getClient()->baseUrl() . "\n";
