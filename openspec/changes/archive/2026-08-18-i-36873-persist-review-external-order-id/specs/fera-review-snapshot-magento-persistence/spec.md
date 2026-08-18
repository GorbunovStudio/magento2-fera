## ADDED Requirements

### Requirement: Review snapshots SHALL retain the Fera external order association
The normalized review snapshot and its Magento entity SHALL retain the nullable top-level Fera `external_order_id` in `fera_review_snapshots`. The system SHALL create a non-unique lookup index for that column. The stored value SHALL remain the normalized Fera source identifier and SHALL NOT be converted to a Magento order increment ID or constrained by a foreign key.

#### Scenario: A created review includes an external order ID
- **WHEN** a valid `review_created` webhook contains a non-empty top-level `external_order_id`
- **THEN** the snapshot builder normalizes the identifier and the persisted snapshot retains it
- **AND** the snapshot remains uniquely identified by its Fera `review_id`

#### Scenario: A review lacks an order association
- **WHEN** a valid review payload omits `external_order_id` or supplies it as blank text
- **THEN** the normalized snapshot represents the association as `NULL`
- **AND** persistence does not invent a Magento order relationship

#### Scenario: A newer review state changes a non-empty association
- **WHEN** an incoming snapshot has an equal or newer Fera update timestamp and a non-empty external order ID that differs from the stored association
- **THEN** the repository persists the incoming association with the accepted review state

### Requirement: Order-link enrichment SHALL not weaken source-version protection
The repository SHALL preserve a stored non-empty `external_order_id` when an incoming snapshot omits that field. When the stored association is `NULL` and an incoming snapshot contains a non-empty external order ID, the repository SHALL fill the association even if that incoming snapshot is stale for mutable review fields. This enrichment SHALL NOT allow stale mutable review fields or `fera_updated_at` to replace newer stored source state.

#### Scenario: A stale backfill row fills only a missing association
- **WHEN** a backfill response has an older Fera update timestamp than an existing snapshot
- **AND** the existing snapshot has no external order ID
- **AND** the response has a non-empty external order ID
- **THEN** the repository persists that order association
- **AND** retains the newer heading, body, rating, media, reporting dimensions, and Fera update timestamp

#### Scenario: An incomplete payload follows a linked snapshot
- **WHEN** a snapshot already has a non-empty external order ID
- **AND** a later webhook or API payload omits or leaves blank `external_order_id`
- **THEN** persistence retains the existing order association
