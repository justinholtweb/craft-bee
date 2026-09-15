---
title: Commerce
slug: commerce
order: 60
summary: The order funnel, variants versus products, and replaying years of existing orders.
---

Install Commerce and Bee picks it up. Add a Product or Variant source and the order funnel wires
itself up.

## What gets recorded

- **Purchases** on order completion, one per line item, with the unit price and the quantity — and
  the order's own timestamp, not `now`.
- **Cart additions** when a line item is genuinely new **(Pro)**. A quantity edit and a shipping
  recalculation both re-save every line on the order; neither is an "add to cart", and treating them
  as one would teach Recombee that everybody adds everything repeatedly.
- **Guest checkout** merges the cookie profile into the account Commerce creates **(Pro)**.

## Variants or products

Bee prefers **variants** when both are synced — the variant is the thing with a price, a SKU and a
stock level — and falls back to the product when only products are in the catalog.

Which you should sync depends on what you want to recommend. A clothing store usually wants products
in the rail and variants in the purchase signal; a store where every variant is a genuinely
different thing wants variants in both. Use `commerce:productId` on a variant source if you need to
get back to the parent.

## Historic orders

A brand-new Recombee database knows nothing while your install is sitting on years of orders.
Replaying them is the fastest route to recommendations worth showing.

```sh
php craft bee/interactions/backfill
php craft bee/interactions/backfill --since=2024-01-01 --limit=5000
```

Or press **Queue backfill** on the diagnostics screen.

> **Safe to run more than once.** Each purchase carries its order's original timestamp, and Recombee
> keys interactions on user, item and timestamp — so a second pass is refused as a duplicate rather
> than doubling everyone's history. The refusals show up in the connection log as 409s.

Line items whose product is not in any catalog source are skipped and counted, not failed. That is
usually discontinued stock, and the count is how you find out how much of your history is missing.

## Prices in a rail

Because the calls return real Craft elements, the price you render is Commerce's, live, with
whatever promotions apply right now — not a number that was copied into Recombee at sync time:

```twig
{% for variant in craft.bee.recommend({ scenario: 'cart-upsell', count: 4 }) %}
  {{ variant.title }} — {{ variant.salePrice|commerceCurrency(cart.currency) }}
{% endfor %}
```

Sync `commerce:price` anyway: Recombee needs it to *filter and boost* on, even though your template
should not read it back.

## A content-only install

Everything Commerce lives in one service, and a content-only install never loads a Commerce class.
Bee works perfectly well with no Commerce at all — entries, categories and assets in the catalog,
detail views and searches in the funnel. Install Commerce later and the order funnel wires itself
up.
