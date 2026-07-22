## Why

Individual Fera review notifications do not show the weekly change in product-review quality and volume across Petsies, Budsies, and other connected Fera stores. The team needs a single weekly Metabase report in Slack that aggregates product reviews per store and then breaks them down by product.

## What Changes

- Enrich the latest Fera review snapshots with the reporting dimensions and source timestamps needed to calculate store- and product-level review metrics in SQL.
- Preserve snapshots for every valid `review_created` and `review_updated` webhook without filtering by Fera review state, and backfill snapshots for reviews that predate snapshot collection.
- Expose a least-privilege reporting dataset for Metabase that excludes review text, media, customer data, and secrets.
- Add the versioned native SQL query needed by a future Metabase report to aggregate product-review metrics per store and then per product for the completed week, the previous completed week, and all time.

## Capabilities

### New Capabilities

- `fera-review-reporting-snapshots`: Maintain a safe, queryable local dataset of current Fera store and product reviews, including an idempotent backfill for pre-existing reviews.
- `fera-weekly-review-sql-query`: Provide the native SQL query that a future Metabase report can use to present weekly product-review dynamics by Fera store and product.

### Modified Capabilities

- None.

## Impact

- Shared `feraai/fera` package/fork: declarative snapshot schema, snapshot mapping and persistence, authenticated Fera Private API read client, one-time backfill console command, reporting view, and focused tests. The Magento repository must consume the package through the approved package or Composer-patch workflow; `vendor/` is not edited directly.
- Magento database: enriched `fera_review_snapshots` rows, reporting indexes, and a restricted SQL view for Metabase.
- Versioned artifact: `sql-query-36377.sql`, parameterized for a future Metabase native SQL question.
- A Metabase dashboard, Slack App integration, dashboard subscription, Slack channel configuration, and delivery monitoring are explicitly deferred to a separate task.
