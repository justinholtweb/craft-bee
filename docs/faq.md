---
title: FAQ
slug: faq
order: 80
summary: Common questions about connecting Craft and Craft Commerce to Recombee.
---

## Do I need a Recombee account, and does Bee replace it?

You need one — Bee is the integration, not the recommender. Recombee does the modelling and the
ranking; Bee does everything between it and Craft: signing, the catalog, the funnel, resolving IDs
back into elements, and the attribution that lets Recombee report which rail earned an order. You
create a database at recombee.com, paste its ID, private token and region into the settings screen,
and the diagnostics screen tells you whether it worked.

## How much work is this on a store that already has products?

A source, a sync and a tag. **Bee → Catalog → New source**, pick Product and the product types it
covers, add any properties you want to filter or boost on, save. Then `php craft bee/sync/catalog`,
and `php craft bee/interactions/backfill` to replay the orders you already have — which is the
fastest route to recommendations worth showing, because a brand-new database knows nothing. Then one
tag on a page.

## What happens to my page if Recombee is slow or down?

It renders. Recommendation requests use a short timeout and **no retries** — a page render must not
wait through a retry ladder — and a failure returns an empty set marked `failed` and logs the reason.
Your template falls back to whatever it falls back to. Interactions fail the same way: they return
false and log, because they fire during checkout and a checkout must not care.

## Will this break my page caching?

No, with one deliberate exception. Nothing visitor-specific is written into a page: the runtime's
config is page-specific, consent is read from a cookie by the runtime rather than the render, and
the guest cookie is minted by the tracking endpoint. The exception is `craft.bee.recommend()`, which
*is* personalised — so the fragment around it should not be cached. Everything else can be.

## How does Bee know who a visitor is?

**Server-side, always.** A logged-in user is their Craft user; a guest gets a signed cookie minted by
the tracking endpoint. The identity is never read off the request, which is what stops a stranger
recording interactions as somebody else. When a guest signs in or checks out, the two are merged in
Recombee so the history they built before signing up is not thrown away.

## Is the public tracking endpoint safe?

It is the only thing a stranger can reach, and it is built on that basis. The user is resolved
server-side, items not in the sync table are refused — otherwise Recombee's `cascadeCreate` would let
anyone mint ghost items in your database — and requests are rate limited per client. CSRF is
deliberately off: a beacon cannot carry a token, and a token in the page would make every page
uncacheable.

## What does it add to my dependency tree?

Nothing. The Recombee client is written against Craft's bundled Guzzle rather than
`recombee/php-api-client`, so the batching, the retries and the connection log are Bee's own and an
SDK bump cannot break your site. The front-end runtime is one hand-written ES5-compatible file —
there is no build step.

## Can I run the historic order backfill more than once?

Yes, and that is by design. Each purchase carries its order's original timestamp, and Recombee keys
interactions on user, item and timestamp — so a second pass is answered with a 409 and refused as a
duplicate rather than doubling everyone's history. The 409s show up in the connection log as the
duplicate protection working, not as failures.

## Does it work without Commerce?

Yes. Commerce is optional and Bee picks it up if it is there. On a content site the catalog is
entries, categories and assets, the funnel is detail views and searches, and the recommendations are
related articles. Install Commerce later and the order funnel wires itself up.

## Two sources both send a price. Is that a problem?

It can be. **Recombee has one property namespace per database**, so two sources that both send
`price` have to agree on its type. Bee checks for that: the catalog screen names any property two
sources disagree about, and the diagnostics check fails on type drift rather than letting the second
source quietly overwrite the first.

## Is Bee free?

Lite is, and it is not a trial. Catalog sync across every element type, detail views and purchases,
recommend-to-user and recommend-to-item, the whole Twig API with element resolution, the connection
log, diagnostics, dry run and the payload preview — for nothing. Detail views and purchases are the
pair that make a recommender work at all, so that is a working integration. Pro is a one-off **$149**
with a **$59/year** renewal.

## What happens if my Pro licence lapses?

Bee drops back to Lite and keeps running. The catalog stays in sync, detail views and purchases keep
being recorded, and the two recommendation calls Lite covers keep answering. The Pro calls return an
empty set and say so in the log, so it is never silent — but nothing breaks, and no page goes down.

## Can I see exactly what is being sent before it goes?

Two ways. The catalog screen has a **payload preview** for any element, built by the same method the
sync uses, so it is the payload rather than an approximation of it. And a **dry-run** switch logs
every request Bee would make without sending any of them, which is the safe way to point a production
install at a new database for the first time.
