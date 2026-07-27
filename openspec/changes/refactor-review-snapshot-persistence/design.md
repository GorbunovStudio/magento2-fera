## Context

`SnapshotRepository` currently uses `ResourceConnection` and adapter-level reads, upserts, and counts. Other persisted Fera entities use the module's standard Magento data interface, model, resource model, and collection pattern. The snapshot table already has the required technical primary key and globally unique `review_id`.

The current atomic upsert also protects snapshot data from an out-of-order Fera event. Replacing it with a collection lookup followed by a model save requires shared coordination across created, updated, and backfill writers. `review_updated` already has a lock around its comparison and notification flow, but its key is store-scoped and is not shared by the other writers.

## Goals / Non-Goals

**Goals:**

- Persist review snapshots through native Magento entity components.
- Preserve the existing snapshot array contract, JSON media boundary, source-version rules, and reporting-incomplete predicate.
- Serialize all snapshot writers by globally unique review ID while retaining the current updated-webhook notification ordering.
- Preserve webhook endpoints and payloads, notification rules, backfill options, counters, and reporting SQL.

**Non-Goals:**

- Change the `fera_review_snapshots` schema or migrate existing rows.
- Alter notification eligibility, including the existing behavior for a stale updated-webhook payload.
- Introduce a new public service contract or Web API.
- Change the reporting SQL artifact or its result set.

## Decisions

### Use a Magento entity with a repository adapter

Add `ReviewSnapshotInterface`, `ReviewSnapshot`, its resource model, and its collection. The model represents storage values; `media` is a JSON string in the entity. `SnapshotRepository` remains the producer-facing adapter that maps the existing normalized media array to and from the entity, so `SnapshotBuilder` and `SnapshotComparator` retain their array contract.

This matches the existing `FeraProduct` and `FeraOrder` patterns and keeps persistence mapping in one place. Injecting models directly into webhook and command code would duplicate mapping and version logic.

### Enforce source version under a shared lock

Add `ReviewSnapshotLock` over `LockManagerInterface`. Its name uses a stable prefix and SHA-256 of the globally unique review ID, with the existing ten-second timeout. It releases the lock in `finally`.

Inside that lock, `SnapshotRepository` finds the entity through a collection, applies the current source-version policy, and saves the model only if fields changed. Mutable fields and `fera_updated_at` change when the stored source timestamp is absent or the incoming timestamp is equal/newer. `fera_created_at` is populated only when the stored value is absent. A custom SQL upsert in the resource model was rejected because it would retain the direct SQL persistence this refactor removes and would not by itself protect updated-webhook compare/publish ordering.

### Preserve producer boundaries

Created wraps persistence in the common lock and then continues current notifications. Updated uses the common lock over previous snapshot read, comparison, save, notification decision, and publish. Backfill takes the lock only for non-dry-run persistence; a lock failure is handled by its existing per-account error boundary. A failed webhook lock is retryable HTTP 503.

### Encapsulate reporting completeness in the collection

`ReviewSnapshot\Collection::addIncompleteReportingDataFilter()` owns the existing grouped completeness predicate. `SnapshotRepository::countIncomplete()` calls the filter and `getSize()`. The collection is the appropriate Magento query boundary for this condition; the repository no longer builds a query itself.

## Risks / Trade-offs

- [The shared lock backend is unavailable across CLI and web nodes] → Use Magento's already configured `LockManagerInterface`; deployment validation confirms the backend is shared.
- [Model read and save fails after a lock is acquired] → Release in `finally`; preserve existing exception propagation and account-level backfill recovery.
- [Typed model accessors receive adapter string values for decimals and booleans] → Normalize nullable and scalar values in accessors and cover them with tests.
- [Stale update can still publish an update notification] → Preserve this existing behavior deliberately; notification semantics are outside the refactor.

## Migration Plan

1. Deploy the code without a schema migration.
2. Run unit, integration, and static-analysis checks before release.
3. Observe webhook retries and backfill account summaries after deployment.
4. Roll back the code if needed; the table and stored rows remain compatible with the preceding release.

## Open Questions

None.
