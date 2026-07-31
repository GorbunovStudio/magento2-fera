## Why

Review-update notifications are currently computed from the incoming snapshot before persistence decides whether its mutable values are current enough to accept. An out-of-order webhook can therefore publish stale before-and-after values even though the newer stored snapshot remains unchanged, while the repository also duplicates typed field access through a separate getter dispatch table.

## What Changes

- Make snapshot persistence expose the effective stored snapshot after applying the existing source-version rules.
- Compare review updates and build notification content from the effective stored snapshot, so rejected stale mutable values cannot produce a notification.
- Retain the existing typed review-snapshot setters and Magento entity boundary while removing redundant manual current-value dispatch from the repository.
- Use Magento model change tracking with normalized storage representations to avoid unnecessary saves without bypassing typed setters.
- Add focused coverage for stale, equal-version, newer, and unchanged update processing.
- Preserve webhook endpoints and payloads, locking, backfill behavior, reporting data, media normalization, and database schema.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `fera-review-snapshot-magento-persistence`: Snapshot persistence reports the effective stored state while retaining typed entity mutation and source-version semantics.
- `fera-review-update-notifications`: Review-update comparison and publication use the effective accepted snapshot and suppress notifications for rejected stale mutable values.

## Impact

- Affected code: `SnapshotRepository`, the review snapshot model, `ReviewUpdatedWebhook`, and their unit and integration tests.
- Affected internal contract: snapshot save processing returns the effective stored snapshot rather than an unobserved `void` result.
- Unchanged data and integrations: no schema migration, new dependency, endpoint change, payload change, queue contract change, backfill option change, or reporting SQL change.
