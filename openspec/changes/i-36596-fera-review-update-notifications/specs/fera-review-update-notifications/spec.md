## ADDED Requirements

### Requirement: Review snapshots SHALL be persisted independently of notification delivery

The system SHALL persist the latest known Fera review snapshot for every valid review-created and review-updated webhook so future update detection has historical data. Snapshot persistence SHALL NOT be disabled when review notifications are disabled.

#### Scenario: Review-created webhook saves a snapshot when notifications are enabled

- **WHEN** Magento receives a valid Fera review-created webhook
- **AND** the payload contains `id`, `heading`, `body`, `rating`, and `media`
- **THEN** the system saves a snapshot keyed by the Fera review ID
- **AND** the snapshot stores `heading`, `body`, `rating`, and normalized `media`

#### Scenario: Review-created webhook saves a snapshot when notifications are disabled

- **WHEN** Magento receives a valid Fera review-created webhook
- **AND** review notifications are disabled for the store
- **THEN** the system saves the review snapshot
- **AND** the system does not publish a negative-review notification

#### Scenario: Review-updated webhook saves a snapshot when notifications are disabled

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** review notifications are disabled for the store
- **THEN** the system saves the current review snapshot as the latest known state
- **AND** the system does not publish a review-update notification

#### Scenario: Media snapshot stores only required media fields

- **WHEN** webhook payload `media` contains media objects with `id`, full media `url`, `thumbnail_url`, and other fields
- **THEN** the review snapshot stores only `id` and full media `url` for each media item
- **AND** the system normalizes media before comparison and persistence

### Requirement: Review updates SHALL be detected by comparing selected fields with the previous snapshot

The system SHALL compare current review values with the previous snapshot for `rating`, `heading`, `body`, and normalized `media`. The system SHALL publish a review-update notification only when a previous snapshot exists, at least one selected field changed, review notifications are enabled, and the webhook payload has `state = pending_update`.

#### Scenario: Changed selected fields with pending update publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** a previous snapshot exists for the review
- **AND** at least one of `rating`, `heading`, `body`, or normalized `media` differs from the previous snapshot
- **AND** the payload has `state = pending_update`
- **AND** review notifications are enabled for the store
- **THEN** the system publishes a review-update notification message
- **AND** the system saves the current snapshot as the latest known state

#### Scenario: Missing previous snapshot does not publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** no previous snapshot exists for the review
- **THEN** the system saves the current snapshot as the latest known state
- **AND** the system does not publish a review-update notification

#### Scenario: Non-pending update state does not publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** selected fields changed compared with the previous snapshot
- **AND** the payload state is not `pending_update`
- **THEN** the system saves the current snapshot as the latest known state
- **AND** the system does not publish a review-update notification

#### Scenario: No selected field changes do not publish a notification

- **WHEN** Magento receives a valid Fera `review_updated` webhook
- **AND** `rating`, `heading`, `body`, and normalized `media` match the previous snapshot
- **THEN** the system saves the current snapshot as the latest known state
- **AND** the system does not publish a review-update notification

### Requirement: Review-update Slack notification SHALL show context and before-after changed fields

The system SHALL send a Slack notification for qualifying review updates that clearly identifies the event as a review update, includes available review context, and shows before/after values for changed fields.

#### Scenario: Slack notification identifies a review update

- **WHEN** a review-update notification message is processed
- **THEN** the Slack payload identifies the event as a Fera review update
- **AND** the payload includes available store, customer, rating, review text, product, Fera review link, and order information

#### Scenario: Slack diff shows changed fields in the required order

- **WHEN** changed fields are included in a review-update notification
- **THEN** the Slack diff contains a `Review before changes` group
- **AND** the Slack diff contains an `After changes` group
- **AND** changed fields appear in the order `rating`, `heading`, `body`, `media`
- **AND** unchanged fields are omitted from the diff groups

#### Scenario: Media diff uses full media URLs

- **WHEN** normalized `media` changed between the previous and current snapshots
- **THEN** the Slack diff displays media values using the full media `url`
- **AND** uploaded photos can be opened at full size from the displayed URLs
- **AND** uploaded videos can be opened or played from the displayed URLs

#### Scenario: Missing optional context does not block notification delivery

- **WHEN** a review-update notification lacks optional context such as customer name, product name, order information, or Fera review link
- **THEN** the system still sends the Slack notification
- **AND** missing optional fields are represented with a safe fallback value

### Requirement: Review-update Slack notification SHALL include the required action buttons

The system SHALL include the same operator action buttons on review-update Slack notifications as on negative-review notifications: `View in Fera`, `View Magento Order`, `View in MakerWare`, and `View Freshdesk Tickets` when each target can be resolved.

#### Scenario: Base Fera actions are included when targets are available

- **WHEN** a review-update Slack notification is prepared
- **AND** the Fera review link can be built
- **THEN** the notification includes a `View in Fera` action
- **AND** when the Magento order can be resolved, the notification includes a `View Magento Order` action

#### Scenario: Budsies MakerWare action is added through reusable action enrichment

- **WHEN** Budsies action enrichment receives a review-update notification context
- **AND** the reviewed plushie can be resolved from the order by `external_product_id`
- **AND** the plushie has a production backend ID
- **THEN** the notification includes a `View in MakerWare` action

#### Scenario: Budsies Freshdesk action is added through reusable action enrichment

- **WHEN** Budsies action enrichment receives a review-update notification context
- **AND** the notification contains `customer_email`
- **AND** Freshdesk resolves a contact ID for that email
- **THEN** the notification includes a `View Freshdesk Tickets` action

#### Scenario: Unresolved optional action targets omit only those actions

- **WHEN** MakerWare or Freshdesk action target resolution fails or has insufficient data
- **THEN** the system omits only the unresolved optional action
- **AND** the base review-update notification still sends

### Requirement: Existing negative-review notifications SHALL keep their behavior

The system SHALL preserve the existing negative-review notification behavior while adding review-update notifications.

#### Scenario: Negative-review notification still follows the existing threshold flow

- **WHEN** Magento receives a valid review-created webhook for a review at or below the negative-review threshold
- **THEN** the system publishes the existing negative-review notification
- **AND** the review-update snapshot persistence does not change the negative-review notification content or threshold decision

#### Scenario: Negative-review action buttons still render after provider extraction

- **WHEN** an existing negative-review Slack notification is prepared
- **THEN** the notification still includes the same base and Budsies-specific actions when their targets can be resolved
