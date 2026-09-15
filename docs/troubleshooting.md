---
title: Troubleshooting
slug: troubleshooting
order: 70
summary: Wrong-region 401s, empty rails, property type drift, and item IDs that changed underneath you.
---

Start at **Bee → Diagnostics**. It runs eleven checks in the order things have to be true, and most
of what follows is on it. This page is for the failures where the error does not point at the cause.

## 401, and the credentials are right

**Check the region.** A database reached in the wrong region answers **401, not 404** — the host
exists, it just does not have your database, and it says so by refusing your signature.

It is the most common setup failure and nothing in the error says "region".

## Every rail is empty

In order:

1. **Is the catalog synced?** Diagnostics says how many items. Zero means no source matched, or the
   sync has not run.
2. **Is there any interaction history?** A brand-new database has nothing to personalise from. On a
   store, replay your orders. On a content site, give it a few days of traffic.
3. **Is the live filter excluding everything?** If `postDate` is in the future on imported content,
   every item is filtered out. Check a payload with the preview on the catalog screen.
4. **Is it a Pro call on Lite?** `search()` and `segment()` return an empty set on Lite and say so in
   the log.

## Recommendations return items that are gone

The catalog is push-based, so it can lag. That is exactly why every request is filtered on `enabled`,
`postDate` and `expiryDate` — if you have turned **Filter to live content** off, or passed
`live: false`, turn it back on.

If the filter is on and stale items still appear, the sync is failing. Check the log and run
`php craft bee/sync/catalog --force`.

## 400: invalid value for property

A property's value does not match the type declared for it *in Recombee*, which is not necessarily
the type you declared in the source. Recombee has **one property namespace per database**: if
another source, or an earlier version of this one, created `price` as a string, a double is refused.

The diagnostics *Item properties* check reports the drift. Either change the mapping to match, or
delete the property in the Recombee console and let `php craft bee/sync/properties` recreate it.

## Half my catalog disappeared from Recombee

Almost always item IDs changing underneath you, which happens when you change which sites are
synced. `e42` becomes `e42-s1`, and the old IDs stay behind with their interaction history attached.

```sh
php craft bee/sync/catalog --force
php craft bee/sync/purge
```

## The log is full of 409s

That is fine. Recombee keys interactions on user, item and timestamp, and a repeat is refused as a
duplicate. It is the duplicate protection working — and it is why the historic backfill is safe to
run twice.

A *lot* of them outside a backfill can mean the same interaction is being sent from two places: the
runtime and a server-side `trackView` on the same page, for instance.

## No detail views are being recorded

1. Is `{{ craft.bee.pageItem(entry) }}` on the page?
2. Is the runtime being injected? Diagnostics checks this.
3. Is consent granted? **Unknown consent is treated as not granted.** Check `craft.bee.hasConsent`.
4. Is the element in a catalog source? The tracking endpoint refuses items that are not in the sync
   table — otherwise anyone could mint ghost items in your database.
5. Did the visitor stay three seconds? The delay is deliberate.

## Cart additions fire on every cart change

They should not — Bee records a cart addition only when a line item is genuinely new, because a
quantity edit and a shipping recalculation both re-save every line on an order. If you are also
calling `craft.bee.trackCartAdd()` from a template, you are sending the second one yourself.

## A source cannot be edited on production

That is correct. Sources live in **project config**, so they are read-only when `allowAdminChanges`
is off. Change them on a development environment and deploy the project config.

## Reading the connection log

| Status | Usually means |
|---|---|
| `200` | Fine. |
| `400` | A property's type does not match, or an interaction carried a field its kind does not take. |
| `401` | Bad token — **or the wrong region**. Check the region first. |
| `404` | The item is not in Recombee. Usually a sync that has not run. |
| `409` | **Not a failure.** The interaction already exists. |
| `429` | Rate limited. Lower the batch size or slow the backfill. |

Credentials are never in the log: the signature and the token live in the URL query string, and only
the path is recorded.

## Working out what is actually being sent

- **Payload preview** on the catalog screen, for any element. Built by the same method the sync uses.
- **Dry run** in the settings: every request built and logged, nothing sent.
- **Log mode: all**, then `php craft bee/log/tail`.

## Console reference

```sh
php craft bee/log/tail --failures
php craft bee/log/prune --days=7
php craft bee/log/clear

php craft bee/diagnostics/check               # exits non-zero on an error
php craft bee/diagnostics/check --offline     # no requests to Recombee
```

`bee/diagnostics/check` exits non-zero when something is actually broken, so it can gate a deploy.
Warnings do not fail it. In a deploy:

```sh
php craft up
php craft bee/sync/properties
php craft bee/sync/catalog
php craft bee/diagnostics/check
```

Properties before the catalog, because an item carrying a property the database does not have is
refused. Diagnostics last, so a misconfigured region stops the deploy instead of quietly turning
every rail into a fallback.

## Still stuck

Email [justin@justinholt.com](mailto:justin@justinholt.com) with the output of
`php craft bee/diagnostics/check` and a couple of rows from the connection log. There are no
credentials in either.
