## 1. Media Payload Foundation

- [x] 1.1 Add or reuse a shared media normalizer that extracts created-review `media` payload items into a deterministic list of `{id, url}` values and ignores items without a usable full media URL.
- [x] 1.2 Extend the negative-review queue message contract/model to carry normalized media for Slack rendering without changing existing negative-review fields.
- [x] 1.3 Update `ReviewCreatedWebhook` to normalize media once and pass it into negative and positive notification messages when those messages are published.
- [x] 1.4 Add or update tests proving photo and video media use full `url` values, `thumbnail_url` is not the primary rendered value, and malformed media is ignored safely.

## 2. Positive Notification Flow

- [x] 2.1 Add positive-review config constants and admin config fields for `enabled`, `rating_threshold`, and encrypted `slack_webhook_url`, scoped consistently with existing Fera store/account config.
- [x] 2.2 Add a positive-review queue topic constant, `communication.xml` topic/handler, `queue_publisher.xml` publisher, and `queue_topology.xml` binding using the existing Fera queue topology.
- [x] 2.3 Add the positive-review queue message interface/model with review context fields, `customer_email`, `external_product_id`, and normalized media payload.
- [x] 2.4 Update `ReviewCreatedWebhook` so reviews meeting the configured positive-review threshold publish positive-review messages only when positive notifications are enabled for the store.
- [x] 2.5 Implement `NotifyPositiveReview\Handler` to validate store/config state, resolve store/order/Fera URLs, build the positive Slack payload, render media URLs, and send to the positive Slack webhook URL.
- [x] 2.6 Dispatch a positive-review Slack action-preparation event carrying the message, resolved order, store ID, and mutable actions container.
- [x] 2.7 Add focused tests for configurable positive threshold routing, disabled positive config, separate Slack destination, missing optional context, base actions, and handler exception behavior.

## 3. Existing Negative Notification Updates

- [x] 3.1 Update the negative-review Slack handler to render a Media section/field from normalized full media URLs when present.
- [x] 3.2 Preserve existing negative-review threshold checks, negative Slack webhook URL usage, email recipient/template behavior, and base Slack action behavior.
- [x] 3.3 Add or update focused tests proving negative notifications render media URLs while preserving threshold, destination, email, and action behavior.

## 4. Fera Fork Validation and Rollout

- [x] 4.1 Validate Fera queue XML files: `communication.xml`, `queue_publisher.xml`, `queue_topology.xml`, and `queue_consumer.xml`.
- [x] 4.2 Validate Fera admin config XML/default config for positive-review enable flag, positive-review threshold, and encrypted Slack webhook URL.
- [x] 4.3 Validate the existing anonymous `review_create` webhook route still requires JWT validation and that no new anonymous unauthenticated surface is introduced.
- [x] 4.4 Run focused Fera fork unit tests for review-created routing, media rendering, positive handler behavior, and negative no-regression.
- [x] 4.5 Release or otherwise make the Fera fork changes available to the Magento repo through the accepted package workflow without editing `vendor/` directly.
- [x] 4.6 Document rollout notes for configuring the positive Slack webhook per store/account, including use of the agreed test destination until the production positive-review Slack channel exists.
