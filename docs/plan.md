# Bee — plan

## Brief

"Create a new Craft CMS 5 plugin named Bee that integrates the Recombee service with both Craft and
Commerce."

## Decisions (2026-08-28)

| | |
| --- | --- |
| Editions | **Lite (free) + Pro $149** — priced with Caffeine, since this is a search/discovery-class plugin sitting in front of a paid third-party service |
| Scope | **Full** — entries *and* Commerce products/variants, the whole interaction set, all recommendation endpoints, Recombee search, scenarios, console + queue backfill, CP dashboard |
| HTTP client | **Bee's own**, on Craft's bundled Guzzle — not `recombee/php-api-client` |

The client decision is the one worth recording. HMAC-SHA1 signing is about fifteen lines, and owning
the transport is what makes the connection log, the dry-run switch, the batch chunking and the
retry policy possible at all — the SDK's request objects do none of those and would have to be
wrapped anyway. It also keeps the plugin at zero runtime dependencies, like the rest of the family.

## Architecture

Three invariants, each "there is exactly one place this happens":

1. `services\Client::send()` — every HTTP request
2. `services\Catalog::buildItem()` — every element→payload conversion
3. `services\Interactions::record()` — every interaction sent

Everything else follows. The CP "Preview payload" button cannot disagree with the sync because they
are the same method. The console cannot skip consent because it goes through the same funnel. The
log cannot be incomplete because there is nowhere else to make a request.

## What makes it a Craft plugin rather than a REST wrapper

- **Recommendations come back as Craft elements**, in Recombee's order, resolved in one query per
  element type, with anything Craft can no longer resolve dropped.
- **The catalog stays current by itself** — save, unpublish and delete all do the right thing, and a
  content fingerprint makes the no-op case free.
- **Property mappings are Craft-shaped**: field handles, element attributes, canned mappers and
  Twig — not a bespoke expression language.
- **The live-content filter** is applied to every request, because a push-based catalog lags and
  Craft's notion of "published" has to win.

## The two features that sell it

**Attribution.** Recombee can only report on — and only learn from — a recommendation if the
interaction that follows carries its `recommId`. A Craft page is cached, redirected and reloaded far
too much for that to survive in a request variable, so Bee keeps a ledger and the runtime carries the
ID across the navigation. Without this, a merchant has no way to know whether any of it is working.

**Historic backfill.** A new Recombee database knows nothing and recommends accordingly, while the
merchant's Craft install is sitting on years of orders. Replaying them turns "come back in a month"
into "it works this afternoon". It is safe to run twice because each purchase carries its order's
original timestamp, and Recombee keys interactions on (user, item, timestamp).

## Edition split

Lite is a genuinely useful integration — catalog sync, detail views, purchases, recommend-to-user and
recommend-to-item — because those are the pair that make a recommender work at all. Pro is
everything that sharpens it: the rest of the interaction set, identity merging, search, segments,
pagination, the advanced ReQL surface, attribution and reporting, and the historic backfill.

## Status

Built and verified 2026-08-28. 101 + 23 integration checks and 10 CP screens green, twice in a row,
against a mock transport in the plugin-testing harness. Console path smoke-tested end to end in dry
run: 26 items synced, 26 unchanged on the rerun.

## Still to do

- GitHub repo, tag, Packagist, Plugin Store submission
- Marketing site (`craft-bee-website`), and a registry entry
- A run against a real Recombee free-tier database, to confirm the signature and the catalog land
  the way the mock says they do
