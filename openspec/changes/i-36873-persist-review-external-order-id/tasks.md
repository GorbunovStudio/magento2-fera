## 1. Declarative schema and snapshot entity

- [x] 1.1 Add nullable `external_order_id` and its non-unique lookup index to `fera_review_snapshots` in `etc/db_schema.xml`, then regenerate `etc/db_schema_whitelist.json` from the consuming Magento application.
- [x] 1.2 Extend `ReviewSnapshotInterface` and `ReviewSnapshot` with the external-order field constant and nullable typed getter/setter.

## 2. Shared snapshot ingestion and reconciliation

- [x] 2.1 Add normalized top-level `external_order_id` to `SnapshotBuilder`'s snapshot contract, using nullable-string extraction for Fera webhook and List Reviews payloads.
- [x] 2.2 Extend `SnapshotRepository` mapping for new rows, accepted version updates, and returned effective snapshots to retain the external order ID.
- [x] 2.3 Implement reconciliation that fills a stored `NULL` external order ID from a non-empty stale payload but neither overwrites a non-empty association from a stale payload nor clears it when the incoming field is absent.
- [x] 2.4 Keep `SnapshotComparator`, webhook notification decisions, queue payloads, and `incomplete_snapshots` semantics unchanged; update PHPStan snapshot-array declarations where the new required key is used.

## 3. Historical enrichment

- [x] 3.1 Extend sanctioned created, updated, and List Reviews fixtures with representative external order IDs, including an omitted-value case.
- [x] 3.2 Verify `fera:reviews:backfill` uses the unchanged shared builder/repository path to enrich existing `NULL` associations without duplicate rows, notifications, or CLI-contract changes.
- [x] 3.3 Document the release runbook: deploy schema with `setup:upgrade`, execute the non-dry-run existing backfill, and inspect remaining null associations without changing the reporting-incomplete counter.

## 4. Verification

- [x] 4.1 Add unit coverage for builder normalization, typed entity accessors, repository insert/read/equal-or-newer update, stale `NULL` enrichment, and preservation of a non-empty association on omitted or stale input.
- [x] 4.2 Extend backfill and webhook tests to cover external order propagation while confirming no review-update comparison or notification behavior changes.
- [ ] 4.3 Run focused PHPUnit tests, PHPStan, coding-standard checks, and OpenSpec validation; run a consuming-Magento integration check for the deployed column, index, and backfill persistence behavior.
