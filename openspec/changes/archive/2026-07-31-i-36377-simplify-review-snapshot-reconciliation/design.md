## Context

`SnapshotRepository` owns source-version reconciliation for normalized snapshot arrays and persists them through the Magento review-snapshot entity. `ReviewUpdatedWebhook` currently loads the previous snapshot, compares it with the incoming snapshot, and only then calls the repository. Because the repository can reject stale mutable values, the comparison and notification payload can describe state that was never stored.

The repository also avoids unnecessary writes by comparing each incoming value through `setIfChanged()` and a field-to-getter `match`, even though the review-snapshot model already exposes typed setters and Magento's `AbstractModel` tracks data changes. The model must continue to absorb database scalar representations without weakening its typed public accessors.

## Goals / Non-Goals

**Goals:**

- Make update detection and notification content agree with the source-version result persisted for the review.
- Suppress review-update notifications when a stale payload contributes no accepted selected-field change.
- Preserve typed review-snapshot setters while deleting redundant repository current-value dispatch.
- Retain no-op save avoidance and all existing locking, reporting, media, webhook, and backfill behavior.

**Non-Goals:**

- Replace the normalized snapshot array with a new DTO or value-object hierarchy.
- Add a generic mapper, repository abstraction, or persistence result class.
- Move locking into the repository or change the shared lock duration or identity.
- Change the database schema, reporting SQL, queue message contract, webhook payloads, or backfill output.

## Decisions

### Return the effective snapshot from persistence

Change `SnapshotRepository::save()` from an unobserved `void` result to the normalized snapshot represented by the model after source-version rules have been applied. Newer and equal-version mutable values appear in the result; rejected stale mutable values remain at their stored values; a missing Fera creation timestamp may still be filled by an older snapshot.

`ReviewUpdatedWebhook` will keep the existing previous-snapshot read inside the shared lock, save the incoming snapshot, and compare the previous snapshot with the returned effective snapshot. Notification fields sourced from snapshot state will also use that effective snapshot.

This is preferred over returning only a boolean because persistence has two independent outcomes: mutable fields can be rejected while a missing creation timestamp is accepted. Returning the effective state expresses both without a new result type or a second database read.

### Apply accepted values through typed model setters

Keep `ReviewSnapshotInterface` and its typed setters. `SnapshotRepository` will call those setters for fields allowed by the existing source-version policy and use the model's data-change flag to decide whether the resource model needs to save.

Normalize nullable boolean storage in `setIsTest()` to `null`, `0`, or `1`. Combined with Magento's numeric-value normalization for loaded models, this lets model change tracking compare database scalars with typed setter inputs consistently. Other numeric setters retain their current typed input and Magento conversion behavior.

This removes `setIfChanged()` and `currentValue()` without using bulk `setData()` or `addData()`. Bulk assignment was rejected because it bypasses the typed setter boundary. A typed snapshot DTO was rejected because the demonstrated problem can be solved without changing all snapshot producers and consumers.

### Preserve the existing critical section

Previous-state loading, reconciliation, comparison, notification decision, and publication remain inside `ReviewSnapshotLock`. Created-webhook and backfill writers continue to ignore the repository return value. This change does not weaken concurrency ordering or expand lock ownership.

## Risks / Trade-offs

- [A setter stores a representation that differs from the database scalar for the same value] → Normalize boolean storage explicitly and cover unchanged numeric and boolean snapshots with repository tests.
- [A stale payload fills `fera_created_at` and is mistaken for a review-content update] → Compare only the existing selected notification fields between the previous and effective snapshots.
- [Callers assume `save()` is `void`] → Existing created-webhook and backfill callers may safely ignore the returned array; update mocks and static-analysis expectations.
- [Notification context still reads non-snapshot fields from the incoming payload] → Limit this change to persisted snapshot fields; optional customer, order, and product context is not versioned in snapshot storage.

## Migration Plan

1. Deploy the code without a data or configuration migration.
2. Run the standalone unit suite and static-analysis checks before release. Database-backed integration checks belong to the consuming Magento application.
3. Monitor review-update queue volume and webhook retry errors after deployment.
4. Roll back the code if needed; stored rows and queue contracts remain compatible.

## Open Questions

None.
