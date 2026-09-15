<?php
$root = getcwd(); require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
use justinholtweb\bee\db\Table;
use justinholtweb\bee\Plugin;
foreach (Plugin::getInstance()->getSources()->all() as $s) {
    echo "source {$s->name}: /admin/bee/catalog/{$s->uid}\n";
}
$db = Craft::$app->getDb();
$err = $db->createCommand('SELECT id, status, path FROM ' . Table::LOG . ' WHERE success = 0 ORDER BY durationMs DESC LIMIT 3')->queryAll();
foreach ($err as $r) echo "log error id={$r['id']} status={$r['status']} {$r['path']}\n";
$ok = $db->createCommand('SELECT id, path FROM ' . Table::LOG . " WHERE path LIKE '%batch%' ORDER BY id DESC LIMIT 1")->queryAll();
foreach ($ok as $r) echo "log batch id={$r['id']} {$r['path']}\n";
