---
title: The catalog
slug: catalog
order: 30
summary: Catalog sources, the properties Bee always sends, the built-in mappers, and how syncing stays cheap.
---

A source is *"these elements become Recombee items, with these properties"*. It answers three
questions: which elements, which of them count, and what Recombee is told.

## Which elements

| Element type | Scoped by |
|---|---|
| Entry | Sections, and entry types within them |
| Category | Category groups |
| Asset | Volumes |
| User | User groups |
| Product *(Commerce)* | Product types |
| Variant *(Commerce)* | Product types |

Leave the scope boxes unchecked to include everything of that type. One database can carry several
sources — the `itemType` property Bee always sends keeps them apart.

### Users

A Users source turns people into items — members to follow, authors, a speaker directory. A few
things work differently from entries:

- **Groups are any-of.** A user in *any* checked group is included, whatever other groups they are
  also in.
- **Only active users count as live.** Suspended, pending and inactive accounts are removed from
  Recombee. A user who is merely locked out after failed logins stays.
- **Status and group changes resync on their own.** Craft changes those without saving the user, so
  Bee listens for activation, suspension, unlocking and group assignment itself.
- **One item per user**, under the site their account belongs to (your primary site). Make sure
  that site is one of the synced sites.
- **What leaves your site:** the user's full name as `title`, their photo as `imageUrl`, and
  whatever properties you map. Map nothing you would not be comfortable sharing with Recombee.
- **Never through the public endpoint.** The anonymous JSON recommendation endpoint skips users, so
  it cannot be used to list your members. Show recommended users with `craft.bee.recommend()` in
  your own templates, where you decide what is printed.
- **Deleting a user removes their item** straight away (with *Delete from Recombee on delete* on),
  and restoring them from the trash sends it again. Their Recombee *user* — the visitor profile and
  its interaction history — is a separate thing that Bee does not delete on its own. An admin can
  delete it by posting its ID (`u` followed by the user's UID) to the `bee/settings/forget-user`
  action, which removes it and every interaction attached to it.
- **A user's entries do not follow them.** Suspending someone changes nothing about their entries,
  so they stay in Recombee. Deleting someone doesn't either: Craft 5 leaves their entries live
  without an author even when you choose *Delete their content*, and transferring them to another
  user rewrites the author without saving the entries, so a mapped `author` property is stale until
  the entry is next saved or `php craft bee/sync/catalog` runs.

## Which of them count

**Live elements only** is on by default and should usually stay on. Disabled, expired and pending
elements are *removed* from Recombee rather than left behind. An item that is still in the database
is an item that can still be recommended, and the first symptom of getting this wrong is a rail
promoting something that was unpublished an hour ago.

## Where sources live

Sources are stored in **project config**, not in a database table, because which entry types feed
the recommender is a deployment concern. They travel with a deploy rather than needing to be
re-clicked in every environment.

The consequence is correct but surprising the first time: sources are **read-only when
`allowAdminChanges` is off**. On production you change them by deploying a changed project config.

Only **admins** can add, change, reorder or delete a source. A source is project config, and a Twig
property is code that runs against every element it syncs, so it gets the same rule as Craft's own
Twig-bearing settings. The *Manage the catalog* permission still runs syncs, re-sends and purges —
including on production, where `allowAdminChanges` is off. If you turn on Craft's
`enableTwigSandbox` (Craft 5.9+), Twig properties are rendered in the sandbox as well.

## Item IDs

| Element | ID |
|---|---|
| Entry 42 | `e42` |
| Product 42 | `p42` |
| Variant 42 | `v42` |
| Category 42 | `c42` |
| Asset 42 | `a42` |

As soon as more than one site is synced the site ID joins them: `e42-s2` — two items, with their own
titles, URLs and properties, which is correct, because they are different things to recommend.

> **Changing which sites are synced changes every item ID.** The old IDs stay behind in Recombee
> along with the interaction history attached to them. Run `bee/sync/catalog --force` and then
> `bee/sync/purge`. Diagnostics will tell you if this has happened.

## Properties Bee always sends

`title`, `url`, `imageUrl`, `itemType`, `siteId`, `sourceHandle`, `slug`, `enabled`, `postDate`,
`expiryDate`, `updatedAt`.

These are not optional and you cannot redeclare them. Every recommendation request is filtered on
`enabled`, `postDate` and `expiryDate`, because the catalog is push-based and can lag.

## Mappings

Each mapping is a Recombee property name, a type, and where to read it from:

| Read from | Value |
|---|---|
| Custom field | A field handle |
| Element attribute | `title`, `slug`, `postDate`, `url`, … |
| Built-in mapper | One of the keys below |
| Twig | An object template, e.g. `{{ object.author.fullName }}` |

For a **set** or an **image list**, a Twig mapping outputs several values in one of two ways: a JSON
array, or one value per line.

```twig
{{ myValues(object)|json_encode }}
{{ myValues(object)|join("\n") }}
```

Commas do not separate values, because a value can contain one. Printing an array directly
(`{{ myValues(object) }}`) does not work: Twig cannot turn an array into text, so the render fails
and the property is left out. The payload preview shows exactly what is sent.

### Built-in mappers

`image`, `images`, `author`, `categories`, `categoryIds`, `tags`, `wordCount`, `readingMinutes`,
`ancestors`, `kind`.

And the Commerce family: `commerce:price`, `commerce:promotionalPrice`, `commerce:onSale`,
`commerce:sku`, `commerce:stock`, `commerce:inStock`, `commerce:productType`,
`commerce:variantCount`, `commerce:variantSkus`, `commerce:minPrice`, `commerce:maxPrice`,
`commerce:weight`, `commerce:productId`.

### Types

Values are coerced to the declared type and **dropped if they cannot be**. A property declared
`double` that receives `"12.00"` would otherwise get the whole item rejected by Recombee.

> **Recombee has one property namespace per database.** Two sources that both send `price` have to
> agree on its type. Bee reports the disagreement on the catalog screen and fails the diagnostics
> check rather than letting the second source silently break the first.

### Values that aren't elements

Tags kept as plain text — a comma-separated `topics` field, say — don't need to become elements, or
Recombee items, to be useful. Send them as a `set` property on the entry:

```twig
{{ object.topics|split(',')|map(t => t|trim)|filter(t => t)|json_encode }}
```

Views and clicks on the entries already teach Recombee which topics a visitor leans towards. The
property then lets you filter (`craft.bee.recommend({ filter: '"ai" in \'topics\'' })`) and, with a
property-based segmentation on `topics` in the Recombee console, ask for the best of one topic with
`craft.bee.segment('ai')` (Pro).

If you do need interactions against a topic itself — a click on a topic page with no entry in
sight — a module can create its own items with `Plugin::getInstance()->getClient()` and record
interactions against their IDs, e.g. `getInteractions()->detailView('topic:ai')`. Bee leaves such
items alone (an ID like `topic:ai` never parses as an element, and `bee/sync/purge` only touches
items Bee synced). Give them an `itemType` of their own and filter on it, or they will be returned
in, and silently shorten, your entry recommendations.

Recombee needs a property to exist before an item can carry it. Press **Sync item properties**, or
run `php craft bee/sync/properties`.

## Syncing

Elements are pushed when saved, removed when unpublished or deleted, and re-sent in bulk from the
console.

**Content fingerprints.** Bee stores a hash of the payload it last sent, per element and per site,
and sends nothing if it matches. That is what makes `bee/sync/catalog` safe in a deploy script: on
an unchanged catalog it is a table scan and no HTTP at all.

**One queue job, not ten thousand.** Element saves are collected during the request and flushed into
a single queue job at the end of it. A job per save would turn a bulk resave of ten thousand entries
into ten thousand jobs.

**Batching.** Bulk syncs go through Recombee's batch endpoint. The response carries one result per
sub-request and Bee reads them back individually, so one bad item is one failure rather than quietly
taking the rest of the batch with it.

| Status | Means |
|---|---|
| `synced` | Sent and acknowledged. The fingerprint is current. |
| `pending` | Queued, not yet sent. |
| `failed` | Recombee refused it. The reason is on the row and in the log. |
| `deleted` | Removed, because it stopped matching a source or was unpublished. |

### Console

```sh
php craft bee/sync/catalog                    # sync every source
php craft bee/sync/catalog --force            # re-send even unchanged items
php craft bee/sync/catalog --source=Products  # one source
php craft bee/sync/catalog --dry-run          # build and log, send nothing
php craft bee/sync/properties                 # create declared item properties
php craft bee/sync/element 1234               # one element
php craft bee/sync/purge                      # delete excluded and failed items
```

Force a re-sync after changing a property mapping, after changing which sites are synced, or after
pointing Bee at a different database. It is not destructive: `Set Item Values` is an upsert.

## Previewing a payload

The catalog screen has a **payload preview** for any element, built by the same method the sync, the
save handler, the queue job and the console all use — so it is the payload rather than an
approximation of it. Use it before a first sync.
