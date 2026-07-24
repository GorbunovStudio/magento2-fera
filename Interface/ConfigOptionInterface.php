<?php

declare(strict_types=1);

namespace Fera\Ai\Interface;

interface ConfigOptionInterface
{
    public const ENABLED = 'fera_ai/general/enabled';
    public const DEBUG_MODE = 'fera_ai/general/debug_mode';
    public const PUBLIC_KEY = 'fera_ai/fera_ai_group/public_key';
    public const SECRET_KEY = 'fera_ai/fera_ai_group/secret_key';
    public const APP_URL = 'fera_ai/fera_ai_group/app_url';
    public const API_URL = 'fera_ai/fera_ai_group/api_url';
    public const JS_URL = 'fera_ai/fera_ai_group/js_url';
    public const EXPORT_ORDER_ON_CREATION = 'fera_ai/sync_settings/orders_export_on_creation';
    public const MINIMIZE_DATA_SHARING = 'fera_ai/sync_settings/minimize_data_sharing';
    public const FULFILLMENT_EXPORT_DELAY_DAYS = 'fera_ai/sync_settings/fulfillment_export_delay_days';
    public const EXPORT_BRAND = 'fera_ai/sync_settings/brand';
    public const LEGACY_REVIEW_NOTIFICATIONS_ENABLED = 'fera_ai/review_notifications/enabled';
    public const NEGATIVE_REVIEW_NOTIFICATIONS_ENABLED = 'fera_ai/review_notifications/negative_review_notifications_enabled';
    public const REVIEW_UPDATE_NOTIFICATIONS_ENABLED = 'fera_ai/review_notifications/review_update_notifications_enabled';
    public const REVIEW_NOTIFICATIONS_RATING_THRESHOLD = 'fera_ai/review_notifications/rating_threshold';
    public const REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL = 'fera_ai/review_notifications/slack_webhook_url';
    public const REVIEW_NOTIFICATIONS_EMAIL_RECIPIENTS = 'fera_ai/review_notifications/email_recipients';
    public const POSITIVE_REVIEW_NOTIFICATIONS_ENABLED = 'fera_ai/positive_review_notifications/enabled';
    public const POSITIVE_REVIEW_NOTIFICATIONS_RATING_THRESHOLD = 'fera_ai/positive_review_notifications/rating_threshold';
    public const POSITIVE_REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL = 'fera_ai/positive_review_notifications/slack_webhook_url';
}
