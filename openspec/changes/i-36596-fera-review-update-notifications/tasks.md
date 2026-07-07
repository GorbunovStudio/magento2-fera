## 1. Part 1 - Fera Fork Snapshot Foundation

- [ ] 1.1 Add a review snapshot declarative schema table keyed by Fera `review_id`, storing `heading`, `body`, `rating`, normalized `media`, and timestamps.
- [ ] 1.2 Add snapshot model/resource or equivalent persistence service for loading and upserting the latest snapshot by `review_id`.
- [ ] 1.3 Add a snapshot builder that extracts `review_id`, `heading`, `body`, `rating`, and `media` from Fera review webhook payloads.
- [ ] 1.4 Normalize `media` to a deterministic list of `id` and full media `url` values before comparison and persistence.
- [ ] 1.5 Add a snapshot comparator that returns changed fields with before/after values for `rating`, `heading`, `body`, and normalized `media`.

## 2. Part 1 - Fera Fork Webhooks and Notification Flow

- [ ] 2.1 Update the review-created webhook flow to persist snapshots before rating threshold checks and before review notification delivery gating.
- [ ] 2.2 Ensure review-created webhook processing saves snapshots even when review notifications are disabled, without publishing negative-review notifications in that case.
- [ ] 2.3 Add a verified anonymous `review_updated` webhook route, service contract, and model using `FeraWebhookJwtValidator` with action `review_updated`.
- [ ] 2.4 Implement review-updated processing to load the previous snapshot, compare selected fields, save the current snapshot, and publish a notification only when all required notification conditions pass.
- [ ] 2.5 Ensure review-updated processing saves snapshots when no previous snapshot exists, when `state !== pending_update`, when no selected fields changed, and when review notifications are disabled.
- [ ] 2.6 Add a dedicated review-update queue topic, publisher, topology binding, message contract, and handler.
- [ ] 2.7 Build the base review-update Slack payload with title, review context, `Review before changes` and `After changes` groups, and changed fields ordered as `rating`, `heading`, `body`, `media`.
- [ ] 2.8 Add base Slack actions for `View in Fera` and `View Magento Order` when their URLs can be resolved.
- [ ] 2.9 Add a review-update Slack action preparation event carrying message, resolved order, store ID, and mutable actions container.
- [ ] 2.10 Add or update Fera fork unit tests for review-created snapshot persistence, disabled notification behavior, review-updated comparison/gating, queue publication, Slack formatting, and base actions.

## 3. Part 2 - Budsies Repo Action Enrichment

- [x] 3.1 Extract MakerWare Slack action creation from `Budsies\Fera\Observer\AddNegativeReviewSlackActionsObserver` into a reusable provider service without changing negative-review behavior.
- [x] 3.2 Extract Freshdesk Slack action creation from `Budsies\Fera\Observer\AddNegativeReviewSlackActionsObserver` into a reusable provider service without changing negative-review behavior.
- [x] 3.3 Update the existing negative-review action observer to use the new MakerWare and Freshdesk action providers.
- [x] 3.4 Add a new Budsies observer for the Fera review-update Slack action preparation event.
- [x] 3.5 Use the shared providers in the review-update observer to add `View in MakerWare` and `View Freshdesk Tickets` when the reviewed plushie or Freshdesk contact can be resolved.
- [x] 3.6 Ensure MakerWare/Freshdesk enrichment failures omit only the failed optional action and do not block the base review-update notification.
- [x] 3.7 Add or update Budsies unit tests covering negative-review action preservation and review-update MakerWare/Freshdesk action enrichment.

## 4. Integration, Rollout, and Validation

- [ ] 4.1 Release or otherwise make the Fera fork changes available to the Magento repo through the accepted package workflow without editing `vendor/` directly.
- [ ] 4.2 Update the Magento repo to consume the Fera fork changes and verify the shared queue/event contracts match the Budsies observer expectations.
- [ ] 4.3 Run `bin/magento setup:upgrade --keep-generated` after the Fera schema change is present.
- [ ] 4.4 Regenerate the Fera declarative schema whitelist with `bin/magento setup:db-declaration:generate-whitelist --module-name=Fera_Ai`.
- [ ] 4.5 Validate Fera queue XML files: `communication.xml`, `queue_publisher.xml`, `queue_topology.xml`, and `queue_consumer.xml`.
- [ ] 4.6 Validate Fera `webapi.xml` keeps the review-updated endpoint anonymous only as a verified webhook with mandatory JWT validation.
- [ ] 4.7 Run focused Fera and Budsies unit tests for the changed webhook, queue, Slack handler, and action-enrichment behavior.
- [x] 4.8 Run PHPStan on uncommitted Budsies PHP files if Part 2 changes touch `app/code/Budsies/**/*.php`.
- [x] 4.9 Verify existing negative-review Slack notifications still send with the same base and Budsies-specific action buttons.
