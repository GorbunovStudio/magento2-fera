## MODIFIED Requirements

### Requirement: Review updates SHALL be detected by comparing selected fields with the previous snapshot

The system SHALL reconcile the incoming review snapshot using the snapshot source-version rules, then compare the effective stored review values with the previous snapshot for `rating`, `heading`, `body`, and normalized `media`. The system SHALL publish a review-update notification only when a previous snapshot exists, at least one selected field changed in the effective stored snapshot, and review-update notifications are enabled. Snapshot-derived notification content SHALL use the effective stored snapshot rather than rejected incoming values.

#### Scenario: Changed selected fields publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** a previous snapshot exists for the review
- **AND** at least one of `rating`, `heading`, `body`, or normalized `media` differs after source-version reconciliation
- **AND** review-update notifications are enabled for the store
- **THEN** the system publishes a review-update notification message using the effective stored snapshot
- **AND** the system saves the effective snapshot as the latest known state

#### Scenario: Stale mutable values do not publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook with an older Fera update timestamp than the stored snapshot
- **AND** source-version reconciliation rejects its mutable values
- **THEN** the system retains the newer stored mutable values
- **AND** the system does not publish a review-update notification when no selected field changed in the effective snapshot
- **AND** a missing stored Fera creation timestamp may still be filled from the stale payload

#### Scenario: Missing previous snapshot does not publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** no previous snapshot exists for the review
- **THEN** the system saves the current snapshot as the latest known state
- **AND** the system does not publish a review-update notification

#### Scenario: Webhook state does not prevent notification publication

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** selected fields changed in the effective snapshot compared with the previous snapshot
- **AND** review-update notifications are enabled for the store
- **THEN** the system publishes a review-update notification message
- **AND** the system saves the effective snapshot as the latest known state

#### Scenario: No selected field changes do not publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** `rating`, `heading`, `body`, and normalized `media` in the effective snapshot match the previous snapshot
- **THEN** the system retains the effective snapshot as the latest known state
- **AND** the system does not publish a review-update notification

#### Scenario: Review-update notifications disabled do not publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** a previous snapshot exists for the review
- **AND** at least one selected field changed in the effective snapshot compared with the previous snapshot
- **AND** review-update notifications are disabled for the store
- **THEN** the system saves the effective snapshot as the latest known state
- **AND** the system does not publish a review-update notification

#### Scenario: Concurrent review-updated webhooks are serialized per review

- **WHEN** Magento receives multiple valid Fera `review_updated` webhooks for the same store and review at the same time
- **THEN** the system processes the snapshot load, reconciliation, comparison, queue publication decision, and snapshot save under a single per-review lock
- **AND** later concurrent processing for that same review observes the snapshot saved by the earlier processing before deciding whether to publish
- **AND** identical repeated payloads do not publish duplicate review-update notification messages

#### Scenario: Different reviews can be processed independently

- **WHEN** Magento receives valid Fera `review_updated` webhooks for different reviews
- **THEN** the per-review lock for one review does not block processing of the other review

#### Scenario: Lock acquisition failure returns a retryable webhook error

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** the per-review processing lock cannot be acquired within the configured wait period
- **THEN** the system does not publish a review-update notification for that attempt
- **AND** the system returns a retryable webhook error so Fera can retry after the active processing completes
