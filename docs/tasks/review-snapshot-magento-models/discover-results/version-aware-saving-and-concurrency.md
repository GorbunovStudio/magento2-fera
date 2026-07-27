# Version-aware saving and concurrency — discovery

## Inspected sources

- `Services/ReviewSnapshot/SnapshotRepository.php`
- `Services/ReviewSnapshot/SnapshotBuilder.php`
- `Model/ReviewUpdatedWebhook.php`
- `Model/ReviewCreatedWebhook.php`
- `Console/Command/BackfillReviewsCommand.php`
- `etc/db_schema.xml`
- `Test/Unit/Services/ReviewSnapshot/SnapshotRepositoryTest.php`
- `Test/Unit/Model/ReviewUpdatedWebhookTest.php`

## Evidence and constraints

- The current `insertOnDuplicate` is atomic relative to unique `review_id`.
- Mutable fields apply when stored `fera_updated_at` is null or incoming `fera_updated_at` is equal/newer. A non-null stored timestamp rejects null or older incoming versions.
- `fera_created_at` is independent: it is populated only when the stored value is null and incoming value exists, including from an otherwise stale snapshot.
- Builder timestamps are normalized UTC strings in `Y-m-d H:i:s`, so consistent comparison is available.
- `ReviewUpdatedWebhook` holds a lock from loading the previous snapshot through comparison, save, notification decision and publish. The key contains the actual store ID and is not shared with created or backfill.
- Created and backfill only call `save()`. A model flow of collection read, then model save without shared coordination introduces duplicate-key and lost-update races.

## Viable approaches

1. **Shared application-level mutex by global review ID plus model/resource/collection persistence** — recommended. The locked operation loads with a collection, applies version rules to a model, and saves through the resource model. Created, updated and backfill share it.
2. **Custom atomic versioned resource-model upsert** — retains conditional `insertOnDuplicate` in the resource layer. It still requires a shared lock for update notification ordering and conflicts with the objective to remove direct SQL persistence.

## Recommended implementation shape

Introduce an internal `Services/ReviewSnapshot/ReviewSnapshotLock` over `LockManagerInterface`, with a key `fera_review_snapshot_` plus SHA-256 of the global review ID and a ten-second wait. A failed webhook lock is retryable 503; a failed backfill lock fails the current account through its existing error path.

Inside the lock, `SnapshotRepository` owns version evaluation and model persistence. It must save only changed models. `ReviewUpdatedWebhook` must use the same lock for its current load, compare, save and publish region.

The only outstanding product decision is stale-update notification behavior. Current code can compare and publish an old payload even though persistence rejects it. Preserving that behavior avoids an external change; suppressing it makes source-version semantics strict but changes notification behavior.

## Confidence

High for mutex and version-rule requirements. Medium for stale-update notification behavior because current specifications do not decide that conflict.
