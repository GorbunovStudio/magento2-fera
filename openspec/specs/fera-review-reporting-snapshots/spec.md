# Purpose

Define the reporting dimensions and backfill behavior for Fera review snapshots.

## Requirements

### Requirement: Fera review snapshots SHALL retain the dimensions required for reporting
The system SHALL retain one latest snapshot for each globally unique Fera `review_id`. In addition to the existing notification-comparison fields, the snapshot SHALL retain the canonical Magento store ID for the Fera account, `subject`, `external_product_id`, Fera `product.id`, nullable product name, `state`, `is_test`, original Fera creation timestamp, and latest Fera update timestamp. The system SHALL NOT persist `is_verified` for this capability.

The original Fera creation timestamp SHALL be stored separately from Magento's local `created_at` and SHALL remain the review's reporting date after later review updates. The Fera timestamps SHALL be normalized to UTC.

#### Scenario: Product review snapshot is created from a valid webhook
- **WHEN** Magento receives a valid Fera `review_created` webhook with `subject = product`
- **THEN** the system saves the review's reporting dimensions from the payload, including `product.id`, external product ID, product name, Fera timestamps, state, and test flag
- **AND** the snapshot is associated with the canonical Magento store for the authenticated Fera account

#### Scenario: Store review snapshot is created from a valid webhook
- **WHEN** Magento receives a valid Fera `review_created` webhook with `subject = store`
- **THEN** the system saves the review with `subject = store`
- **AND** product-specific fields are stored as `NULL`
- **AND** the review remains available for existing snapshot behavior without contributing to the Metabase product-review dashboard

#### Scenario: A review update preserves the review creation date
- **WHEN** Magento receives a valid Fera `review_updated` webhook for an existing snapshot
- **THEN** the system retains the review's original Fera creation timestamp
- **AND** the system updates mutable review data from the newer Fera state
- **AND** Magento's local snapshot lifecycle timestamps retain their existing meaning

### Requirement: Snapshot persistence SHALL remain independent of notification and state filters
The system SHALL persist the reporting dimensions for every valid Fera `review_created` and `review_updated` webhook. Snapshot persistence SHALL NOT depend on Slack notification settings, notification thresholds, review-update notification eligibility, or the Fera `state` value.

#### Scenario: A non-pending update is retained for reporting
- **WHEN** Magento receives a valid Fera `review_updated` webhook whose `state` is not `pending_update`
- **THEN** the system saves the current review snapshot and its reporting dimensions
- **AND** existing review-update Slack-notification eligibility remains unchanged

#### Scenario: Snapshot persistence continues while notifications are disabled
- **WHEN** Magento receives a valid Fera review-created or review-updated webhook while review notifications are disabled
- **THEN** the system saves the current snapshot and its reporting dimensions
- **AND** the existing notification setting continues to control only notification delivery

### Requirement: Snapshot writes SHALL be idempotent and source-version aware
The system SHALL upsert snapshots by the globally unique Fera `review_id`, without creating duplicate rows when the same webhook or backfill data is processed more than once. A snapshot write whose Fera update timestamp is older than the stored source update timestamp SHALL NOT overwrite newer mutable values.

#### Scenario: A duplicate review is processed again
- **WHEN** the system receives an equivalent webhook or backfill record for an existing Fera review ID
- **THEN** the system retains one snapshot row for that review ID
- **AND** the reporting values remain logically unchanged

#### Scenario: A stale backfill row follows a newer webhook
- **WHEN** a review was updated by a valid webhook after the backfill fetched an older Fera representation of that review
- **AND** the backfill later attempts to save the older representation
- **THEN** the system preserves the newer webhook values

### Requirement: Existing Fera reviews SHALL be backfilled through an explicit command
The system SHALL provide a manual, idempotent console command that imports existing reviews from the Fera Private API into enriched snapshots. The command SHALL process each enabled Fera account once by grouping Magento stores that share a Fera secret and selecting that group's canonical Magento store.

For each account, the command SHALL page through `GET /v3/private/reviews` with `subject=both`, without a `state` or verification filter, and map both `store` and `product` reviews through the same snapshot mapping used for webhooks. The command SHALL NOT invoke webhook endpoints or publish Slack or queue messages for imported history.

#### Scenario: A backfill imports both review subjects for every configured account
- **WHEN** an operator runs the backfill command without restricting it to a store
- **THEN** the command imports all pages of store and product reviews for each enabled Fera account
- **AND** each imported review is associated with that account's canonical Magento store

#### Scenario: A backfill run is safely repeated
- **WHEN** an operator reruns a completed or partially completed backfill command
- **THEN** already imported reviews are upserted without duplicate snapshots
- **AND** newer live-webhook values are not overwritten by stale API pages

#### Scenario: An existing sparse snapshot is enriched by backfill
- **WHEN** the Fera API returns a review whose `review_id` already exists in `fera_review_snapshots`
- **AND** that existing row lacks one or more reporting dimensions introduced by this change
- **THEN** the command updates the existing row rather than creating another row
- **AND** it fills the missing store, subject, product, state, test, and Fera timestamp values from the Fera record
- **AND** it preserves the original Fera creation timestamp and any newer source state already saved by a webhook

#### Scenario: A legacy snapshot is absent from Fera's current response
- **WHEN** an existing snapshot is not returned by a complete Fera backfill for its account
- **THEN** the command does not invent reporting dimensions or substitute Magento receipt time for Fera creation time
- **AND** the command reports the aggregate count of remaining incomplete snapshots
- **AND** the snapshot is excluded from the product-review report until a Fera source record can enrich it

#### Scenario: A Fera account cannot be completely imported
- **WHEN** a page request or a review mapping fails unexpectedly for one Fera account
- **THEN** the command reports the affected account using only non-sensitive identifiers and aggregate counters
- **AND** the command continues with independent accounts where safe
- **AND** the command returns a non-zero exit status after processing finishes

### Requirement: Existing review snapshots SHALL be enriched with external order associations by backfill
`fera:reviews:backfill` SHALL map `external_order_id` returned by `GET /v3/private/reviews` through the shared snapshot builder and repository. A rerun SHALL enrich an existing snapshot with a missing association without creating a duplicate row, invoking webhook handlers, or publishing queue or Slack messages.

#### Scenario: A rerun enriches a legacy snapshot
- **WHEN** the List Reviews API returns a review whose `review_id` already exists locally with `external_order_id` set to `NULL`
- **AND** the returned review has a non-empty `external_order_id`
- **THEN** the backfill retains one snapshot row for that review
- **AND** the snapshot stores the returned external order association

#### Scenario: A backfill row has no external order ID
- **WHEN** the List Reviews API returns a review without a usable `external_order_id`
- **THEN** the backfill leaves a missing association as `NULL`
- **AND** does not infer an association from local Magento data

#### Scenario: Backfill preserves its operational contract while enriching associations
- **WHEN** an operator runs `fera:reviews:backfill` to enrich external order associations
- **THEN** the command retains its existing account grouping, paging, dry-run behavior, per-account error handling, and summary counters
- **AND** the command does not publish review notifications

### Requirement: The product-review report SHALL use only the required snapshot fields
The reporting SQL query SHALL read only product-review snapshots and the Magento store name needed to aggregate them: canonical Magento store ID and display name, product identifiers and display name, rating, state, test flag, and Fera creation/update timestamps. The query SHALL not select review heading, review body, media, customer data, Fera secrets, webhook URLs, or raw webhook payloads.

#### Scenario: Metabase aggregates review data without selecting review content
- **WHEN** Metabase runs the weekly review SQL query
- **THEN** it can group product reviews by store, product, rating, state, test flag, and Fera creation date
- **AND** the query result does not contain store-review rows, review text, media, customer information, or integration credentials
