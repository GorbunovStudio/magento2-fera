## 1. Fera reporting snapshot model

- [ ] 1.1 Confirm the sanctioned Fera webhook and List Reviews fixtures cover `created_at`, `updated_at`, `subject`, `is_test`, `product.id`, and nullable product fields without retaining raw payloads in logs.
- [ ] 1.2 Extend the shared Fera snapshot declarative schema with canonical Magento store, subject, product, state, test, and Fera timestamp fields while preserving the globally unique `review_id` identity and local lifecycle timestamps.
- [ ] 1.3 Add reporting indexes for canonical store, subject, product identity, and Fera creation date; add the restricted reporting SQL view containing only product-review rows and excluding review content and sensitive fields.
- [ ] 1.4 Regenerate the Fera declarative schema whitelist from the updated schema source.

## 2. Snapshot ingestion and source-version safety

- [ ] 2.1 Extend the shared snapshot mapper/builder to map the reporting fields from valid Fera webhook/API review data and normalize source timestamps to UTC.
- [ ] 2.2 Extend snapshot repository reads and writes to preserve immutable Fera creation date, retain nullable product context safely, and prevent older `fera_updated_at` values from overwriting newer source data.
- [ ] 2.3 Resolve the canonical Fera-account Magento store for both review-created and review-updated webhook persistence without changing their notification gating or comparison fields.
- [ ] 2.4 Add focused shared-Fera unit tests for product and store review snapshots, disabled-notification persistence, non-pending updates, duplicate delivery, and stale-write protection.

## 3. Historical Fera review backfill

- [ ] 3.1 Add a paginated read operation for Fera Private API List Reviews, authenticated with the canonical account store's configured secret and supporting `subject=both` without state or verification filters.
- [ ] 3.2 Implement the manual review-backfill command with account grouping, optional single-store selection, page-size validation, dry-run and diagnostic maximum-page controls, per-account counters, and non-zero failure reporting.
- [ ] 3.3 Make the command map imported reviews through the enriched snapshot path without invoking webhook handlers or publishing queue/Slack messages.
- [ ] 3.4 Add focused tests for pagination, account grouping, no-notification import behavior, retry-safe idempotency, stale-write protection, and partial account failure reporting.

## 4. Package rollout and query verification

- [ ] 4.1 Release the shared `feraai/fera` fork change or add it through the approved Composer-patch workflow; do not modify installed `vendor/` files directly.
- [ ] 4.2 Consume the approved package change in this Magento repository and run `XDEBUG_MODE=off bin/magento setup:upgrade --keep-generated --quiet`.
- [ ] 4.3 Add `sql-query-36377.sql`, parameterized with inclusive period start and exclusive period end, using only the product-review reporting view for per-store and per-product past-week rating and rating delta, past-week positive/negative counts, and all-time rating/count.
- [ ] 4.4 Verify the reporting view and SQL query on a non-production database, including exclusion of store-review and content rows, period boundaries, all-time baselines, and multiple Fera accounts.
- [ ] 4.5 Run the backfill in production, record only per-account aggregate counters and remaining-incomplete snapshot count, and resolve any failed or intentionally incomplete account.

## 5. Validation and handoff

- [ ] 5.1 Run the focused shared-Fera unit tests, affected Magento/Budsies unit tests, and PHPStan for changed PHP files; manually review changed code against the Magento standards guide.
- [ ] 5.2 Verify that existing negative, positive, and review-update notifications retain their current behavior after the shared package update.
- [ ] 5.3 Document the backfill invocation, reporting-view contract, SQL query parameters and output fields, remaining-incomplete snapshot handling, and recovery steps for a failed import.
