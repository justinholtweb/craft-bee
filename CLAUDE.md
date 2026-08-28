# Bee — Craft CMS 5 Plugin

## Project Overview

Bee integrates [Recombee](https://www.recombee.com/) with Craft CMS 5 and Craft Commerce 5: catalog
sync, interaction tracking, personalised recommendations and search. Distributed as
`justinholtweb/craft-bee`. **Lite (free) + Pro ($149).**

## Why it exists

There is no Craft plugin for Recombee. The work a site would otherwise have to do by hand is all of
it: signing requests, modelling the catalog, keeping it current, tracking interactions, resolving
recommended IDs back into elements, and attributing interactions to the recommendations that caused
them. Bee's pitch is that a merchant configures a source and writes one Twig tag.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+** (optional), Yii2, Twig
- **No runtime dependencies.** The Recombee client is written against Craft's bundled Guzzle rather
  than `recombee/php-api-client`, so batching, retries and the log are Bee's own and an SDK bump
  cannot break a customer's site.
- No build step. The front-end runtime is one hand-written ES5-compatible file.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\bee`
- Package: `justinholtweb/craft-bee`
- Handle: `bee`

### The three invariants

1. **`services\Client::send()` — nothing else makes an HTTP request.** Signing, retries, the
   dry-run switch and the connection log therefore cannot be bypassed, and the log can be trusted as
   a complete record.
2. **`services\Catalog::buildItem()` — the only place an element becomes a payload.** The save
   handler, the queue job, the console backfill and the CP "Preview payload" button all call it, so
   the preview is byte-identical to what Recombee receives.
3. **`services\Interactions::record()` — the only place an interaction is sent.** Twig, the runtime,
   the Commerce hooks and the console all funnel there, so consent, edition gating, identity
   resolution and attribution happen once.

### Data model

- `{{%bee_sync}}` — per (element, site). Unique on `(elementId, siteId)`; `contentHash` is what makes
  a re-sync of an unchanged catalog free.
- `{{%bee_log}}` — the connection log. Payloads truncated, credentials never present (only the path
  is recorded; the signature and token live in the query string).
- `{{%bee_recomms}}` — the attribution ledger. Unique on `recommId`.

Catalog **sources** are *not* a table: they live in project config, because which entry types feed
the recommender is a deployment concern. Consequence: read-only when `allowAdminChanges` is off,
which is correct.

### Protocol notes (read from Recombee's own PHP client, not guessed)

- Host is `https://rapi-{region}.recombee.com`; regions are `us-west`, `eu-west`, `ap-se`,
  `ca-east`. **A database reached in the wrong region answers 401, not 404.**
- Signing: build `/{databaseId}/{path}` plus any query string, append `?hmac_timestamp=…` (or `&…`),
  **HMAC-SHA1 that whole string**, then append `&hmac_sign=…` — which is not itself signed. Sign
  anything else and every request comes back 401 with no indication of why.
- `Set Item Values` is `POST /{db}/items/{itemId}` with the property map as the body and
  **`!cascadeCreate`** — the exclamation mark is how Recombee separates control keys from property
  names.
- Property list is `GET /{db}/items/properties/list/`, not `items/list/properties`.
- Batch is `POST /{db}/batch/` with `{requests: [{method, path, params}]}`, paths database-relative;
  the response is one `{code, json}` per sub-request, in order.
- Search is `POST /{db}/search/users/{userId}/items/` — it is per-user, because it is personalised.
- **409 means the interaction already exists** (Recombee keys them on user + item + timestamp). That
  is the duplicate protection working, not a failure — and it is why the historic order backfill is
  safe to run twice.
- Interactions are typed: sending `duration` on a purchase is a 400, not an ignored field.

### Fail-open, everywhere

Recommendations render into pages and interactions fire during checkout. Nothing Bee does may take a
page down: `Recommendations` returns an empty `RecommendationSet` marked `failed`, `Interactions`
returns `false`, and both log the reason. Recommendation requests use a short timeout and **no
retries** — a page render must not wait through a retry ladder.

### Caching

Nothing visitor-specific is written into a page. The runtime's config is page-specific, consent is
read from a cookie by the runtime, and the guest cookie is minted by the tracking endpoint rather
than the page render. `craft.bee.recommend()` is the deliberate exception.

### The public tracking endpoint

`controllers\TrackController` is the only thing a stranger can reach. It resolves the user
server-side (never from the request), refuses items not in the sync table (otherwise `cascadeCreate`
would let anyone mint ghost items in the merchant's database), rate limits per client, and has CSRF
**deliberately off** — a beacon cannot carry a token, and a token in the page would make every page
uncacheable.

## Traps found while building this

- **`allowAnonymous` wants Craft's bitmask, not a boolean.** `['record' => true]` throws
  `Invalid $allowAnonymous value` from the *controller constructor*, which surfaces as a 500 on the
  tracking endpoint and nowhere else. Use `self::ALLOW_ANONYMOUS_LIVE`.
- **`Product::getVariants()` returns a `VariantCollection` in Commerce 5, not an array.** It is
  Countable and iterable, so `count()` and `foreach` keep working and the change is invisible until
  the first `array_map` — which then throws, gets swallowed by the mapping's own error handling, and
  silently drops the price range from every product in the catalog.
- **`Purchasable::getWeight()` is gone in Commerce 5.7** while the property remains on Variant.
  Everything Commerce-priced goes through the tolerant `read()` helper for the same reason.
- **Craft signs its own cookies, so a cookie added to the response cannot be read off the request in
  the same cycle**, and `$_COOKIE` fails signature validation. A freshly minted guest token has to
  be memoised in-process or the first page view of every session is orphaned under an ID the next
  page never uses.
- **A diagnostics check that opts out has to be typed `?array`.** Returning `null` from a method
  declared `: array` is a `TypeError` at the top of the whole screen.
- **Every Craft element is `Traversable`** (Yii models are `IteratorAggregate`), so a generic
  "flatten anything iterable" branch in the property coercion would explode an element into its
  attribute values. Only `is_array()` counts as a container.
- **Element save handlers must be typed `ElementEvent`, not `ModelEvent`** — the wrong type fatals on
  every element save in the install, not just Bee's.
- Element saves are collected and flushed into **one** queue job at `EVENT_AFTER_REQUEST`. A job per
  save turns a bulk resave into ten thousand jobs.
- **The order-completion event lives on the `Order` element**, not the `Orders` service. Guessing
  wrong produces a plugin that looks wired up and records nothing, which is why
  `commerce-checks.php` builds and completes a real order rather than calling the handler.
- Craft's editable table always posts a blank final row; the controller drops rows with no name.
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`.
- Never mark plugin settings `required`: a fresh install then cannot save *any* setting.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-bee/tests/integration/checks.php           # 101 checks
ddev exec php /var/www/craft-bee/tests/integration/commerce-checks.php  #  23 checks
bash ~/Sites/craft-bee/tests/integration/cp-smoke.sh                    #  10 checks
ddev exec bash -c 'find /var/www/craft-bee/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

There is deliberately **no live Recombee database** in the suite. The HTTP layer is swapped for a
Guzzle mock (`Client::setHttpClient()`), which is *stricter* than a live server: every request is
captured and asserted on, including the ones a real server would have quietly accepted. The one
thing a mock cannot verify is the signature, so that is checked against an independently computed
HMAC rather than against Bee's own code.

`commerce-checks.php` is separate because Commerce is optional: it is the only test file allowed to
name a Commerce class, and `checks.php` asserts that `services\Commerce.php` is the only source file
that imports one. Both suites are idempotent and restore sources, rows, edition and settings.

## Coding conventions

- `Craft::t('bee', '…')` for user-facing strings; `src/translations/en/bee.php` lists every one, and
  a check fails if a string is missing from it
- Business logic in services; controllers stay thin
- Anything that runs during a page render or a checkout fails **open**
- The visitor's identity is resolved server-side, always
