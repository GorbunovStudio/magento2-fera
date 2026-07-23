## Context

Redmine #36377 requires a weekly product-review dynamics summary for every Fera-connected store. Each store section aggregates all product reviews in its Fera account and then shows a breakdown by product. This change provides the Magento/Fera reporting source and a versioned native SQL query; a separate task will create the Metabase dashboard and Slack delivery.

The shared `feraai/fera` package already stores one current review snapshot for each valid `review_created` and `review_updated` webhook. It currently stores only review content, rating, and media. Its local `created_at` and `updated_at` timestamps describe Magento persistence, not the review's Fera lifecycle. The existing webhook paths already save independently of notification configuration and `state`, so the change extends the snapshot data contract rather than changing the conditions that trigger a save.

Fera is configured as separate accounts. `StoreGroupService` already groups enabled Magento stores by their shared Fera secret and selects one deterministic primary store. The primary store is the canonical reporting key for an account. A Fera review ID is confirmed to be globally unique, so it remains the snapshot identity.

## Goals / Non-Goals

**Goals:**

- Retain the current state of both Fera store reviews and product reviews with the dimensions needed for SQL aggregation.
- Backfill existing Fera reviews once, safely and without producing historical notifications.
- Give a future reporting task a narrow, non-sensitive read model for the report.
- Provide a versioned SQL query with product-review metrics aggregated by store, previous-period deltas, all-time baselines, and product breakdowns.

**Non-Goals:**

- Adding a Magento cron, queue consumer, Slack payload builder, or summary `slack_webhook_url` configuration.
- Querying the Fera API every week to calculate the report.
- Recording immutable historical versions of a review or treating review edits as new weekly reviews.
- Changing existing negative-, positive-, or review-update notification behavior.
- Persisting `is_verified`, raw Fera payloads, review customer data, or Fera credentials for reporting.
- Reporting on Fera `subject = store` reviews.
- Creating or configuring a Metabase dashboard, Metabase database role, Slack App integration, Slack channel, or scheduled dashboard subscription.
- Creating a cross-store `Overall` aggregate.

## Decisions

### 1. Use enriched current snapshots as the reporting source

**Decision:** Extend `fera_review_snapshots` in the shared Fera package with `magento_store_id`, `subject`, product identifiers and name, `state`, `is_test`, `fera_created_at`, and `fera_updated_at`. Keep existing `review_id`, content, rating, media, and Magento lifecycle timestamps. Do not add `fera_store_id` or `is_verified`.

**Rationale:** All report calculations need review-level created date, rating, scope, and product/store grouping. The Fera review ID is globally unique, while `magento_store_id` identifies the report section without an additional Fera Store lookup. Existing local timestamps cannot be reused because they do not mean when Fera created or changed the review.

**Alternatives considered:**

- Query Fera on every report run: rejected because pagination, network requests, and unneeded review-content transfer make it slower and less reliable than local SQL.
- Store aggregate counters only: rejected because arbitrary Metabase periods and product groupings require review-level records.
- Store a full snapshot history: rejected because the agreed weekly metric is based on creation date; edits to old reviews do not belong to the week's new-review counts.

### 2. Retain both review subjects but report only product reviews

**Decision:** Webhooks and backfill retain all Fera review states and both subjects. The versioned SQL query contains only `subject = product` snapshots in its reporting input. Store cards aggregate all product-review rows for the account; product cards group those same rows by stable product ID. Product fields are nullable for retained store reviews.

**Rationale:** The report is about product-review quality and volume. A store section is a grouping of all product reviews belonging to that Fera account, not a calculation over Fera business/store reviews. Retaining both subjects in snapshots preserves complete webhook history and future reporting flexibility, while the SQL query prevents store reviews from contributing to the report.

**Alternatives considered:**

- Backfill only product reviews: rejected because the agreed snapshot backfill also completes historical snapshot coverage for existing store reviews; those rows remain excluded from this report by the SQL query.
- Filter by approval or another Fera state while saving: rejected because the source dataset must retain all states and no report state filter has been agreed.
- Derive dashboard store metrics from Fera store reviews: rejected because the required store section is an aggregate of product reviews.

### 3. Normalize all writes to the Fera account's canonical Magento store

**Decision:** Both webhook and backfill writes resolve the primary store returned by `StoreGroupService` for the configured Fera secret and persist that ID as `magento_store_id`.

**Rationale:** Several Magento store views can share one Fera account. Reading or writing separately for each view would duplicate backfill requests or split a single account's report rows. The same canonical key lets Metabase display one section per Fera account.

**Alternatives considered:**

- Use the receiving webhook route's Magento store ID directly: rejected because it can fragment one Fera account across store views.
- Persist Fera's JWT store claim as the report key: rejected because the report is organized by Magento store/account and backfill has no webhook JWT.

### 4. Preserve creation dates and protect against stale source data

**Decision:** `fera_created_at` is immutable after first acquisition, except that a missing value can be filled later. Upserts compare `fera_updated_at` and do not allow an older source representation to replace newer mutable values.

**Rationale:** The command can fetch a page while a newer webhook arrives. A blind later upsert would otherwise erase the latest data. Keeping the original creation date ensures an old review edit never changes its report period.

**Alternatives considered:**

- Use Magento receipt time for missing Fera timestamps: rejected because it gives an incorrect reporting week.
- Last database write wins: rejected because backfill and webhooks can arrive out of order.

### 5. Use an explicit, restartable one-time backfill command

**Decision:** Add a manual command to the shared Fera package. It iterates canonical Fera account groups, paginates the Fera Private API `reviews` endpoint with `subject=both`, and upserts each record through the snapshot persistence path. It has controls for one account, page size, diagnostic maximum pages, and dry-run validation.

**Rationale:** A Data Patch would issue third-party network calls during `setup:upgrade`, risking a blocked deployment and making an unavailable Fera configuration look like a completed migration. An explicit command permits progress reporting, retry after a transient failure, and a safe idempotent rerun.

**Alternatives considered:**

- Data Patch: rejected because deployment should not depend on a paginated external API operation.
- Reuse the existing CSV import command: rejected because that command sends reviews to Fera, rather than retrieving Fera reviews.
- Trigger historical records through webhook handlers: rejected because it risks publishing Slack messages and couples import to HTTP delivery behavior.

### 6. Keep the reporting source in the versioned SQL query

**Decision:** Do not create a reporting view. The versioned SQL query reads the required reporting columns directly from `fera_review_snapshots`, joins the Magento `store` table for the display name, and filters to product reviews with complete reporting dimensions. Add indexes aligned to filters by canonical store, subject, product, and Fera creation date.

**Rationale:** The reporting task does not use a database view. The query explicitly selects only the fields required for aggregation and does not select review content, media, customer context, review IDs, or integration secrets.

**Alternatives considered:**

- Create a reporting view: rejected because the reporting task does not need an additional database object.
- Build a second materialized reporting table and refresh cron: rejected because it adds another persistence lifecycle without being required by the report.

### 7. Provide a versioned SQL query for a future Metabase report

**Decision:** Store the native MySQL 8 query in `sql-query-36377.sql`. It reads `fera_review_snapshots` joined to `store`, selects only the required reporting columns, filters to product reviews with complete reporting dimensions, accepts period-start and exclusive period-end parameters, calculates the immediately preceding equal-length period, and returns store aggregates followed by product rows. Positive reviews have rating `>= 4`; negative reviews have rating `<= 3`.

For each store, queries aggregate every product-review row in the account; for each product, they aggregate that product's rows. Both levels return past-week average rating and its delta against the comparator, past-week positive and negative counts, and all-time current average rating and count.

**Rationale:** Fera creation date implements the agreed meaning of "changes during the week": new reviews created in that period. The SQL file creates a reviewed, reproducible contract for the later Metabase task without embedding dashboard or Slack configuration in the Magento change.

**Alternatives considered:**

- Filter weekly rows by `fera_updated_at`: rejected because editing an old review must not make it a review of the current week.
- Use a cross-store total: rejected because the report is deliberately read per store.
- Persist positive/negative counters: rejected because rating-based conditional aggregation is simpler and remains adjustable in SQL.

## Risks / Trade-offs

- [Webhook and API payload mapping could drift] → Cover both mappings with sanitized fixtures and validate required source fields before rollout; reject invalid required identity/rating data without logging payload content.
- [A future Metabase connection could expose review data] → The query selects only the required reporting columns; the subsequent reporting task must grant its database role the minimum required access and verify the query column list.
- [Backfill may be interrupted or race live webhooks] → Use source-version-aware idempotent upserts, per-record writes, counters, non-zero failure status, and safe reruns.
- [A deleted Fera review remains in a latest-snapshot table] → This change does not model deletion. Reconciliation or deletion markers require a later explicit scope if Fera's current dataset must exactly match local all-time totals.
- [A later rating edit changes current all-time values] → Accepted: all-time metrics reflect the latest snapshot; weekly inclusion remains based solely on original creation date.

## Migration Plan

1. Implement and release the shared Fera package change through the approved package or Composer-patch workflow; do not edit installed `vendor/` files.
2. Deploy the package update, apply the Fera schema upgrade, and regenerate the Fera declarative schema whitelist.
3. Add and review `sql-query-36377.sql` against the snapshot and store tables on a non-production database.
4. Run the backfill command once in production, verify its per-account counters and remaining-incomplete snapshot count, and rerun it if any account failed or was intentionally limited. Rows absent from Fera's complete response remain excluded from the report rather than receiving invented dates.

Rollback removes use of the SQL artifact. Schema and package rollback must preserve existing snapshot fields used by review-update notifications; new columns can remain unused rather than deleting reporting data during an incident.

## Open Questions

- The operating team must decide whether test reviews (`is_test = true`) are included in the initial dashboard or excluded by a visible Metabase filter. Snapshot collection retains them either way.
