---
title: Installation
slug: installation
order: 10
summary: Requirements, install, pointing Bee at a Recombee database, and the editions.
---

Two commands, two environment variables, and a region. Nothing is sent to Recombee until you add a
catalog source, so installing Bee changes nothing on its own.

## Requirements

| | |
|---|---|
| Craft CMS | 5.3 or later |
| PHP | 8.2 or later |
| Craft Commerce | 5.0 or later — **optional**, picked up automatically if present |
| Recombee | a database at [recombee.com](https://www.recombee.com/) |

There is no build step and no runtime dependency beyond Craft itself. The Recombee client is written
against Craft's bundled Guzzle rather than `recombee/php-api-client`, so the batching, the retries
and the connection log are Bee's own and an SDK bump cannot break your site.

## Install

```sh
composer require justinholtweb/craft-bee
php craft plugin/install bee
```

Or find **Bee** in the Craft Plugin Store and install it from there.

## Credentials

Create a database at Recombee and note its ID, its private token, and the region it was created in.
Put the secrets in `.env`:

```
BEE_DATABASE_ID="your-database-id"
BEE_PRIVATE_TOKEN="your-private-token"
```

Then in **Settings → Bee**, set the two fields to `$BEE_DATABASE_ID` and `$BEE_PRIVATE_TOKEN`, pick
the region, and press **Test connection**.

Referencing environment variables rather than pasting the values in is what lets staging and
production point at different databases. Diagnostics reports a warning if you paste them literally.

### Regions

| Region | Host |
|---|---|
| `us-west` | `rapi-us-west.recombee.com` |
| `eu-west` | `rapi-eu-west.recombee.com` |
| `ap-se` | `rapi-ap-se.recombee.com` |
| `ca-east` | `rapi-ca-east.recombee.com` |

> **A database reached in the wrong region answers 401, not 404.** If the credentials are right and
> the connection test still fails with 401, the region is wrong. It is the most common setup failure
> and nothing in the error says "region".

## Then

1. **Bee → Catalog → New source.** Pick an element type and the sections or product types it covers.
2. **Sync everything**, or `php craft bee/sync/catalog`.
3. **Bee → Diagnostics.** Eleven checks, each with a fix.

On a Commerce store, run `php craft bee/interactions/backfill` before judging the quality of
anything. A brand-new Recombee database knows nothing and recommends accordingly.

## Editions

| | Lite | Pro |
|---|---|---|
| **Price** | Free | **$149**, then $59/year |
| Catalog sync — entries, categories, assets, products, variants | ✓ | ✓ |
| Detail views and purchases | ✓ | ✓ |
| Recommend to user, recommend to item | ✓ | ✓ |
| Twig API, element resolution, connection log, diagnostics | ✓ | ✓ |
| Console sync, dry run, preview payload | ✓ | ✓ |
| Cart additions, bookmarks, ratings, view portions | — | ✓ |
| Guest → account merge | — | ✓ |
| Recombee search and item segments | — | ✓ |
| Pagination (`next()`) | — | ✓ |
| Boosters, custom logic, diversity, ReQL expressions | — | ✓ |
| Attribution ledger and the recommendations report | — | ✓ |
| Historic Commerce order backfill | — | ✓ |
| Client-side recommendations endpoint | — | ✓ |

Lite is a real integration, not a teaser: detail views and purchases are the pair that make a
recommender work at all. Everything in Pro sharpens it.

If a Pro licence lapses, Bee drops back to Lite and keeps running — the catalog stays in sync, the
two Lite calls keep answering, and the Pro calls return an empty set and say so in the log. Nothing
breaks and no page goes down.

## Trying it safely on a live install

Turn **Dry run** on before connecting a production site to a new database. Every request Bee would
make is built and written to the connection log, and none of them are sent.
