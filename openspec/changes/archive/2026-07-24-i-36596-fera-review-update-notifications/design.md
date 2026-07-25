## Context

The current shared Fera module processes the review-created webhook and sends Slack/email notifications for negative reviews through `Fera\Ai\Model\Queue\NotifyNegativeReview\Handler`. Budsies extends the shared negative-review Slack notification with MakerWare and Freshdesk buttons through `Budsies\Fera\Observer\AddNegativeReviewSlackActionsObserver`.

Redmine 36596 adds a related but separate workflow: after an operator requests a review update in Fera.ai, the team needs a Slack notification when the customer changes the existing review. Fera's `review_updated` webhook fires for many review changes, so the implementation identifies meaningful review changes by comparing selected review fields against the last known snapshot.

Implementation is intentionally split:

- Part 1, Fera fork: shared review webhook handling, snapshot persistence, update detection, queue contract/topic, and base Slack notification.
- Part 2, Budsies repo: Budsies-specific Slack action enrichment for MakerWare and Freshdesk buttons, reusing existing `Budsies_Fera` integration logic.

## Goals / Non-Goals

**Goals:**

- Persist the latest snapshot for every created and updated Fera review, even when the corresponding notification type is disabled.
- Detect update notifications from direct comparison of `rating`, `heading`, `body`, and normalized `media`.
- Gate negative-review notifications and review-update notifications with separate enabled settings that both default to disabled.
- Keep both notification types on the shared Slack webhook URL setting.
- Send a review-update Slack notification with before-change review context, after-change values for changed fields, and a dedicated list of newly attached media.
- Include the same action buttons as negative-review notifications: `View in Fera`, `View Magento Order`, `View in MakerWare`, and `View Freshdesk Tickets`.
- Keep existing negative-review notification behavior unchanged.

**Non-Goals:**

- Building a full review field history or audit log.
- Reliably distinguishing customer edits from rare operator edits to the same fields; all selected-field changes are reported.
- Changing Fera's negative-review rating threshold behavior.
- Adding new external dependencies.
- Editing `vendor/` directly in this Magento repository.

## Decisions

### 1. Implement core update detection in the shared Fera fork

**Decision:** Add the `review_updated` webhook endpoint, snapshot table, snapshot services, queue topic/message, and base Slack handler in the shared Fera fork.

**Rationale:** Fera webhook authentication, payload parsing, review URL generation, notification config, and queue topology already belong to the shared Fera module. Keeping update detection there avoids duplicating Fera-specific logic in `Budsies_Fera`.

**Alternatives considered:**

- Implement the entire flow in `Budsies_Fera`: rejected because it would duplicate Fera webhook validation and payload parsing while the shared Fera module already owns that integration boundary.
- Modify `vendor/feraai/fera` directly in this repo: rejected by repository standards. Changes must be made in the Fera fork workflow or through an approved Composer patch workflow.

### 2. Save snapshots independently of notification delivery config

**Decision:** Save snapshots on review creation and update even when the corresponding notification type is disabled for the store. Negative-review and review-update notification enable flags gate only queue publication and delivery, not snapshot persistence.

**Rationale:** Future update notifications need historical baseline data. If snapshot collection stops while notifications are disabled, re-enabling notifications later would produce missing baselines and missed first updates.

**Alternatives considered:**

- Keep the existing early return when notifications are disabled: rejected because it makes update detection unreliable after notifications are re-enabled.
- Persist snapshots only for negative reviews: rejected because updated reviews can start from any rating, and the update notification is independent of the negative-review threshold.

### 2a. Use separate enabled flags for negative-review and review-update notifications

**Decision:** Rename the existing review notifications enabled setting to a negative-review-specific enabled setting and add a separate review-update notifications enabled setting. Both settings default to disabled. Existing legacy values for the old enabled setting should be migrated only to the renamed negative-review setting. Review-update notifications should depend only on the new review-update enabled setting while continuing to use the existing shared Slack webhook URL.

**Rationale:** Operators need to enable or disable review-update notifications independently from negative-review notifications. The Slack webhook destination is shared operational infrastructure, so duplicating it would create unnecessary configuration drift.

**Alternatives considered:**

- Gate review-update notifications behind both the old review notifications enabled flag and a new update-specific flag: rejected because disabling negative-review notifications should not disable review-update notifications.
- Copy the legacy enabled value to both new flags: rejected because review-update notifications are a new notification type and should remain opt-in by default.
- Add a separate Slack webhook URL for review-update notifications: rejected because the accepted scope uses the existing shared Slack destination.

### 3. Store only the latest selected review fields

**Decision:** Store a single latest snapshot keyed by `review_id`, containing `heading`, `body`, `rating`, and normalized `media` with only `id` and full media `url`. Compare media items by non-empty `id`, falling back to `url` only when an ID is unavailable; retain the full URL for Slack display.

**Rationale:** The current requirement needs only the previous state for one comparison. A media URL can change without representing a new attachment, so a stable media ID avoids false update notifications while the full URL remains available for Slack. Storing full history would add schema and retention complexity without supporting a current workflow.

**Alternatives considered:**

- Store a hash only: rejected because Slack needs before/after values for changed fields.
- Store complete raw webhook payloads: rejected because this would retain more customer-linked data than needed and would complicate schema/privacy handling.
- Store media metadata beyond `id` and `url`, such as `thumbnail_url`, `type`, or processing details: rejected because Slack notifications should link to the full media URL so operators can open full-size photos and playable videos, and metadata-only changes should not trigger review-update notifications.

### 3a. Force review snapshot storage to utf8mb4

**Decision:** Add a schema patch that converts `fera_review_snapshots` to `utf8mb4` after the declarative table is created.

**Rationale:** Review text can contain 4-byte Unicode characters such as emoji. Magento declarative schema can create module tables with an explicit `DEFAULT CHARSET=utf8mb3` even when the database default charset is `utf8mb4`; in that case characters such as `🖤` are stored as `?`. That lossy round-trip makes repeated identical Fera `review_updated` webhooks look like body changes because the current payload still contains the emoji while the previous snapshot contains `?`.

**Alternatives considered:**

- Rely on the database default charset: rejected because observed Magento-generated DDL can still create the table as `utf8mb3`.
- Store snapshot strings in an ASCII-safe encoded format such as Base64: rejected because it makes the snapshot table harder to inspect, increases storage size, and adds encode/decode complexity around normal text fields.
- Use `utf8mb4_0900_ai_ci`: rejected for the module patch because it is MySQL 8 specific; `utf8mb4_general_ci` is more compatible across Magento MySQL/MariaDB environments while still preserving 4-byte Unicode.

### 4. Compare selected fields directly

**Decision:** On `review_updated`, compare current snapshot values with the previous snapshot. Publish a notification only when a previous snapshot exists, at least one selected field changed, and review-update notifications are enabled.

**Rationale:** Fera's update webhook is broad. Comparing the selected fields avoids notifications for metadata-only deliveries while reporting every meaningful review change.

**Alternatives considered:**

- Notify on every `review_updated` webhook: rejected because operator or system changes would generate noise.
- Rely only on a webhook state value: rejected because the webhook can fire without a meaningful content change.
- Attempt to identify the exact actor: rejected for this scope because available webhook data does not provide a reliable actor distinction, and the false-positive risk from rare operator edits is accepted.

### 4a. Serialize review-updated processing per review

**Decision:** Use Magento's `LockManagerInterface` in the review-updated webhook flow to acquire a mutex keyed by store ID and Fera review ID before loading the previous snapshot, comparing fields, publishing the queue message, and saving the current snapshot.

**Rationale:** Fera can send several identical `review_updated` webhooks almost simultaneously for a single customer edit. Without serialization, concurrent requests can all read the same previous snapshot before any request saves the new snapshot, causing duplicate review-update notification messages. The Magento installation uses the `db` lock provider, so `LockManagerInterface` gives a distributed mutex shared across web nodes without introducing custom locking infrastructure.

**Implementation plan:**

1. Inject `Magento\Framework\Lock\LockManagerInterface` into `Fera\Ai\Model\ReviewUpdatedWebhook`.
2. Build the current snapshot before acquiring the lock so the lock key can include the normalized review ID.
3. Use a lock name derived from store ID and review ID, for example `fera_review_updated_{storeId}_{sha256(reviewId)}`, to keep locks per review and avoid unsafe characters in lock names.
4. Acquire the lock with a short timeout, such as 10 seconds.
5. While holding the lock, execute the critical section: load previous snapshot, compare selected fields, evaluate notification gates, publish the review-update queue message when needed, and save the current snapshot.
6. Release the lock in `finally` so exceptions do not leave stale application-level locks.
7. If the lock cannot be acquired, return a retryable webhook error, such as HTTP 503, instead of silently returning success and losing a possible update.

**Alternatives considered:**

- Add locking in the review-update queue handler: rejected because duplicate queue messages are created before the handler runs.
- Use `SELECT ... FOR UPDATE` around the snapshot row: considered a strong option, but it requires larger repository/transaction changes and extra care for reviews whose snapshot row does not exist yet. `LockManagerInterface` is smaller in scope and works before a snapshot row exists.
- Silently skip webhook processing when a lock is already held: rejected because that could lose a real later update; a retryable error lets Fera resend after the active request has saved the snapshot.

### 5. Send review-update Slack notifications through a separate queue topic

**Decision:** Add a dedicated topic such as `fera.review.notify_updated` and handler for review-update Slack notifications.

**Rationale:** Review updates are a different event from negative-review creation. They should not depend on the negative-review threshold or reuse copy that identifies the event as a new negative review.

**Alternatives considered:**

- Reuse `fera.review.notify_negative`: rejected because the payload, title, diff content, and threshold rules differ.
- Send Slack synchronously from the webhook: rejected because webhook processing should persist state and enqueue work without blocking on external Slack HTTP calls.

### 6. Reuse Budsies action logic through shared providers

**Decision:** In the Budsies repo, extract MakerWare and Freshdesk button construction from `AddNegativeReviewSlackActionsObserver` into reusable provider services. Use those providers from both the existing negative-review observer and a new review-update action observer.

**Rationale:** The review-update notification needs the same Budsies-specific buttons as negative-review notifications. Extracting providers avoids duplicating order/plushie and Freshdesk contact lookup logic while preserving existing negative-review behavior.

**Alternatives considered:**

- Copy the existing private observer methods into a new observer: rejected because it duplicates integration logic and increases drift risk.
- Move MakerWare/Freshdesk buttons into the shared Fera module: rejected because those are Budsies-specific integrations and module dependencies.

## Risks / Trade-offs

- Operator or system edits to selected fields can trigger notifications -> Accepted compromise; the workflow reports every meaningful review change without attempting unreliable actor detection.
- Existing reviews may have no snapshot at deployment time -> On first update without a previous snapshot, save the snapshot and do not notify; future updates can then be detected.
- Snapshot persistence stores review text and media URLs -> Store only required fields, avoid logging field values, and do not store raw payloads.
- Snapshot text stored under `utf8mb3` can lose emoji and other 4-byte Unicode characters -> Convert the snapshot table to `utf8mb4` so comparison uses lossless database round-trips.
- Media changes may include removals or existing attachments as well as new uploads -> Render only newly attached media in Slack so operators see the customer-added files without repeating the full previous media list.
- Concurrent identical `review_updated` webhook requests can read the same previous snapshot and enqueue duplicate notifications -> Serialize review-updated snapshot compare/save work with a per-review Magento lock and return a retryable response when the lock cannot be acquired.
- Queue, schema, and config-path changes affect deployment order -> Deploy the Fera fork changes first, run schema upgrade/whitelist generation and config migration, then deploy Budsies enrichment changes.
- Renaming the existing enabled setting can change behavior if existing configuration is not migrated -> Copy legacy enabled values only into the negative-review enabled setting; keep review-update notifications disabled by default.
- Refactoring Budsies action logic could regress negative-review buttons -> Keep provider extraction behavior-preserving and cover existing negative-review scenarios with focused tests.

## Migration Plan

1. Part 1, Fera fork:
   - Add review snapshot schema and persistence services.
   - Add a schema patch that converts review snapshot storage to `utf8mb4`.
   - Update review-created webhook processing to save snapshots before notification gating.
   - Add review-updated webhook processing, per-review mutex locking, comparison, and queue publication.
   - Add review-update queue message/handler and base Slack payload/buttons.
   - Add separate enabled settings for negative-review and review-update notifications, default both to disabled, and migrate the legacy enabled value only to the negative-review setting.
   - Add/update Fera fork unit tests.
2. Release/update the shared Fera fork package in the Magento repo through the accepted package workflow.
3. Run Magento schema upgrade and regenerate the Fera schema whitelist when applying the Fera package change.
4. Part 2, Budsies repo:
   - Extract MakerWare/Freshdesk Slack action providers from existing `Budsies_Fera` observer logic.
   - Wire the existing negative-review observer to the providers.
   - Add a review-update action observer for the new Fera action-preparation event.
   - Add focused Budsies unit tests.
5. Validate that existing negative-review Slack notifications still include the same actions.

Rollback should roll back the Fera fork and Budsies repo changes together if the queue contract or event payload changes are incompatible. If only Budsies action enrichment fails, the Fera base review-update notification can still operate without MakerWare/Freshdesk buttons.

## Open Questions

- None for the accepted scope. The implementation should still verify the exact `review_updated` JWT action and payload shape against Fera test payloads during development.
