## Why

Budsies currently receives immediate Slack alerts for negative Fera.ai reviews, but positive reviews and their customer-submitted media are not surfaced in the same operational flow. This change adds the shared Fera module support needed to publish positive-review Slack notifications using a configurable threshold and render review media in created-review Slack alerts.

## What Changes

- Add optional positive-review Slack notifications for newly created Fera reviews whose rating meets a configurable positive-review threshold.
- Route positive-review notifications through a dedicated Fera queue topic and handler, separate from negative-review notifications.
- Add per-store Fera admin configuration for enabling positive-review notifications and setting a separate encrypted Slack webhook URL.
- Normalize created-review media to `{id, url}` and render full media URLs in Slack so photos open full-size and videos can be opened or played.
- Add media rendering to existing negative-review Slack notifications while preserving the negative-review threshold, Slack destination, and email behavior.
- Add a positive-review Slack action-preparation event so downstream modules can append tenant-specific action buttons.

## Capabilities

### New Capabilities

- `fera-positive-review-notification-core`: Shared Fera fork behavior for positive created-review Slack notifications, media rendering, positive queue routing, config, and extension event publication.

### Modified Capabilities

- None.

## Impact

- Shared `feraai/fera` fork: `ReviewCreatedWebhook`, queue topics/contracts/handlers, admin config XML/default config, Slack payload builders, media normalization, and Fera fork tests.
- Queue XML: `communication.xml`, `queue_publisher.xml`, `queue_topology.xml`, and existing `queue_consumer.xml` validation.
- Config/security: new positive-review threshold setting, encrypted positive Slack webhook URL, and store-scoped enable flag.
- Existing negative-review Slack/email behavior must remain backward-compatible except for added Slack media rendering.
- No Budsies repo action-enrichment implementation is included in this change; that is handled by `i-36555-budsies-positive-review-actions`.
