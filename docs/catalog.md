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
