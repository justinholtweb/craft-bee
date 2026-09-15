---
title: Recommendations
slug: usage
order: 40
summary: The four Twig calls, their options, the set they return, and attribution.
---

Four calls. Every one hands back Craft elements, already loaded, in Recombee's order, with anything
Craft can no longer resolve dropped.

```twig
{# Personalised for the visitor #}
{% set recs = craft.bee.recommend({ count: 6, scenario: 'homepage' }) %}

{# Related to this item #}
{% set related = craft.bee.related(product, { count: 4, scenario: 'product-detail' }) %}

{# Recombee's own search (Pro) #}
{% set results = craft.bee.search(craft.app.request.getParam('q'), { count: 24 }) %}

{# Best of a segment (Pro) #}
{% set best = craft.bee.segment('brand-acme', { count: 8 }) %}
```

## Always write a fallback

```twig
{% for product in related %}
  <a href="{{ product.url }}" {{ craft.bee.attribution(related, product) }}>
    {{ product.title }}
  </a>
{% else %}
  {# Nothing came back. Recommendations never throw — always have a fallback. #}
  {% for product in craft.commerce.products.limit(4).all() %}…{% endfor %}
{% endfor %}
```

An empty set is a normal answer, not an error. For a visitor Recombee has never seen it is the
*correct* answer. The `{% else %}` branch is not defensive programming; it is the first-visit
experience.

## It fails open

Recommendations render into pages, so nothing here may take a page down. A failed request returns an
empty set marked `failed` and logs the reason.

Recommendation requests use a **short timeout and no retries**. The catalog sync, which runs in a
queue where waiting is free, is the only thing that retries — a page render must not wait through a
retry ladder.

## Options

| Option | |
|---|---|
| `count` | How many. Defaults to the setting. |
| `scenario` | The Recombee scenario, e.g. `homepage`, `cart`, `emailing`. |
| `filter` | Extra ReQL, combined with (not replacing) the live-content filter. |
| `live` | `false` to drop the live-content filter. |
| `siteId` | Which site to resolve elements in. |
| `userId` | Override the visitor. **Never accept this from a request.** |
| `booster`, `logic`, `diversity`, `expertSettings`, `reqlExpressions`, `returnAbGroup` | Passed straight through. **Pro.** |

A scenario is a label Recombee trains against separately, so one database can serve every slot on
the site and report on each. Name them after the slot — `homepage`, `cart-upsell`,
`product-detail` — and the attribution report becomes readable.

## On the set

| | |
|---|---|
| `recs.recommId` | The ID to attribute interactions to |
| `recs.ids()` | The raw Recombee item IDs |
| `recs.elements()` | The resolved Craft elements |
| `recs.values()` | Properties Recombee returned, if you asked for them |
| `recs.isEmpty()`, `recs.failed` | Nothing came back / the request failed |
| `recs.hasMore()`, `recs.next()` | The next page of the same recommendation. **Pro.** |
| `recs.abGroup` | The A/B group, when `returnAbGroup` was set |

`isEmpty()` and `failed` are different questions. An empty set means Recombee had nothing to say; a
failed set means the request did not succeed. Both render the same fallback, but only one is worth
an alert.

## The live filter

Every request is filtered on `enabled`, `postDate` and `expiryDate` **on Recombee's side**, so
unpublished items are excluded before they are ranked rather than removed from the list afterwards.
Filtering after the fact would silently shorten every rail.

Pass `live: false` to drop it, and `filter` to add your own ReQL on top: the two are combined, not
swapped.

## Search

Recombee's search endpoint is **per-user by design**, so two people searching the same word get
different orders. **Pro.**

```twig
{% set q = craft.app.request.getParam('q') %}
{% set results = craft.bee.search(q, { count: 24, scenario: 'search-results' }) %}

{% for entry in results %}
  <a href="{{ entry.url }}" {{ craft.bee.attribution(results, entry) }}>{{ entry.title }}</a>
{% else %}
  {% for entry in craft.entries.search(q).limit(24).all() %}…{% endfor %}
{% endfor %}
```

It is a ranking over the catalog you have synced, informed by what this visitor has done — not a
replacement for Craft's search index. Very good at "which of our products does this person mean";
the wrong tool for "find the word *warranty* anywhere on the site".

## Attribution

Recombee can report click-through and revenue per scenario, but only if the interactions that follow
a recommendation carry the `recommId` that caused them. **Pro.**

```twig
{{ craft.bee.attribution(set, item) }}
```

That renders the two data attributes the runtime reads on click, so the detail view on the *next*
page carries the `recommId`.

Every set Bee hands out is written to a ledger keyed on its `recommId`. When an interaction arrives
for an item that visitor was recommended, Bee looks the recommendation up and stamps it — so a
purchase two pages later is still attributed, with nothing threaded through your cart templates. The
ledger prunes itself on a retention window, 90 days by default.

**Bee → Diagnostics** reads the ledger back: sets handed out and distinct people reached, per
scenario, over 30 days. Click-through and revenue live in the Recombee console, which can report
them *because* Bee attached the `recommId`.

## Twig reference

| Tag | |
|---|---|
| `craft.bee.recommend(options)` | Personalised for the visitor. |
| `craft.bee.related(item, options)` | Related to an element or item ID. |
| `craft.bee.segment(id, options)` | Best of an item segment. **Pro.** |
| `craft.bee.search(query, options)` | Recombee's personalised search. **Pro.** |
| `craft.bee.attribution(set, item)` | Data attributes for a link. **Pro.** |
| `craft.bee.pageItem(item)` | What the page is about, for the runtime. |
| `craft.bee.itemId(element)` | The Recombee item ID for an element. |
| `craft.bee.userId` | The current Recombee user ID, or null. |
| `craft.bee.isConfigured` | Whether credentials are set. |
| `craft.bee.isPro` | Whether this is the Pro edition. |
| `craft.bee.hasConsent` | Whether consent has been granted. |

## Multi-site

Recommendations resolve in the current site by default. Pass `siteId` to resolve elsewhere:

```twig
{% set recs = craft.bee.recommend({
  count: 6,
  siteId: craft.app.sites.getSiteByHandle('de').id,
}) %}
```

Items Craft can no longer resolve in that site are dropped rather than returned as nulls, so a rail
is never padded with blanks.

## Events

For merchandising rules that belong to the site rather than to the model:

```php
use justinholtweb\bee\events\RecommendationEvent;
use justinholtweb\bee\services\Recommendations;
use yii\base\Event;

Event::on(Recommendations::class, Recommendations::EVENT_AFTER_RECOMMEND, function(RecommendationEvent $e) {
    // $e->set is the RecommendationSet; $e->params were the request options.
});
```
