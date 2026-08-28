# Release Notes for Bee

## 5.0.0 — 2026-08-28

Initial release.

### Added

- **Catalog sync** for entries, categories, assets, Commerce products and Commerce variants, driven
  by catalog sources stored in project config. Property mappings read from custom fields, element
  attributes, built-in mappers or inline Twig, coerced to the declared Recombee type.
- **Automatic sync on save**, on the queue or inline, with a content fingerprint so an unchanged
  element costs nothing, and removal when an element is unpublished or deleted.
- **Bulk sync** from the console or the control panel, batched and resumable.
- **Interaction tracking** — detail views with real dwell time, purchases, cart additions,
  bookmarks, ratings and view portions — through one funnel with consent gating, de-duplication and
  a fail-open guarantee.
- **A dependency-free front-end runtime** that records views, measures dwell time, and carries a
  recommendation's `recommId` across the navigation to the interaction it produced.
- **Recommendations and search** — to a user, to an item, to an item segment, and Recombee's
  personalised search — returning Craft elements in Recombee's order.
- **An attribution ledger**, so Recombee can report on and learn from its own recommendations.
- **The Commerce order funnel**: purchases on order completion, cart additions on genuinely new line
  items, guest-to-account merging at checkout, and a historic order backfill that is safe to run
  twice.
- **Diagnostics** — twelve checks, each with a fix — in the control panel and as a console command
  that exits non-zero, so it can gate a deploy.
- **A connection log** with both payloads and no credentials, and a **dry-run** mode that builds and
  logs every request without sending it.
- **Two editions**: Lite (free) and Pro.
