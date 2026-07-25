# Purpose

Define the native SQL artifact and aggregation contract for weekly Fera product-review reporting.

# Requirements

### Requirement: The SQL query SHALL aggregate product reviews by store and product
The change SHALL provide `sql-query-36377.sql`, a native MySQL 8 query over `fera_review_snapshots` joined to the Magento `store` table. The query SHALL return a separate store aggregate for every canonical Magento store and a product breakdown within that store. Every result row SHALL use only product-review snapshots and reporting columns selected by the query.

The query SHALL NOT use Fera `subject = store` reviews, create a cross-store `Overall` aggregate, or select review content, media, customer data, or integration credentials.

#### Scenario: Store metrics aggregate all product reviews in the account
- **WHEN** the SQL query returns a store aggregate row
- **THEN** its past-week average rating, positive-review count, negative-review count, all-time average rating, and all-time review count are calculated from all product-review rows for that canonical store
- **AND** store-review snapshots do not contribute to those metrics

#### Scenario: Product metrics use the corresponding product reviews
- **WHEN** the SQL query returns a product row within a store
- **THEN** its metrics are calculated from product-review rows for that stable product identity
- **AND** the product row is ordered after its store aggregate

### Requirement: The SQL query SHALL calculate weekly dynamics and all-time baselines
The SQL query SHALL accept an inclusive period start and exclusive period end as native-query parameters. It SHALL calculate current-period metrics from `fera_created_at`, and compare them with the immediately preceding period of equal length.

For every store aggregate and product row, the query SHALL return past-week average rating, its delta versus the preceding equal-length period, past-week positive-review count, past-week negative-review count, all-time current average rating, and all-time review count. Positive reviews SHALL have rating greater than or equal to 4, and negative reviews SHALL have rating less than or equal to 3.

#### Scenario: An old review is edited during the current week
- **WHEN** a review created before the selected period is updated during the selected period
- **THEN** the SQL query excludes that review from past-week rating, positive count, and negative count
- **AND** the review remains available for all-time current metrics

#### Scenario: The reporting period changes
- **WHEN** a Metabase operator supplies a different period start and exclusive period end
- **THEN** the query calculates the comparison period immediately before that range with the same duration
- **AND** it returns the corresponding store and product metrics without a query rewrite

### Requirement: Report delivery configuration SHALL remain outside this change
This change SHALL provide the SQL artifact and Magento reporting data only. It SHALL NOT create or configure a Metabase dashboard, Metabase data-source role, Slack App integration, dashboard subscription, Slack channel, Magento summary webhook, Magento cron, or Magento report delivery.

#### Scenario: The SQL artifact is handed to a reporting task
- **WHEN** the Magento change is complete
- **THEN** `sql-query-36377.sql` is available as the native-query source for a separate Metabase reporting task
- **AND** no Slack message is sent by this change
