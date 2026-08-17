## Why

Review snapshots retain the product context but discard Fera's `external_order_id`. As a result, a stored review cannot be reliably associated with the Magento order that generated it, and historical rows lack the data needed for that association.

## What Changes

- Add a nullable, indexed `external_order_id` column to `fera_review_snapshots` and its declarative-schema whitelist.
- Extend the review snapshot entity, normalized snapshot contract, shared builder, and repository so review-created, review-updated, and Fera API backfill records retain Fera's external order ID.
- Preserve an already stored non-empty order link when an API payload omits the field; allow backfill to fill a missing order link without letting stale review content replace newer review state.
- Reuse `fera:reviews:backfill` to enrich existing snapshots from `GET /v3/private/reviews`, which is confirmed to return `external_order_id`; do not add a new command or publish notifications during history import.
- Add focused schema, persistence, mapper, and backfill coverage.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `fera-review-snapshot-magento-persistence`: The normalized snapshot entity and source-version persistence rules retain an external order link.
- `fera-review-reporting-snapshots`: Shared webhook/API ingestion and the historical backfill retain Fera's external order ID for existing and new snapshots.

## Impact

- Affected schema: `fera_review_snapshots` gains a nullable `external_order_id` source identifier and lookup index.
- Affected code: `ReviewSnapshotInterface`, `ReviewSnapshot`, `SnapshotBuilder`, `SnapshotRepository`, the shared review fixtures, and snapshot/backfill tests.
- Affected operations: after `setup:upgrade`, operators rerun the existing `fera:reviews:backfill` command to enrich historical records.
- Unchanged integrations: webhook endpoints and payloads, queue messages, notification decisions, review comparison fields, backfill options, and reporting SQL remain unchanged.
