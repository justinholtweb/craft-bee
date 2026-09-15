---
title: Interactions
slug: interactions
order: 50
summary: Recording the funnel, who a visitor is, consent, caching, and the public tracking endpoint.
---

A recommender is only as good as what it has watched people do. Bee sends the whole funnel, and the
visitor behind it is resolved on the server — never read off the request.

## The usual case

Tell the runtime what the page is about:

```twig
<body {{ craft.bee.pageItem(entry) }}>
```

That is the whole integration for detail views. The runtime will:

- record a detail view once the visitor has actually stayed — **three seconds by default**, because
  firing on load counts every bounce and every prefetch as interest;
- send the final dwell time on unload;
- carry the `recommId` from whatever recommendation they clicked to get here.

The runtime is one hand-written ES5-compatible file. There is no build step and nothing to add to
your bundle.

## From JavaScript

```js
Bee.cartAdd('v1234', 2, 19.99);
Bee.bookmark('e42');
Bee.rate('e42', 0.5);      // Recombee's scale runs -1 … 1
Bee.portion('e42', 0.75);
Bee.stop();                // consent declined after load
```

## From Twig, server-side

```twig
{% do craft.bee.trackView(entry) %}
{% do craft.bee.trackCartAdd(variant) %}
{% do craft.bee.trackPurchase(variant) %}
{% do craft.bee.trackBookmark(entry) %}
{% do craft.bee.trackRating(entry, craft.bee.stars(4)) %}   {# 4 out of 5 → 0.5 #}
{% do craft.bee.trackViewPortion(entry, 0.75) %}
```

`craft.bee.stars()` exists because Recombee's rating scale runs from −1 to 1 and almost every site's
runs from 1 to 5. Passing a raw `4` would be clamped to the top of the scale and tell Recombee
something you did not mean.

## The kinds

| Kind | Lite | Pro |
|---|---|---|
| Detail view | ✓ | ✓ |
| Purchase | ✓ | ✓ |
| Cart addition | — | ✓ |
| Bookmark | — | ✓ |
| Rating | — | ✓ |
| View portion | — | ✓ |

Detail views and purchases are the pair that make a recommender work at all, which is why they are
the pair in Lite.

**Interactions are typed.** Recombee's endpoints take different fields, and sending the wrong one is
a 400 rather than an ignored field — a `duration` on a purchase, for instance. Bee builds each kind
with only the fields that kind accepts.

**A 409 is not a failure.** Recombee keys interactions on user, item and timestamp, so a repeat is
refused as a duplicate. That is the duplicate protection working, and it is why the historic order
backfill is safe to run twice.

## Who a visitor is

| State | Recombee user ID |
|---|---|
| Signed in | `u{user uid}` |
| Guest | `g{random token}`, in a first-party cookie |
| Guest signs in, or checks out | The two are merged in Recombee **(Pro)** |

Nothing personal is stored against a guest ID. It is a random token in a cookie whose name and
lifetime you control.

**Why the merge matters.** Most of a visitor's history happens before they have an account. Throwing
it away at the moment they sign up throws away the browsing that made them decide.

> Every call accepts a `userId` option, for server-side jobs where there is no session — an email
> send, a backfill, a report. **Never populate it from a request parameter.** It would let a stranger
> read anyone's personalised recommendations and record interactions as them.

## Consent

Set **Consent** to *"Only when a consent cookie says so"* and name the cookie your consent platform
sets, plus the values that count as granted.

**Unknown consent is treated as not granted.** A visitor who has not answered the banner has not
said yes, and an interaction sent now cannot be recalled later.

Consent is read from the cookie *by the runtime, in the browser* — not during the page render.
Reading it server-side would make every page carrying the runtime vary by cookie, and therefore
uncacheable. If consent is declined after load, call `Bee.stop()`.

## Caching

Bee writes nothing visitor-specific into a page:

- the runtime's configuration is page-specific, not visitor-specific;
- the consent answer is read from a cookie in the browser;
- the guest cookie is minted by the tracking endpoint rather than the page render.

So pages carrying the runtime stay cacheable. The one exception is `craft.bee.recommend()`, and
unavoidably so — a personalised block is personal. Keep it out of a cached fragment, or cache it per
user.

## The tracking endpoint

It is the only part of Bee a stranger can reach, and it is built on that basis:

- the user is resolved server-side, never from the request;
- items that are not in the sync table are refused — otherwise Recombee's `cascadeCreate` would let
  anyone mint ghost items in your database;
- requests are rate limited per client;
- CSRF is **deliberately off**: a beacon cannot carry a token, and a token in the page would make
  every page uncacheable.

## Excluding people

```php
use justinholtweb\bee\events\InteractionEvent;
use justinholtweb\bee\services\Interactions;
use yii\base\Event;

// Don't track staff.
Event::on(Interactions::class, Interactions::EVENT_BEFORE_RECORD, function(InteractionEvent $e) {
    $e->isValid = !Craft::$app->getUser()->getIsAdmin();
});
```
