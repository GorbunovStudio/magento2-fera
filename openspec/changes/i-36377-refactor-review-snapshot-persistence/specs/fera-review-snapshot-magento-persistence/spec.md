## ADDED Requirements

### Requirement: Review snapshots SHALL use the Magento entity persistence boundary
The system SHALL represent `fera_review_snapshots` with a Magento data interface, model, resource model, and collection. The producer-facing snapshot repository SHALL load and save that entity through the model, resource model, and collection rather than using `ResourceConnection` or adapter fetch/upsert calls directly. The repository SHALL retain its current snapshot-array boundary, including normalized media conversion.

#### Scenario: A snapshot is read for review-update comparison
- **WHEN** the review-updated webhook requests the previous snapshot by review ID
- **THEN** the repository obtains the entity through a review-ID-filtered collection and returns the existing normalized snapshot array

#### Scenario: The backfill counts missing reporting data
- **WHEN** backfill completes processing enabled Fera accounts
- **THEN** the repository obtains the incomplete-snapshot count through `addIncompleteReportingDataFilter()` on the review snapshot collection

### Requirement: Snapshot persistence SHALL preserve source-version semantics under concurrent writers
The system SHALL serialize snapshot writes by globally unique Fera review ID across review-created webhooks, review-updated webhooks, and backfill. It SHALL update mutable snapshot fields and `fera_updated_at` only when the stored Fera update timestamp is absent or the incoming timestamp is equal to or newer than it. It SHALL populate `fera_created_at` only if the stored value is absent.

#### Scenario: An older snapshot arrives after a newer snapshot
- **WHEN** an incoming snapshot has an older Fera update timestamp than the stored snapshot
- **THEN** its mutable values and Fera update timestamp do not replace the stored values
- **AND** its Fera creation timestamp fills the stored value only when that stored value is absent

#### Scenario: Snapshot writers contend for one review
- **WHEN** two created, updated, or backfill flows process the same Fera review ID concurrently
- **THEN** the flows use the same per-review lock before reading or persisting that snapshot

### Requirement: Producer contracts SHALL remain unchanged during persistence migration
The system SHALL preserve the existing review webhook endpoints and payloads, notification decisions, backfill options, dry-run behavior, per-account error handling, summary counters, and reporting SQL behavior while changing snapshot persistence internals.

#### Scenario: An updated webhook is processed under the shared lock
- **WHEN** a valid review-updated webhook is received
- **THEN** loading the previous snapshot, comparison, persistence, notification decision, and publication occur within the shared review lock

#### Scenario: Backfill is run in dry-run mode
- **WHEN** `fera:reviews:backfill` runs with `--dry-run`
- **THEN** it validates and counts fetched reviews without acquiring snapshot locks or persisting snapshot entities
