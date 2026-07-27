# Producer migration and validation — discovery

## Inspected sources

- `Model/ReviewCreatedWebhook.php`, `Model/ReviewUpdatedWebhook.php`
- `Console/Command/BackfillReviewsCommand.php`
- `Services/ReviewSnapshot/SnapshotRepository.php`, `SnapshotBuilder.php`, `SnapshotComparator.php`, `MediaNormalizer.php`
- `Api/ReviewCreatedWebhookInterface.php`, `Api/ReviewUpdatedWebhookInterface.php`
- Snapshot-related unit tests, `README.md`, `composer.json`, `phpstan.neon`

## Evidence and constraints

- `SnapshotRepository` has exactly three production consumers: created calls `save`, updated calls get/compare/save, backfill calls `save` per record and `countIncomplete` for the final summary.
- REST contracts remain `execute(): void`; no webhook payload or endpoint change is necessary.
- Backfill options, dry-run behavior, account-level error continuation and aggregate summary are existing contracts.
- `saved` currently counts successful calls to repository, not physically changed columns; stale records must remain counted as processed save attempts.
- The incomplete predicate must remain: `fera_created_at IS NULL OR magento_store_id IS NULL OR subject IS NULL OR (subject = 'product' AND fera_product_id IS NULL AND external_product_id IS NULL)`.
- The nested predicate needs a collection method `addIncompleteReportingDataFilter()` that owns the grouped select condition and is evaluated by `getSize()`; a simple series of `addFieldToFilter()` calls cannot express it without changing the logic.
- Existing repository test asserts SQL expressions; builder, comparator and media-normalizer tests already cover the stable array contract.

## Migration and validation recommendation

- Created builds the snapshot, calls persistence inside the common lock, then retains current notification threshold and message behavior. Lock acquisition failure becomes retryable 503.
- Updated replaces its direct `LockManagerInterface` use and store-scoped name with `ReviewSnapshotLock`, retaining its load/compare/save/decision/publish lock boundary and its REST behavior.
- Backfill locks only actual per-review persistence; dry-run takes no lock. A lock error stays inside the existing per-account error path, failing that account while others continue.
- Keep `getByReviewId(): ?array` and `countIncomplete(): int`; change internal `save()` to `void` because callers do not observe the current affected-row result.
- Add model/repository/lock/producer unit coverage and a Magento integration test for persisted version rules and the collection count. Remove only the SQL-string assertion test.

## Validation constraint

This package has no local `vendor/` or `phpunit.xml`; PHPUnit must run from the consuming Magento application root. PHPStan can run from the package once dependencies are present.

## Confidence

High for affected consumers and preservation of external contracts. Medium only for the exact PHPUnit invocation because bootstrap lives in the consuming Magento installation.
