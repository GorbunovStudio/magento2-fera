## 1. Typed Snapshot Persistence

- [x] 1.1 Normalize `ReviewSnapshot::setIsTest()` storage to nullable `0`/`1` values while preserving its typed nullable-boolean API.
- [x] 1.2 Refactor `SnapshotRepository` to apply accepted snapshot fields through typed model setters and use Magento model change tracking instead of `setIfChanged()` and `currentValue()`.
- [x] 1.3 Make `SnapshotRepository::save()` return the effective normalized snapshot for new, accepted, stale, and unchanged writes while saving the entity only when data changed.

## 2. Effective Review-Update Reconciliation

- [x] 2.1 Update `ReviewUpdatedWebhook` to compare the previous snapshot with the effective snapshot returned by persistence inside the existing per-review lock.
- [x] 2.2 Build all snapshot-derived review-update message fields from the effective snapshot so rejected stale values cannot enter the queue message.

## 3. Behavioral Verification

- [x] 3.1 Update repository unit tests for effective return values, equal/newer version updates, stale mutable-field rejection with creation-time enrichment, and unchanged numeric/boolean no-op saves.
- [x] 3.2 Add review-updated webhook tests proving stale rejected values do not publish and accepted changes publish effective snapshot content.
- [x] 3.3 Cover the repository's returned effective snapshot across out-of-order writes in the standalone unit suite; database-backed integration coverage belongs to the consuming Magento application.
- [x] 3.4 Run snapshot-related unit tests, static analysis, coding-standard checks, and strict OpenSpec validation; leave database-backed integration testing to the consuming Magento application.
