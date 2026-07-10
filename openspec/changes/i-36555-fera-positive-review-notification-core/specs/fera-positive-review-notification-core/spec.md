## ADDED Requirements

### Requirement: Positive review-created notifications SHALL use separate Fera routing and configuration

The shared Fera module SHALL support Slack notifications for newly created Fera reviews whose rating meets the configured positive-review threshold when positive-review notifications are enabled for the store. Positive notifications SHALL use a separate queue topic, handler, enable flag, positive-review threshold setting, and encrypted Slack webhook URL from negative-review notifications.

#### Scenario: Four-star review publishes a positive notification when threshold is four
- **WHEN** Magento receives a valid Fera `review_create` webhook for a review with rating `4`
- **AND** the current store positive-review threshold is configured as `4`
- **AND** positive-review notifications are enabled for the current store
- **THEN** the shared Fera module publishes a positive-review notification message
- **AND** the message is routed through the positive-review queue topic

#### Scenario: Five-star review publishes a positive notification when threshold is five
- **WHEN** Magento receives a valid Fera `review_create` webhook for a review with rating `5`
- **AND** the current store positive-review threshold is configured as `5`
- **AND** positive-review notifications are enabled for the current store
- **THEN** the shared Fera module publishes a positive-review notification message
- **AND** the message is routed through the positive-review queue topic

#### Scenario: Review below configured positive threshold is skipped
- **WHEN** Magento receives a valid Fera `review_create` webhook for a review with rating `4`
- **AND** the current store positive-review threshold is configured as `5`
- **AND** positive-review notifications are enabled for the current store
- **THEN** the shared Fera module does not publish a positive-review notification message

#### Scenario: Positive notifications are skipped when disabled
- **WHEN** Magento receives a valid Fera `review_create` webhook for a review with rating `5`
- **AND** positive-review notifications are disabled for the current store
- **THEN** the shared Fera module does not publish a positive-review notification message

#### Scenario: Positive Slack destination is independent
- **WHEN** a positive-review notification message is processed
- **AND** the current store has a positive-review Slack webhook URL configured
- **THEN** the shared Fera module sends the Slack notification to the positive-review Slack webhook URL
- **AND** the existing negative-review Slack webhook URL is not used for that positive notification

### Requirement: Positive review Slack payload SHALL include base review context and base actions

The shared Fera module SHALL send a positive-review Slack payload that clearly identifies the alert as a positive review and includes available review context.

#### Scenario: Positive Slack payload includes required context
- **WHEN** a positive-review notification message is processed
- **THEN** the Slack payload identifies the event as a positive review
- **AND** the payload includes the store or brand name
- **AND** the payload includes the customer name when available
- **AND** the payload includes the rating
- **AND** the payload includes the review title and review text when available
- **AND** the payload includes the product name when available
- **AND** the payload includes the order identifier when available
- **AND** the payload includes a `View in Fera` action when the Fera review URL can be built

#### Scenario: Missing optional context does not block positive Slack delivery
- **WHEN** a positive-review notification lacks optional context such as customer name, product name, order information, review title, or review text
- **THEN** the shared Fera module still sends the positive-review Slack notification
- **AND** missing optional fields are represented with a safe fallback value or omitted when appropriate

#### Scenario: Magento order action is included when order resolves
- **WHEN** a positive-review notification resolves to a Magento order
- **THEN** the Slack notification includes a `View Magento Order` action

### Requirement: Created-review Slack notifications SHALL render uploaded media using full media URLs

The shared Fera module SHALL render customer-submitted media in created-review Slack notifications using full media `url` values from Fera media payloads. This SHALL apply to both positive-review Slack notifications and existing negative-review Slack notifications.

#### Scenario: Positive notification renders media URLs
- **WHEN** a positive-review notification message contains normalized media with one or more full media `url` values
- **THEN** the Slack notification includes a Media section or field
- **AND** each usable media `url` is rendered as a visible Slack link

#### Scenario: Negative notification renders media URLs
- **WHEN** a negative-review notification message contains normalized media with one or more full media `url` values
- **THEN** the Slack notification includes a Media section or field
- **AND** each usable media `url` is rendered as a visible Slack link
- **AND** the existing negative-review threshold and destination behavior remains unchanged

#### Scenario: Photo and video media use the same full URL rule
- **WHEN** Fera provides photo or video media items with full media `url` values
- **THEN** the Slack notification renders those full media `url` values
- **AND** the system does not render `thumbnail_url` as the primary media value

#### Scenario: Missing or malformed media does not block notification delivery
- **WHEN** a created-review notification has no media
- **OR** all provided media items are missing usable full media `url` values
- **THEN** the shared Fera module sends the Slack notification without a Media section or field
- **AND** notification processing does not fail because media is absent or malformed

### Requirement: Positive Slack notification SHALL expose an action-enrichment event

The shared Fera module SHALL expose a positive-review Slack action-preparation event so tenant-specific modules can append optional action buttons before the Slack payload is sent.

#### Scenario: Positive action event provides enrichment context
- **WHEN** a positive-review Slack notification is prepared
- **THEN** the shared Fera module dispatches a positive-review action-preparation event
- **AND** the event includes the positive notification message
- **AND** the event includes the resolved Magento order when available
- **AND** the event includes the store ID
- **AND** the event includes a mutable actions container

#### Scenario: Action event failure preserves base notification
- **WHEN** a positive-review action-preparation observer throws an exception
- **THEN** the shared Fera module logs the enrichment failure
- **AND** the base positive-review Slack notification still sends with base actions

### Requirement: Existing negative-review notifications SHALL preserve routing and email behavior

The shared Fera module SHALL preserve existing negative-review behavior while adding media rendering and positive-review notifications.

#### Scenario: Negative-review threshold flow remains unchanged
- **WHEN** Magento receives a valid Fera `review_create` webhook for a review at or below the configured negative-review threshold
- **THEN** the shared Fera module follows the existing negative-review notification flow
- **AND** the review is evaluated against the configured negative-review threshold as before

#### Scenario: Negative-review email behavior remains unchanged
- **WHEN** a negative-review notification is processed
- **THEN** existing negative-review email notification behavior remains unchanged
- **AND** review media is not required to be added to the negative-review email template
