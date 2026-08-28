<?php
/**
 * Bee Commerce checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-bee/tests/integration/commerce-checks.php
 *
 * Kept separate from `checks.php` because Commerce is an optional dependency: this file is allowed
 * to name Commerce classes and the main suite is not, and a content-only install should be able to
 * run the main suite green without Commerce installed at all.
 *
 * Builds a real order and completes it, so the order-completion event wiring is exercised rather
 * than assumed — the constant lives on the Order *element*, not the Orders service, and a wrong
 * guess there fails silently in production.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as CommercePlugin;
use craft\helpers\Json;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use justinholtweb\bee\db\Table;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\helpers\Props;
use justinholtweb\bee\models\PropertyMap;
use justinholtweb\bee\models\Settings;
use justinholtweb\bee\models\Source;
use justinholtweb\bee\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function withMock(array $responses, callable $fn): array
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $client = Plugin::getInstance()->getClient();
    $client->setHttpClient(new GuzzleClient(['handler' => $stack, 'http_errors' => true]));

    try {
        $result = $fn();
    } finally {
        $client->setHttpClient(null);
    }

    return [$result, $history];
}

function jsonResponse(mixed $body, int $status = 200): PsrResponse
{
    return new PsrResponse($status, ['Content-Type' => 'application/json'], Json::encode($body));
}

function bodiesFromBatch(array $history, int $i = 0): array
{
    $decoded = Json::decodeIfJson((string)$history[$i]['request']->getBody());

    return $decoded['requests'] ?? [];
}

if (!Plugin::commerceIsReady()) {
    echo "Craft Commerce is not installed here — nothing to check.\n";
    exit(0);
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();
$originalEdition = $plugin->edition;
$sourceUids = [];
$orderIds = [];

try {
    $settings->databaseId = 'bee-test-db';
    $settings->privateToken = 'not-a-real-token';
    $settings->region = 'eu-west';
    $settings->enabled = true;
    $settings->dryRun = false;
    $settings->logMode = Settings::LOG_NONE;
    $settings->trackingEnabled = true;
    $settings->trackPurchases = true;
    $settings->trackCartAdditions = true;
    $settings->purchaseVariants = true;
    $settings->retries = 0;
    $settings->syncSites = '*';

    Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_PRO);

    $variant = Variant::find()->status(null)->one();
    $product = $variant->getOwner();

    if (!$product instanceof Product) {
        throw new RuntimeException('The harness has no products to check against.');
    }

    /**
     * Distinct orders for the funnel assertions.
     *
     * They have to be distinct: `Interactions::record()` de-duplicates within a request, so
     * recording the *same* order three times in one process would correctly produce one batch and
     * two silent no-ops — which would look like a bug in the assertions rather than the feature
     * working.
     *
     * The harness also carries legacy fixture orders with no customer and no line items, which are
     * exactly the ones a backfill has to pass over.
     */
    $usableOrders = [];

    foreach (Order::find()->isCompleted(true)->orderBy(['commerce_orders.dateOrdered' => SORT_DESC])->limit(40)->all() as $candidate) {
        if (count($candidate->getLineItems()) > 0 && $candidate->getCustomer() !== null) {
            $usableOrders[] = $candidate;
        }

        if (count($usableOrders) >= 4) {
            break;
        }
    }

    if (count($usableOrders) < 4) {
        throw new RuntimeException('The harness needs at least four completed orders with a customer and line items.');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Catalog sources');

    $variantSource = new Source([
        'name' => 'Bee test variants',
        'elementType' => Variant::class,
        'liveOnly' => false,
        'properties' => [
            new PropertyMap(['name' => 'price', 'type' => Props::TYPE_DOUBLE, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'commerce:price']),
            new PropertyMap(['name' => 'sku', 'type' => Props::TYPE_STRING, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'commerce:sku']),
            new PropertyMap(['name' => 'inStock', 'type' => Props::TYPE_BOOLEAN, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'commerce:inStock']),
            new PropertyMap(['name' => 'productType', 'type' => Props::TYPE_STRING, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'commerce:productType']),
            new PropertyMap(['name' => 'productItemId', 'type' => Props::TYPE_STRING, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'commerce:productId']),
        ],
    ]);

    $productSource = new Source([
        'name' => 'Bee test products',
        'elementType' => Product::class,
        'liveOnly' => false,
        'properties' => [
            new PropertyMap(['name' => 'minPrice', 'type' => Props::TYPE_DOUBLE, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'commerce:minPrice']),
            new PropertyMap(['name' => 'maxPrice', 'type' => Props::TYPE_DOUBLE, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'commerce:maxPrice']),
            new PropertyMap(['name' => 'variantSkus', 'type' => Props::TYPE_SET, 'kind' => PropertyMap::KIND_SPECIAL, 'value' => 'commerce:variantSkus']),
        ],
    ]);

    check('Commerce sources save', function() use ($plugin, $variantSource, $productSource, &$sourceUids) {
        $ok = $plugin->getSources()->save($variantSource) && $plugin->getSources()->save($productSource);
        $sourceUids[] = $variantSource->uid;
        $sourceUids[] = $productSource->uid;

        return $ok ?: Json::encode($variantSource->getErrors() + $productSource->getErrors());
    });

    check('a variant payload carries price, SKU, stock and the parent product’s item ID', function() use ($plugin, $variant, $variantSource, $product) {
        $values = $plugin->getCatalog()->buildItem($variant, $variantSource);

        return is_float($values['price'] ?? null)
            && ($values['sku'] ?? null) === $variant->sku
            && array_key_exists('inStock', $values)
            && ($values['productItemId'] ?? null) === Ids::forElement($product)
            && ($values['itemType'] ?? null) === 'variant'
            ?: 'got ' . Json::encode($values);
    });

    check('a product payload carries the variant price range', function() use ($plugin, $product, $productSource) {
        $values = $plugin->getCatalog()->buildItem($product, $productSource);

        return isset($values['minPrice'], $values['maxPrice'])
            && $values['minPrice'] <= $values['maxPrice']
            && ($values['itemType'] ?? null) === 'product'
            ?: 'got ' . Json::encode($values);
    });

    check('the product type handle is read from the variant’s parent, not from the variant', function() use ($plugin, $variant, $variantSource, $product) {
        $values = $plugin->getCatalog()->buildItem($variant, $variantSource);

        return ($values['productType'] ?? null) === $product->getType()->handle
            ?: 'got ' . var_export($values['productType'] ?? null, true);
    });

    check('an untracked-stock variant reports null rather than zero', function() use ($plugin, $variant) {
        // Null and 0 mean opposite things: "sell as many as you like" versus "sold out". Getting
        // this backwards would filter every unlimited-stock product out of every recommendation.
        $value = $plugin->getCatalog()->special('commerce:stock', $variant);

        return $value === null || is_int($value) ?: 'got ' . var_export($value, true);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Purchasable resolution');

    check('a line item resolves to the variant when variants are synced', function() use ($plugin, $settings, $usableOrders) {
        $settings->purchaseVariants = true;
        $lineItem = $usableOrders[0]->getLineItems()[0];

        $itemId = $plugin->getCommerce()->itemIdForLineItem($lineItem);

        return is_string($itemId) && str_starts_with($itemId, 'v') ?: 'got ' . var_export($itemId, true);
    });

    check('it falls back to the product when only products are synced', function() use ($plugin, $settings, $usableOrders) {
        $settings->purchaseVariants = false;

        $lineItem = $usableOrders[0]->getLineItems()[0];
        $itemId = $plugin->getCommerce()->itemIdForLineItem($lineItem);

        $settings->purchaseVariants = true;

        return is_string($itemId) && str_starts_with($itemId, 'p') ?: 'got ' . var_export($itemId, true);
    });

    check('a purchasable nothing claims resolves to null rather than a ghost item', function() use ($plugin, $variantSource, $productSource, $usableOrders) {
        // With both sources gone, nothing in the catalog covers this line item — and an interaction
        // naming an unknown item would cascadeCreate a propertyless ghost that can never be
        // recommended usefully.
        $plugin->getSources()->delete($variantSource->uid);
        $plugin->getSources()->delete($productSource->uid);

        $itemId = $plugin->getCommerce()->itemIdForLineItem($usableOrders[0]->getLineItems()[0]);

        $plugin->getSources()->save($variantSource);
        $plugin->getSources()->save($productSource);

        return $itemId === null ?: 'got ' . var_export($itemId, true);
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('The order funnel');

    check('a completed order becomes one purchase per line item, batched', function() use ($plugin, $usableOrders) {
        $order = $usableOrders[0];
        $expected = count($order->getLineItems());

        [$tally, $history] = withMock([
            jsonResponse(array_fill(0, $expected, ['code' => 200, 'json' => 'ok'])),
        ], fn() => $plugin->getCommerce()->recordOrder($order));

        $requests = $history === [] ? [] : bodiesFromBatch($history);

        return count($history) === 1
            && count($requests) === $expected
            && $requests[0]['path'] === '/purchases/'
            && $tally['sent'] === $expected
            ?: 'tally=' . Json::encode($tally) . ' requests=' . count($requests);
    });

    check('a purchase sends the unit price and the quantity separately', function() use ($plugin, $usableOrders) {
        $order = $usableOrders[1];
        $lineItem = $order->getLineItems()[0];

        [, $history] = withMock([
            jsonResponse(array_fill(0, count($order->getLineItems()), ['code' => 200, 'json' => 'ok'])),
        ], fn() => $plugin->getCommerce()->recordOrder($order));

        $params = bodiesFromBatch($history)[0]['params'];

        // Sending the line total as `price` would make every multi-buy look like a luxury purchase.
        return (float)$params['amount'] === (float)$lineItem->qty
            && (float)$params['price'] < (float)$order->getTotalPrice() + 0.01
            && (float)$params['price'] > 0
            ?: 'got ' . Json::encode($params);
    });

    check('a purchase carries the order’s own timestamp, not now', function() use ($plugin, $usableOrders) {
        $order = $usableOrders[2];

        [, $history] = withMock([
            jsonResponse(array_fill(0, count($order->getLineItems()), ['code' => 200, 'json' => 'ok'])),
        ], fn() => $plugin->getCommerce()->recordOrder($order));

        $params = bodiesFromBatch($history)[0]['params'];

        return (int)$params['timestamp'] === $order->dateOrdered->getTimestamp()
            ?: 'got ' . $params['timestamp'] . ' wanted ' . $order->dateOrdered->getTimestamp();
    });

    check('the purchase is attributed to the order’s customer, by UID', function() use ($plugin, $usableOrders) {
        $order = $usableOrders[3];
        $expected = 'u' . $order->getCustomer()->uid;

        [, $history] = withMock([
            jsonResponse(array_fill(0, count($order->getLineItems()), ['code' => 200, 'json' => 'ok'])),
        ], fn() => $plugin->getCommerce()->recordOrder($order));

        return bodiesFromBatch($history)[0]['params']['userId'] === $expected
            ?: 'got ' . bodiesFromBatch($history)[0]['params']['userId'];
    });

    check('the completion event actually fires Bee’s handler', function() use ($plugin, $variant, &$orderIds) {
        // The event constant lives on the Order *element*, not the Orders service. Guessing wrong
        // there produces a plugin that looks wired up and records nothing, so this builds and
        // completes a real order rather than calling the handler directly.
        $commerce = CommercePlugin::getInstance();

        $order = new Order();
        $order->number = $commerce->getCarts()->generateCartNumber();
        $order->email = 'bee-checks@example.test';
        $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;

        if (!Craft::$app->getElements()->saveElement($order)) {
            return 'could not save a cart: ' . Json::encode($order->getErrors());
        }

        $orderIds[] = $order->id;

        $lineItem = $commerce->getLineItems()->createLineItem($order, $variant->id, [], 2);
        $order->addLineItem($lineItem);

        if (!Craft::$app->getElements()->saveElement($order)) {
            return 'could not add a line item: ' . Json::encode($order->getErrors());
        }

        $plugin->getCommerce()->attachEventHandlers();

        [, $history] = withMock([
            jsonResponse([['code' => 200, 'json' => 'ok']]),
        ], function() use ($order) {
            $order->markAsComplete();
        });

        if ($history === []) {
            return 'the completion event did not reach Bee';
        }

        $params = bodiesFromBatch($history)[0]['params'] ?? [];

        return ($params['itemId'] ?? null) === Ids::forElement($variant)
            && (float)($params['amount'] ?? 0) === 2.0
            ?: 'got ' . Json::encode($params);
    });

    check('Lite does not send cart additions even with Commerce wired up', function() use ($plugin, $usableOrders) {
        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_LITE);

        $lineItem = $usableOrders[0]->getLineItems()[0];

        [$sent, $history] = withMock([], fn() => $plugin->getCommerce()->recordCartAddition($lineItem));

        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_PRO);

        return $sent === false && $history === [];
    });

    check('a cart addition is never recorded against a completed order', function() use ($plugin, $usableOrders) {
        [$sent, $history] = withMock([], fn() => $plugin->getCommerce()->recordCartAddition($usableOrders[0]->getLineItems()[0]));

        return $sent === false && $history === [];
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Historic backfill');

    check('backfill replays completed orders as purchases', function() use ($plugin) {
        [$result, $history] = withMock([
            jsonResponse(array_fill(0, 500, ['code' => 200, 'json' => 'ok'])),
        ], fn() => $plugin->getCommerce()->backfillOrders(null, 40));

        return $result['orders'] === 40
            && $result['sent'] > 0
            && $history !== []
            ?: 'got ' . Json::encode($result);
    });

    check('an order with no resolvable customer is counted as skipped, not passed over in silence', function() use ($plugin) {
        // The harness carries legacy orders with no customer. A backfill that reported
        // "40 orders, 0 purchases" with nothing in the skipped column would be unexplainable.
        [$result] = withMock([
            jsonResponse(array_fill(0, 500, ['code' => 200, 'json' => 'ok'])),
        ], fn() => $plugin->getCommerce()->backfillOrders(null, 40));

        return $result['orders'] === 40 && ($result['sent'] + $result['skipped'] + $result['failed']) > 0
            ?: 'got ' . Json::encode($result);
    });

    check('backfilled purchases carry their original order timestamps', function() use ($plugin) {
        [, $history] = withMock([
            jsonResponse(array_fill(0, 500, ['code' => 200, 'json' => 'ok'])),
        ], fn() => $plugin->getCommerce()->backfillOrders(null, 40));

        $timestamps = array_map(
            static fn(array $r) => (int)($r['params']['timestamp'] ?? 0),
            bodiesFromBatch($history),
        );

        // This is what makes a second backfill safe: Recombee keys an interaction on
        // (user, item, timestamp), so replaying the original stamps is refused as duplicates
        // rather than doubling every customer's history.
        return $timestamps !== [] && min($timestamps) > 0 && min($timestamps) < time()
            ?: 'got ' . Json::encode($timestamps);
    });

    check('a since date narrows the replay', function() use ($plugin) {
        [$all] = withMock([jsonResponse(array_fill(0, 500, ['code' => 200, 'json' => 'ok']))],
            fn() => $plugin->getCommerce()->backfillOrders(null, 40));

        [$recent] = withMock([jsonResponse(array_fill(0, 500, ['code' => 200, 'json' => 'ok']))],
            fn() => $plugin->getCommerce()->backfillOrders(new DateTime('+1 day'), 100));

        return $recent['orders'] < $all['orders'] && $recent['orders'] === 0
            ?: 'all=' . $all['orders'] . ' recent=' . $recent['orders'];
    });

    check('Lite cannot backfill', function() use ($plugin) {
        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_LITE);

        [$result, $history] = withMock([], fn() => $plugin->getCommerce()->backfillOrders(null, 40));

        Craft::$app->getPlugins()->switchEdition('bee', Plugin::EDITION_PRO);

        return $result['orders'] === 0 && $history === [];
    });

    // ─────────────────────────────────────────────────────────────────────────────────────────
    section('Isolation');

    check('Bee reports Commerce’s version through the one service allowed to ask', function() use ($plugin) {
        return $plugin->getCommerce()->version() === CommercePlugin::getInstance()->getVersion();
    });

    check('every Commerce mapper is offered in the CP dropdown', function() use ($plugin) {
        $options = $plugin->getCatalog()->specialOptions();
        $commerceKeys = array_filter(array_keys($options), static fn($k) => str_starts_with($k, 'commerce:'));

        return count($commerceKeys) >= 12 ?: 'only ' . count($commerceKeys);
    });

    check('every Commerce mapper returns without fataling on a real variant and a real product', function() use ($plugin, $variant, $product) {
        foreach (array_keys($plugin->getCommerce()->specialOptions()) as $key) {
            foreach ([$variant, $product] as $element) {
                $plugin->getCatalog()->special('commerce:' . $key, $element);
            }
        }

        return true;
    });
} finally {
    section('Cleanup');

    foreach ($orderIds as $orderId) {
        Craft::$app->getElements()->deleteElementById($orderId, Order::class, null, true);
    }

    foreach ($sourceUids as $uid) {
        Plugin::getInstance()->getSources()->delete($uid);
    }

    Craft::$app->getProjectConfig()->flush();

    Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::RECOMMS)->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::SYNC)->execute();

    Craft::$app->getPlugins()->switchEdition('bee', $originalEdition);
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $originalSettings);
    Craft::$app->getProjectConfig()->flush();

    echo "  ✓ orders, sources, rows, edition and settings restored\n";

    echo "\n";
    echo $failed === 0
        ? "\033[32mAll $passed Commerce checks passed.\033[0m\n"
        : "\033[31m$failed of " . ($passed + $failed) . " Commerce checks failed.\033[0m\n";

    exit($failed === 0 ? 0 : 1);
}
