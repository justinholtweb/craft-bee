---
title: Configuration
slug: configuration
order: 20
summary: Every setting — connection, logging, syncing, tracking, consent and recommendations.
---

**Settings → Bee.** Nothing here is marked required, so a fresh install can always save.

## Connection

| Setting | |
|---|---|
| Database ID | Your Recombee database. Use `$BEE_DATABASE_ID`. |
| Private token | Use `$BEE_PRIVATE_TOKEN`. Never in project config. |
| Region | `us-west`, `eu-west`, `ap-se`, `ca-east`. **The wrong one answers 401.** |
| Timeout | Milliseconds for ordinary requests. 10000 by default. |
| Recommendation timeout | Milliseconds for recommendations. 3000, and deliberately short. |
| Retries | For sync requests only. Recommendations never retry. |
| Dry run | Build and log every request; send none. |

## Logging

| Setting | |
|---|---|
| Log mode | *All*, *errors only*, or *none*. Errors by default. |
| Log retention | Days. Garbage collection prunes past it. |

Credentials are never written to the log: the signature and the token live in the query string, and
only the path is recorded.

## Syncing

| Setting | |
|---|---|
| Sync sites | Which sites become items. All of them by default. **Changing this changes every item ID.** |
| Auto sync | Push on element save. On by default. |
| Sync mode | *Queue* or *inline*. Queue by default, collected into one job per request. |
| Batch size | Items per batch request. 500 by default. |
| Delete on delete | Remove items from Recombee when the element goes. |
| Auto-create properties | Create a declared property in Recombee when it is first needed. |

## Tracking

| Setting | |
|---|---|
| Tracking enabled | The master switch for interactions. |
| Inject runtime | Add the front-end runtime to site pages. |
| Track guests | Give guests a cookie ID. Off means only signed-in visitors are tracked. |
| Guest cookie name / days | Defaults to `CraftBeeId` and a year. |
| Merge guests on login | Merge the guest profile into the account. **Pro.** |
| Detail view delay | Seconds before a view counts. 3 by default — firing on load counts every bounce. |

## Consent

| Setting | |
|---|---|
| Consent mode | *Always*, or *only when a consent cookie says so*. |
| Consent cookie name | The cookie your consent platform sets. |
| Consent cookie values | Which values count as granted. `1,true,yes,granted` by default. |

**Unknown consent is treated as not granted.** A visitor who has not answered the banner has not
said yes, and an interaction sent now cannot be recalled later.

## Commerce

| Setting | |
|---|---|
| Track purchases | Record purchases on order completion. |
| Track cart additions | Record genuinely new line items. **Pro.** |
| Purchase variants | Record the variant rather than the product where both are synced. |

## Recommendations

| Setting | |
|---|---|
| Default count | How many to ask for when a call does not say. 6. |
| Filter to live content | Filter every request on `enabled`, `postDate` and `expiryDate`. Leave it on. |
| Rotation rate / time | How hard Recombee should avoid repeating itself, and over what window. |
| Minimum relevance | Recombee's own floor: *low*, *medium*, *high*, or unset. |
| Attribution | Write the ledger and stamp interactions. **Pro.** |
| Attribution retention | Days to keep ledger rows. 90 by default. |

## Overriding in config

Every setting can be set in `config/bee.php` the way any Craft plugin's can, which is how you differ
per environment without differing project config. A setting fixed in config shows as read-only in
the control panel.
