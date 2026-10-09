---
title: GraphQL
slug: graphql
order: 45
summary: beeRecommend, beeRelated and beeSearch for headless front ends, and what a schema can and cannot ask for.
---

A headless front end cannot call `craft.bee.*`, so Bee adds three queries to Craft's GraphQL API.
They go through the same service as the Twig calls — the live filter, the short timeout, the
attribution ledger and `EVENT_AFTER_RECOMMEND` all behave the same — and they hand back Craft
elements. **Pro.**

## Granting access

In **GraphQL → Schemas**, tick **Bee → Query recommendations and search** on the schema your front
end uses. Without it the three queries are not in the schema at all.

The component lets a token *ask*. What comes back is still limited by the rest of the schema: an
element is returned only when the schema can read its section, category group, volume, product type
or user group, and only in a site the schema can read. A token that cannot query your Staff section
does not get Staff entries back by asking Bee for them, and their item IDs are left out too.

## The queries

```graphql
{
  forYou: beeRecommend(count: 6, scenario: "homepage") {
    recommId
    itemIds
    elements {
      ... on clothing_Product { title url defaultPrice }
      ... on article_Entry { title url }
    }
  }

  related: beeRelated(elementId: 1234, count: 4, scenario: "product-detail") {
    recommId
    itemIds
    elements { id title url }
  }

  results: beeSearch(query: "winter boots", count: 24) {
    recommId
    itemIds
    elements { id title url }
  }
}
```

| Query | Arguments | |
|---|---|---|
| `beeRecommend` | `count`, `scenario`, `site` | Personalised for the visitor making the request. |
| `beeRelated` | `elementId` or `itemId`, `count`, `scenario`, `site` | Related to an element, personalised when there is a visitor. |
| `beeSearch` | `query` (required), `count`, `scenario`, `site` | Recombee's personalised search. |

`count` is clamped to 1–100 and defaults to the plugin's default count. `site` is a site handle and
defaults to the current site.

Each returns a `BeeRecommendations` object:

| Field | |
|---|---|
| `recommId` | Recombee's ID for this recommendation. Put it on each card as `data-bee-recomm-id` so the click is attributed. |
| `scenario`, `kind`, `abGroup` | What was asked for, and the A/B group when Recombee returned one. |
| `failed` | `true` when Bee fell back to an empty set. |
| `itemIds` | The Recombee item IDs, one-for-one with `elements`. |
| `elements` | Craft's `ElementInterface`, in Recombee's order. Use inline fragments for type-specific fields. |

## Who the visitor is

There is **no `userId` argument**, on purpose. The visitor is whoever the request's Craft session or
Bee's guest cookie says it is, exactly as for `craft.bee.recommend()`. A token is shared by every
caller of a schema, so an argument that named the user would let anyone read a stranger's
recommendations.

In practice:

- **Called from the browser** with credentials included (`fetch(…, { credentials: 'include' })`),
  the query carries the visitor's session and guest cookie, and recommendations are personal.
- **Called from a server** (a static build, server-side rendering), there is no visitor.
  `beeRecommend` answers `failed: true` with no elements — there is nobody to personalise for — so
  render a fallback. `beeRelated` and `beeSearch` still work, unpersonalised.

## What it will not accept

- **No ReQL.** `filter`, `booster`, `logic` and the rest of the template options are not arguments.
  ReQL runs inside Recombee, and a filter from the internet is a way to enumerate the catalog past
  what the site means to show. Templates keep the whole ReQL surface.
- **No ghost items.** `beeRelated` only accepts an element that is live, readable by the schema and
  already in Bee's catalog. Recombee's item endpoints create items they have not seen, so an
  unchecked ID would let a caller fill your database with empty ones.

## Caching

Craft caches GraphQL results by schema, query and variables — not by visitor. Any document that
names a Bee query is executed with that cache switched off, so one visitor's recommendations (and
their `recommId`) are never served to the next person who sends the same query. If you put a CDN
or a static cache in front of `/api`, exclude these queries from it for the same reason.

## It fails open

Nothing here raises a GraphQL error at runtime. Recombee being down, a site the schema cannot read, an element that is not in the catalog — each one answers `failed: true` with
empty lists, so the rest of the query still renders. The reason is in **Bee → Log** or Craft's log.
