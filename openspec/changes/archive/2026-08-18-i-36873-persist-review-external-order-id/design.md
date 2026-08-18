## Context

`fera_review_snapshots` is the shared, latest-state projection of Fera reviews. `SnapshotBuilder` builds one normalized array for review-created webhooks, review-updated webhooks, and `fera:reviews:backfill`; `SnapshotRepository` persists it under the existing review-level lock and source-version rules.

Fera sends `external_order_id` both in webhooks and in `GET /v3/private/reviews`, but the builder and snapshot entity currently discard it. The existing notification handlers already interpret that ID as Magento's numeric `sales_order.entity_id`, while historical snapshots have no durable order association.

## Goals / Non-Goals

**Goals:**

- Retain Fera's external order identifier with each review snapshot and support efficient lookup by it.
- Use the existing shared builder and backfill command to populate both new and historical snapshots.
- Preserve source-version protection for review content while allowing a valid historical API response to fill a missing order association.
- Preserve existing webhook, queue, notification, comparison, and backfill CLI contracts.

**Non-Goals:**

- Add a foreign key or a new `order_id` field referring to `sales_order`.
- Resolve, validate, or transform the external value into a Magento increment ID during snapshot persistence.
- Change the product-review reporting view, the meaning of `incomplete_snapshots`, or add a new backfill CLI option.
- Backfill records that Fera does not return from `GET /v3/private/reviews`.

## Decisions

### Persist the Fera source identifier as nullable indexed text

Add nullable `external_order_id VARCHAR(255)` and `FERA_REVIEW_SNAPSHOTS_EXTERNAL_ORDER_ID_IDX` to the declarative schema and whitelist. The field remains a string because it is an external Fera contract even though the current Magento notification handlers recognise numeric values as `sales_order.entity_id`.

The index supports the intended lookup from an order to its reviews. The index is non-unique because a single order can receive multiple reviews. A database foreign key was rejected: the source ID can be absent or external to the local order table, and constraining it would incorrectly make Fera ingestion dependent on local order retention.

### Extend the single normalized snapshot path

Add `external_order_id` to the `SnapshotBuilder` PHPStan type, `ReviewSnapshotInterface`, `ReviewSnapshot` typed accessors, and repository-to-model mapping. `SnapshotBuilder` will extract the top-level Fera value through the existing nullable-string normalization: trim a non-empty string, convert numeric scalars to strings, and map absent or blank values to `null`.

No webhook handler needs a direct persistence change because both use the builder. The current review-update comparator remains limited to review content and media, so order-association changes cannot emit a review-change notification.

### Reconcile order association independently from stale review content

For a new row, the repository writes the normalized order ID. For an existing row, an equal or newer Fera source version may replace the stored non-empty association with a non-empty incoming one. An absent or blank incoming value never clears an existing non-empty association.

Additionally, when the stored association is `NULL` and the incoming value is non-empty, the repository fills it even if the input's `fera_updated_at` is older than the stored review version. This narrowly scoped enrichment lets a full historical backfill repair legacy rows without allowing stale heading, body, rating, media, product, state, or timestamp data to overwrite the newer webhook state.

### Reuse backfill for data migration

No Magento data patch can reconstruct the missing association locally; Fera is the source of truth. After the declarative schema is deployed, rerunning `fera:reviews:backfill` pages through the confirmed List Reviews field and uses the same repository reconciliation path. The command remains idempotent, retains its account-level recovery and dry-run semantics, and does not publish webhook, queue, or Slack messages.

The existing `incomplete_snapshots` counter is deliberately unchanged because it measures reporting completeness and a review can validly lack an order association. Operators verify enrichment with a targeted query for `external_order_id IS NULL` if needed.

## Risks / Trade-offs

- [A Fera payload omits `external_order_id`] → Normalize it to `NULL` and never erase an already persisted non-empty association.
- [A historical API page is older than a concurrent webhook] → Permit only the `NULL` to non-empty association fill outside normal source-version acceptance; retain all newer review state.
- [An external ID is not a local Magento order entity ID] → Preserve the source string without a foreign key; consumers that require a local order continue to validate it explicitly.
- [A historical review is not returned by the List Reviews endpoint] → Leave the association null rather than infer it from local timestamps or notification data.
- [The new lookup is used at scale] → Add the dedicated non-unique index with the column rather than relying on a table scan.

## Migration Plan

1. Deploy the declarative schema, entity mapping, and tests; run `bin/magento setup:upgrade` from the consuming Magento application so the nullable column and index are created.
2. Run the focused unit tests and static analysis, then verify the column and index in a Magento integration environment.
3. Run `bin/magento fera:reviews:backfill` for all configured accounts, or scope it with `--store-id`; do not use `--dry-run` for the enrichment run.
4. Inspect aggregate command output and, where required, query remaining null `external_order_id` values by account or review subject.
5. If application code must be rolled back, retain the additive nullable column and index. They are backward-compatible; no destructive schema rollback is required.

## Open Questions

None. Fera's List Reviews response has been confirmed to include `external_order_id`.
