# Release Notes for Bee

## 5.0.1 — 2026-09-28

### Security

- Catalog sources can now only be added, changed, reordered or deleted by admins, and only where
  `allowAdminChanges` is on. A user with the *Manage the catalog* permission could previously add a
  Twig property that read secrets such as the security key and returned them through the payload
  preview. That permission still runs syncs, re-sends and purges, and the sync buttons now show on
  production too. On Craft 5.9+ with `enableTwigSandbox` on, Twig properties are also rendered in
  the sandbox.
- The payload preview now checks that the user can view the element, so it can no longer read
  entries from sections they have no access to.
- The public tracking endpoint no longer accepts purchases. Purchases are recorded on the server by
  the Commerce integration or `craft.bee.trackPurchase()`, so a script can no longer fake them to
  push an item up everyone's recommendations.
- The tracking endpoint's rate limit is now per IP address rather than per IP and User-Agent, which
  a client could rotate, and it can no longer be bypassed by sending requests in parallel.
- Recommended elements are resolved with their default status, so a disabled or future entry that
  Recombee still recommends is no longer returned by the public recommend endpoint.

### Changed

- The control panel's inline styles and hard-coded colours were replaced with Craft's CP tokens and
  classes, so Bee's screens follow the CP theme.

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
