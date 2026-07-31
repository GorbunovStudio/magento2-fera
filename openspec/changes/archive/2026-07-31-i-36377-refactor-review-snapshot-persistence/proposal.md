## Why

Review snapshots currently bypass the module's standard Magento persistence pattern and access the database adapter directly. The refactor must move that access to Magento entities without weakening source-version handling, concurrency guarantees, webhook behavior, backfill behavior, or reporting.

## What Changes

- Add a native Magento review-snapshot entity: data interface, model, resource model, and collection.
- Refactor `SnapshotRepository` to persist through that entity while preserving its existing array contract and media conversion.
- Replace the direct SQL version-aware upsert with model persistence protected by a shared, global per-review lock.
- Move the incomplete-reporting-data predicate into the review snapshot collection.
- Migrate created, updated, and backfill producers to the shared lock without changing their public webhook, notification, or CLI contracts.
- Replace SQL-implementation unit assertions with behavior and integration coverage.

## Capabilities

### New Capabilities

- `fera-review-snapshot-magento-persistence`: Persist review snapshots through native Magento entity components while retaining current data, version, concurrency, and producer behavior.

### Modified Capabilities

- None.

## Impact

- Affected code: review snapshot services, webhook handlers, backfill command, and snapshot-related tests.
- Affected data: the existing `fera_review_snapshots` table is reused without a schema migration.
- Affected dependencies: the existing Magento lock manager becomes the shared coordination mechanism for all snapshot writers.
- Unchanged integrations: webhook payloads and endpoints, notification rules, backfill options and output, and the versioned reporting SQL artifact.
