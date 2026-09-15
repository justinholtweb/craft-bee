<?php
$root = getcwd(); require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
use craft\elements\User;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\Plugin;
$p = Plugin::getInstance(); $db = Craft::$app->getDb();

// Re-send a handful of products so "Recently synced" shows both element types, not just entries.
$ids = \craft\commerce\elements\Product::find()->status('live')->limit(14)->ids();
foreach ($ids as $id) {
    $p->getCatalog()->syncElement(\Craft::$app->elements->getElementById($id), true);
}
echo "re-sent " . count($ids) . " product(s)\n";

// Recommendation traffic, so the ledger and the scenario report have something to show.
$items = $db->createCommand('SELECT itemId FROM ' . Table::SYNC . ' WHERE status = :s', [':s' => 'synced'])->queryColumn();
$uids = array_map(fn($u) => 'u' . $u, User::find()->status(null)->limit(38)->ids());
$scen = [['homepage','user',8], ['product-detail','item',6], ['cart-upsell','item',4],
         ['search-results','search',10], ['category-rail','user',6]];
$sets = 0;
foreach ($uids as $u) {
    foreach ($scen as [$name, $kind, $n]) {
        if (random_int(1, 100) > 60) continue;
        $o = ['userId' => $u, 'scenario' => $name, 'count' => $n];
        $set = match ($kind) {
            'user' => $p->getRecommendations()->toUser($o),
            'item' => $p->getRecommendations()->toItem($items[array_rand($items)], $o),
            'search' => $p->getRecommendations()->search(['winter jacket','gift set','espresso','running shoe'][random_int(0,3)], $o),
        };
        $sets++;
        foreach ($set->ids() as $id) {
            if (random_int(1, 100) <= 18) {
                $p->getInteractions()->detailView($id, ['userId' => $u]);
                if (random_int(1, 100) <= 24) $p->getInteractions()->cartAddition($id, ['userId' => $u]);
            }
        }
    }
}
printf("%d sets; ledger=%d log=%d\n", $sets,
    $db->createCommand('SELECT COUNT(*) FROM ' . Table::RECOMMS)->queryScalar(),
    $db->createCommand('SELECT COUNT(*) FROM ' . Table::LOG)->queryScalar());
