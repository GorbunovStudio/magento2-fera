## ADDED Requirements

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
