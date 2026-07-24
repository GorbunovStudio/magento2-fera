## Why

Operators can request that a customer update an existing Fera.ai review, but the current Fera UI does not make the customer's follow-up edit obvious. Without a dedicated notification, review updates can be missed even though the team already receives Slack alerts for newly submitted negative reviews.

## What Changes

- Add support for the Fera `review_updated` webhook in the shared Fera fork.
- Persist the latest known review snapshot for every created and updated review, even when the corresponding notification type is disabled, so future update detection has a reliable baseline.
- Ensure review snapshot text storage preserves 4-byte Unicode characters, such as emoji, so repeated identical webhooks do not create false body changes after database round-trips.
- Detect review updates by comparing the current payload with the previous snapshot for `rating`, `heading`, `body`, and `media`, and require `state = pending_update` before sending an update notification.
- Add independent enabled settings for negative-review notifications and review-update notifications in the existing review notifications configuration group; both default to disabled and both use the shared Slack webhook URL.
- Send a new Slack notification for qualifying review updates with before-change review context, after-change values for changed fields, and a dedicated list of newly attached media.
- Keep the existing negative-review notification behavior unchanged.
- Split implementation into two coordinated parts:
  - Part 1, Fera fork: webhook handling, snapshot storage, update detection, queue contract/topic, and base Slack notification.
  - Part 2, Budsies repo: Budsies-specific Slack action enrichment for MakerWare and Freshdesk buttons, reusing existing `Budsies_Fera` components.

## Capabilities

### New Capabilities

- `fera-review-update-notifications`: Detecting Fera review updates after update requests and notifying Slack with review context, changed-field before/after values, and the same operator action buttons as negative-review notifications.

### Modified Capabilities

- None.

## Impact

- Shared `feraai/fera` fork: Fera webhook API surface, JWT validation flow, review snapshot persistence, declarative schema and snapshot charset schema patch, queue topic/message/handler, review notification configuration, Slack payload formatting, and unit tests.
- Magento repo `Budsies_Fera`: action-enrichment event handling for review update notifications and extraction/reuse of MakerWare/Freshdesk Slack action providers.
- Existing Fera negative-review notification flow must continue to work unchanged.
- New database table or schema changes in the Fera module require `setup:upgrade` and schema whitelist updates when implemented.
- New verified anonymous webhook route and queue topology changes require explicit validation of `webapi.xml`, `communication.xml`, `queue_publisher.xml`, `queue_topology.xml`, and `queue_consumer.xml`.
