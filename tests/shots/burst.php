<?php
$root = getcwd(); require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
use craft\elements\User;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\Plugin;
$p = Plugin::getInstance(); $db = Craft::$app->getDb();
$items = $db->createCommand('SELECT itemId FROM ' . Table::SYNC . ' LIMIT 200')->queryColumn();
$uids = array_map(fn($u) => 'u' . $u, User::find()->status(null)->limit(12)->ids());
foreach ($uids as $u) {
    $p->getRecommendations()->toUser(['userId' => $u, 'scenario' => 'homepage', 'count' => 8]);
    $p->getInteractions()->detailView($items[array_rand($items)], ['userId' => $u]);
    $p->getInteractions()->cartAddition($items[array_rand($items)], ['userId' => $u]);
}
printf("log rows: %d\n", $db->createCommand('SELECT COUNT(*) FROM ' . Table::LOG)->queryScalar());
