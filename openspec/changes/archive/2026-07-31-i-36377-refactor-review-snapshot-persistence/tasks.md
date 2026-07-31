## 1. Review Snapshot Entity

- [x] 1.1 Add the review snapshot data interface, model, resource model, and collection for `fera_review_snapshots`.
- [x] 1.2 Add `addIncompleteReportingDataFilter()` to the collection with the existing reporting-completeness predicate.

## 2. Entity-Based Snapshot Persistence

- [x] 2.1 Refactor `SnapshotRepository` to load and persist review snapshot entities through the collection, factory, and resource model.
- [x] 2.2 Preserve media conversion, current array mapping, source-version rules, and immutable Fera creation timestamp behavior.

## 3. Shared Snapshot Writer Coordination

- [x] 3.1 Add the global per-review `ReviewSnapshotLock` service over Magento's lock manager.
- [x] 3.2 Migrate review-created and review-updated webhook persistence to the shared lock while preserving notification behavior.
- [x] 3.3 Migrate non-dry-run backfill persistence to the shared lock while preserving dry-run and account recovery behavior.

## 4. Validation

- [x] 4.1 Replace SQL implementation assertions with entity, repository, lock, and producer behavior tests.
- [x] 4.2 Run relevant unit tests, static analysis, and available integration validation; resolve any failures.
