## Context

The shared Fera module owns `review_create` webhook authentication, review payload parsing, queue publication, Fera review URL generation, Fera admin configuration, and base Slack delivery. Existing negative-review notifications use `fera.review.notify_negative` and `fera_ai/review_notifications/*`.

Redmine 36555 requires positive-review alerts based on a configurable positive-review threshold, separate Slack routing from negative alerts, and customer-uploaded media in review Slack notifications. This Fera-side change provides the shared package capability only. Budsies-specific MakerWare/Freshdesk action buttons are intentionally implemented in the separate Budsies change using an extension event exposed here.

This change touches externally observable Slack integration behavior, encrypted config, and queue topology. Those areas are in scope. The implementation must happen in the Fera fork/package workflow or an approved Composer patch workflow, not by directly editing `vendor/` in the Magento repo.

## Goals / Non-Goals

**Goals:**

- Publish positive-review notification messages for valid `review_create` payloads whose rating meets the configured positive-review threshold when enabled for the store.
- Send positive-review Slack messages to a separate configured webhook URL.
- Keep the positive flow on a dedicated topic and handler.
- Render created-review media in both positive and negative Slack notifications using full media `url` values.
- Preserve negative-review threshold, negative Slack destination, negative email behavior, and existing negative base actions.
- Expose an action-preparation event for positive-review Slack actions.

**Non-Goals:**

- Implementing MakerWare or Freshdesk action enrichment.
- Adding positive-review email notifications.
- Changing review-update notification detection or snapshot persistence from Redmine 36596.
- Adding weekly summaries or analytics.
- Adding database schema for positive notifications.
- Adding new external dependencies.

## Decisions

### 1. Use a dedicated positive-review queue topic and handler

**Decision:** Add a topic such as `fera.review.notify_positive`, a message interface/model under `NotifyPositiveReview`, and `NotifyPositiveReview\Handler`.

**Rationale:** Positive notifications have different copy, destination config, and enabled state from negative notifications. A separate topic and handler keep the existing negative flow stable and easier to validate.

**Alternatives considered:**

- Generalize `fera.review.notify_negative` into one typed review-notification topic: rejected because it creates a larger refactor and increases regression risk for existing negative notifications.
- Send Slack directly in `ReviewCreatedWebhook`: rejected because webhook handling should publish queue work instead of blocking on Slack HTTP calls.

### 2. Add positive-review config separate from negative-review config

**Decision:** Add a positive-review config group with at least:

- `fera_ai/positive_review_notifications/enabled`
- `fera_ai/positive_review_notifications/rating_threshold`
- `fera_ai/positive_review_notifications/slack_webhook_url`

The Slack webhook URL must use Magento encrypted config storage. The positive-review threshold must be an independently configurable rating value in the same config group, with a default of `4`. Store/website/default visibility should follow existing Fera review notification config scope.

**Rationale:** Redmine 36555 now requires the positive-review threshold to be configurable independently from the negative-review threshold and routed to a separate Slack destination.

**Alternatives considered:**

- Reuse `fera_ai/review_notifications/slack_webhook_url`: rejected because it would route positive and negative reviews to the same destination.
- Reuse the existing negative-review threshold as the positive threshold: rejected because positive and negative review routing must remain independently configurable.
- Add the positive webhook under the negative review-notifications group: possible, but a dedicated group avoids implying the negative threshold controls positive notifications.

### 3. Use a configurable positive-review threshold

**Decision:** `ReviewCreatedWebhook` should publish a positive notification when `rating >= configured positive-review threshold` and positive notifications are enabled. Ratings below the configured threshold should not publish positive messages. The threshold should default to `4` to preserve the original launch behavior unless a store config overrides it.

**Rationale:** The business decision changed to allow stores to control which ratings count as positive without coupling that rule to the negative-review threshold.

**Alternatives considered:**

- Keep the threshold fixed at 4: rejected because the agreed behavior now requires a separate positive-threshold setting.
- Suppress positive notifications when the negative threshold is configured to overlap rating 4 or 5: rejected because negative threshold semantics are existing behavior and should not be silently changed.

### 4. Normalize and render media using full media URLs

**Decision:** Normalize review media to a deterministic list of `{id, url}`. Ignore media items without a usable full `url`. Render the full `url` values in Slack, one per line, for both positive and negative created-review Slack notifications.

**Rationale:** Fera media payloads can include both photos and videos. Full `url` links let operators open full-size photos and playable videos. `thumbnail_url` is not the primary display value and should not trigger behavior by itself.

**Alternatives considered:**

- Render `thumbnail_url`: rejected because video thumbnails are static images and do not open/play the uploaded video.
- Store and render media `type`: rejected because the current Slack behavior can present URLs uniformly for photos and videos.
- Add media to negative emails: rejected because the requested change is Slack visibility and existing email behavior should remain stable.

### 5. Expose a positive action-preparation event

**Decision:** Before sending the positive Slack payload, dispatch an event such as `fera_positive_review_slack_actions_prepare` with the positive message, resolved order, store ID, and mutable actions container.

**Rationale:** The shared Fera module should stay tenant-agnostic. Budsies-specific action buttons can then be added from `Budsies_Fera` without hardcoding Budsies dependencies into the Fera package.

**Alternatives considered:**

- Add MakerWare/Freshdesk buttons directly in Fera: rejected because those are Budsies-specific integrations.
- Omit an extension event for positive notifications: rejected because Redmine 36555 requires the same contextual actions for positive notifications.

## Risks / Trade-offs

- Positive and negative rules can overlap depending on the configured positive and negative thresholds -> Preserve existing negative behavior and test positive routing independently.
- Slack may not preview every uploaded media URL -> Render visible full URLs so operators can open them even without unfurling.
- Added media can make Slack payloads longer -> Keep media rendering compact and omit the media block when no usable URLs exist.
- Queue contract changes require package coordination -> Release/update the Fera package before applying Budsies action-enrichment wiring.

## Migration Plan

1. Implement and release the Fera fork/package changes.
2. Validate queue XML and handler registration in an environment consuming the package.
3. Configure positive-review notifications per store/account with the agreed test destination until a production positive-review channel exists.
4. Deploy the separate Budsies action-enrichment change after the positive action event is available.

Rollback can disable positive notifications or clear the positive Slack webhook URL. If queue/event contracts are incompatible with Budsies wiring, roll back the Fera package update and Budsies change together.

## Open Questions

- None for scope. Final Slack copy can be adjusted during implementation if product owners prefer specific wording.
