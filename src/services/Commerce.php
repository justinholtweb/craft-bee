<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\base\Purchasable;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\events\LineItemEvent;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as CommercePlugin;
use craft\commerce\services\LineItems;
use DateTime;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\models\Interaction;
use justinholtweb\bee\Plugin;
use yii\base\Component;
use yii\base\Event;

/**
 * Everything Commerce.
 *
 * **This is the only file in the plugin allowed to import a Commerce class**, and a test asserts
 * it. Commerce is an optional dependency: if a `craft\commerce\…` import leaked into a service that
 * runs on a content-only site, the plugin would fatal on install. Confining it here means the
 * question "does Bee work without Commerce?" has a one-line answer.
 *
 * Commerce 5 moved pricing and stock onto new APIs while keeping several of the old accessors
 * around, and which ones exist varies across 5.x point releases. Rather than pin a narrow version,
 * the readers below try the current accessor first and fall back — see `read()`.
 */
class Commerce extends Component
{
    public function isReady(): bool
    {
        return Plugin::commerceIsReady();
    }

    /**
     * Wire up the order funnel. Called from `Plugin::init()`, and only when Commerce is present.
     */
    public function attachEventHandlers(): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->trackPurchases) {
            Event::on(
                Order::class,
                Order::EVENT_AFTER_COMPLETE_ORDER,
                function(Event $event): void {
                    /** @var Order $order */
                    $order = $event->sender;
                    $this->recordOrder($order);
                },
            );
        }

        if ($settings->trackCartAdditions) {
            Event::on(
                LineItems::class,
                LineItems::EVENT_AFTER_SAVE_LINE_ITEM,
                function(LineItemEvent $event): void {
                    // Only a genuinely new line is an "add to cart". A quantity edit, a shipping
                    // recalculation and a price refresh all re-save every line on the order.
                    if ($event->isNew) {
                        $this->recordCartAddition($event->lineItem);
                    }
                },
            );
        }
    }

    // ─── The funnel ──────────────────────────────────────────────────────────────────────────

    /**
     * One purchase per line item, sent as a single batch.
     *
     * Fires on order completion rather than on payment: an order Commerce considers complete is the
     * event a merchant means by "sold", and it is the only one guaranteed to happen exactly once.
     */
    public function recordOrder(Order $order): array
    {
        $interactions = Plugin::getInstance()->getInteractions();
        $userId = $this->userIdFor($order);

        if ($userId === null) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        }

        // A guest who browsed anonymously and then checked out has two profiles in Recombee: the
        // cookie one that did all the browsing, and the account Commerce just created for them.
        // Merging here is what keeps the session that led to the sale attached to the sale.
        $this->mergeGuestProfile($userId);

        $timestamp = $order->dateOrdered ?? new DateTime();
        $batch = [];

        foreach ($order->getLineItems() as $lineItem) {
            $itemId = $this->itemIdForLineItem($lineItem, $order);

            if ($itemId === null) {
                continue;
            }

            $batch[] = $interactions->build(Interaction::PURCHASE, $itemId, [
                'userId' => $userId,
                'timestamp' => $timestamp,
                'amount' => (float)$lineItem->qty,
                'price' => $this->unitPrice($lineItem),
            ]);
        }

        return $interactions->recordMany($batch);
    }

    public function recordCartAddition(LineItem $lineItem): bool
    {
        if (!Plugin::getInstance()->isPro()) {
            // Cart additions are a Pro signal. Lite still gets views and purchases, which is enough
            // for the model to work.
            return false;
        }

        $order = $lineItem->getOrder();

        if ($order === null || $order->isCompleted) {
            return false;
        }

        $itemId = $this->itemIdForLineItem($lineItem, $order);

        if ($itemId === null) {
            return false;
        }

        // The shopper is at the keyboard, so the request identity is the right one — and it is the
        // cookie profile, which is what has been accumulating the browsing history.
        $userId = Plugin::getInstance()->getIdentity()->currentUserId() ?? $this->userIdFor($order);

        if ($userId === null) {
            return false;
        }

        return Plugin::getInstance()->getInteractions()->cartAddition($itemId, [
            'userId' => $userId,
            'amount' => (float)$lineItem->qty,
            'price' => $this->unitPrice($lineItem),
        ]);
    }

    /**
     * Replay historic orders into Recombee. Pro.
     *
     * This is the single most valuable thing Bee does on day one. A brand-new Recombee database
     * knows nothing and recommends accordingly, while the merchant's Craft install is sitting on
     * years of purchases. Backfilling turns "come back in a month" into "it works this afternoon".
     *
     * @param callable|null $progress fn(int $ordersDone, int $ordersTotal)
     * @return array{orders: int, sent: int, skipped: int, failed: int}
     */
    public function backfillOrders(?DateTime $since = null, ?int $limit = null, ?callable $progress = null): array
    {
        $tally = ['orders' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        if (!Plugin::getInstance()->isPro()) {
            return $tally;
        }

        $query = Order::find()
            ->isCompleted(true)
            ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC])
            ->limit(null);

        if ($since !== null) {
            $query->dateOrdered('>= ' . $since->format('Y-m-d H:i:s'));
        }

        $total = $limit ?? (int)$query->count();
        $interactions = Plugin::getInstance()->getInteractions();
        $batch = [];
        $done = 0;

        foreach ($query->each(100) as $order) {
            /** @var Order $order */
            $done++;
            $tally['orders']++;

            $userId = $this->userIdFor($order);

            if ($userId === null) {
                // An order with no resolvable customer cannot be attributed to anyone. Counting it
                // rather than passing over it silently is the difference between "5 orders, 0
                // purchases, no idea why" and an answer.
                $tally['skipped'] += count($order->getLineItems());
            } else {
                foreach ($order->getLineItems() as $lineItem) {
                    $itemId = $this->itemIdForLineItem($lineItem, $order);

                    if ($itemId === null) {
                        $tally['skipped']++;
                        continue;
                    }

                    $batch[] = $interactions->build(Interaction::PURCHASE, $itemId, [
                        'userId' => $userId,
                        'timestamp' => $order->dateOrdered,
                        'amount' => (float)$lineItem->qty,
                        'price' => $this->unitPrice($lineItem),
                        // A replay of an order Bee already sent must not become a second purchase.
                        // Recombee keys an interaction on (user, item, timestamp), so re-sending the
                        // original order timestamp is what makes the backfill safe to run twice.
                    ]);
                }
            }

            if (count($batch) >= 500) {
                $this->tally($interactions->recordMany($batch, false), $tally);
                $batch = [];
            }

            $progress && $progress($done, $total);

            if ($limit !== null && $done >= $limit) {
                break;
            }
        }

        if ($batch !== []) {
            $this->tally($interactions->recordMany($batch, false), $tally);
        }

        return $tally;
    }

    // ─── Catalog mappers ─────────────────────────────────────────────────────────────────────

    public function specialOptions(): array
    {
        return [
            'price' => Craft::t('bee', 'Price'),
            'promotionalPrice' => Craft::t('bee', 'Promotional price'),
            'onSale' => Craft::t('bee', 'On sale'),
            'sku' => Craft::t('bee', 'SKU'),
            'stock' => Craft::t('bee', 'Stock'),
            'inStock' => Craft::t('bee', 'In stock'),
            'productType' => Craft::t('bee', 'Product type handle'),
            'variantCount' => Craft::t('bee', 'Number of variants'),
            'variantSkus' => Craft::t('bee', 'All variant SKUs'),
            'minPrice' => Craft::t('bee', 'Lowest variant price'),
            'maxPrice' => Craft::t('bee', 'Highest variant price'),
            'weight' => Craft::t('bee', 'Weight'),
            'productId' => Craft::t('bee', 'Parent product item ID'),
        ];
    }

    public function special(string $key, ElementInterface $element): mixed
    {
        if (!$this->isReady()) {
            return null;
        }

        $purchasable = $this->purchasableFor($element);

        return match ($key) {
            'price' => $purchasable !== null ? $this->price($purchasable) : $this->productPrice($element, 'min'),
            'promotionalPrice' => $purchasable !== null
                ? $this->read($purchasable, ['getPromotionalPrice', 'getSalePrice'])
                : null,
            'onSale' => $purchasable !== null
                ? (($p = $this->read($purchasable, ['getPromotionalPrice', 'getSalePrice'])) !== null
                    && $p < $this->price($purchasable))
                : null,
            'sku' => $purchasable?->getSku() ?? $this->defaultVariantValue($element, 'sku'),
            'stock' => $purchasable !== null ? $this->stock($purchasable) : $this->productStock($element),
            'inStock' => $purchasable !== null
                ? ($this->stock($purchasable) === null || $this->stock($purchasable) > 0)
                : (($s = $this->productStock($element)) === null || $s > 0),
            'productType' => $this->productTypeHandle($element),
            'variantCount' => $element instanceof Product ? count($this->variants($element)) : null,
            'variantSkus' => $element instanceof Product
                ? array_values(array_filter(array_map(static fn(Variant $v) => $v->sku, $this->variants($element))))
                : null,
            'minPrice' => $this->productPrice($element, 'min'),
            'maxPrice' => $this->productPrice($element, 'max'),
            // Commerce 5 dropped `getWeight()` from the Purchasable interface but kept the
            // property on Variant, so this has to go through the tolerant reader like the rest.
            'weight' => $purchasable !== null ? $this->weight($purchasable) : null,
            'productId' => $element instanceof Variant ? Ids::forElement($element->getOwner() ?? $element) : null,
            default => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The Recombee item ID for what was actually bought.
     *
     * Prefers the variant when variants are synced, because that is the thing with a price, a SKU
     * and a stock level — but falls back to the product, because a merchant who only syncs products
     * still deserves a working purchase signal.
     */
    public function itemIdForLineItem(LineItem $lineItem, ?Order $order = null): ?string
    {
        try {
            $purchasable = $lineItem->getPurchasable();
        } catch (\Throwable) {
            return null;
        }

        if (!$purchasable instanceof ElementInterface) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        $sources = Plugin::getInstance()->getSources();

        if ($settings->purchaseVariants && $sources->forElement($purchasable) !== null) {
            return Ids::forElement($purchasable);
        }

        if ($purchasable instanceof Variant) {
            $product = $purchasable->getOwner();

            if ($product !== null && $sources->forElement($product) !== null) {
                return Ids::forElement($product);
            }
        }

        // Nothing claims it, so it is not in the catalog and an interaction naming it would create
        // a propertyless ghost item that can never be recommended usefully.
        return null;
    }

    /**
     * The Recombee user ID for an order's customer.
     */
    public function userIdFor(Order $order): ?string
    {
        try {
            $customer = $order->getCustomer();
        } catch (\Throwable) {
            $customer = null;
        }

        return Plugin::getInstance()->getIdentity()->userIdFor($customer);
    }

    private function mergeGuestProfile(string $userId): void
    {
        if (!Plugin::getInstance()->isPro() || !Plugin::getInstance()->getSettings()->mergeGuestsOnLogin) {
            return;
        }

        $identity = Plugin::getInstance()->getIdentity();
        $current = $identity->currentUserId(false);

        if ($current === null || $current === $userId || !Ids::isGuest($current)) {
            return;
        }

        try {
            $identity->merge($current, $userId);
            $identity->clearGuestToken();
        } catch (\Throwable $e) {
            Craft::warning('Bee could not merge the guest profile at checkout: ' . $e->getMessage(), 'bee');
        }
    }

    private function purchasableFor(ElementInterface $element): ?Purchasable
    {
        if ($element instanceof Purchasable) {
            return $element;
        }

        if ($element instanceof Product) {
            return $element->getDefaultVariant();
        }

        return null;
    }

    /**
     * Unit price, not line total. Recombee's `price` is per unit and `amount` carries the quantity;
     * sending the line total makes every multi-buy look like a luxury purchase.
     */
    private function unitPrice(LineItem $lineItem): ?float
    {
        $value = $this->read($lineItem, ['getSalePrice', 'getPromotionalPrice', 'getPrice']);

        if ($value === null && isset($lineItem->salePrice)) {
            $value = $lineItem->salePrice;
        }

        return $value !== null ? round((float)$value, 4) : null;
    }

    /**
     * A product's variants as a plain array.
     *
     * Commerce 5 returns a `VariantCollection` here, not an array. It is Countable and iterable, so
     * `count()` and `foreach` keep working and the change is invisible until the first `array_map`
     * — which then throws, and gets swallowed by the mapping's own error handling, silently
     * dropping the price range from every product in the catalog.
     *
     * @return Variant[]
     */
    private function variants(Product $product): array
    {
        $variants = $product->getVariants();

        if (is_array($variants)) {
            return $variants;
        }

        return method_exists($variants, 'all') ? $variants->all() : iterator_to_array($variants);
    }

    private function weight(Purchasable $purchasable): ?float
    {
        $value = $this->read($purchasable, ['getWeight']);

        if ($value === null && isset($purchasable->weight)) {
            $value = $purchasable->weight;
        }

        return $value !== null ? (float)$value : null;
    }

    private function price(Purchasable $purchasable): ?float
    {
        $value = $this->read($purchasable, ['getPrice', 'getBasePrice']);

        return $value !== null ? round((float)$value, 4) : null;
    }

    /**
     * Commerce 5 replaced `hasUnlimitedStock` with inventory tracking, and `getStock()` means
     * different things across the 5.x line. Null means "not tracked", which is not the same as zero.
     */
    private function stock(Purchasable $purchasable): ?int
    {
        foreach (['getInventoryTracked', 'getHasUnlimitedStock'] as $getter) {
            if (method_exists($purchasable, $getter)) {
                $tracked = $purchasable->$getter();

                if ($getter === 'getInventoryTracked' && !$tracked) {
                    return null;
                }

                if ($getter === 'getHasUnlimitedStock' && $tracked) {
                    return null;
                }
            }
        }

        $value = $this->read($purchasable, ['getStock']);

        return $value !== null ? (int)$value : null;
    }

    private function productPrice(ElementInterface $element, string $which): ?float
    {
        if (!$element instanceof Product) {
            return null;
        }

        $prices = array_values(array_filter(array_map(
            fn(Variant $v) => $this->price($v),
            $this->variants($element),
        ), static fn($p) => $p !== null));

        if ($prices === []) {
            return null;
        }

        return $which === 'max' ? max($prices) : min($prices);
    }

    private function productStock(ElementInterface $element): ?int
    {
        if (!$element instanceof Product) {
            return null;
        }

        $total = 0;

        foreach ($this->variants($element) as $variant) {
            $stock = $this->stock($variant);

            // One untracked variant makes the whole product unlimited.
            if ($stock === null) {
                return null;
            }

            $total += $stock;
        }

        return $total;
    }

    private function defaultVariantValue(ElementInterface $element, string $attribute): mixed
    {
        if (!$element instanceof Product) {
            return null;
        }

        return $element->getDefaultVariant()?->$attribute;
    }

    private function productTypeHandle(ElementInterface $element): ?string
    {
        $product = $element instanceof Variant ? $element->getOwner() : $element;

        if (!$product instanceof Product) {
            return null;
        }

        try {
            return $product->getType()?->handle;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Call the first getter that exists, swallowing the ones Commerce has retired.
     */
    private function read(object $object, array $getters): mixed
    {
        foreach ($getters as $getter) {
            if (!method_exists($object, $getter)) {
                continue;
            }

            try {
                $value = $object->$getter();
            } catch (\Throwable) {
                continue;
            }

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function tally(array $result, array &$tally): void
    {
        foreach (['sent', 'skipped', 'failed'] as $key) {
            $tally[$key] += $result[$key] ?? 0;
        }
    }

    /**
     * Commerce's own version, for the diagnostics screen.
     */
    public function version(): ?string
    {
        return $this->isReady() ? CommercePlugin::getInstance()?->getVersion() : null;
    }
}
