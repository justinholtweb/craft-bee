<?php
$root = getcwd(); require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
use justinholtweb\bee\db\Table;
$db = Craft::$app->getDb();
foreach ($db->createCommand('SELECT id, status, path, ROUND(durationMs) ms FROM ' . Table::LOG
    . ' WHERE status IN (400, 429, 503) ORDER BY id DESC LIMIT 5')->queryAll() as $r) {
    printf("id=%s status=%s %sms %s\n", $r['id'], $r['status'], $r['ms'], $r['path']);
}
