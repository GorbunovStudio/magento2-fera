<?php

declare(strict_types=1);

namespace Fera\Ai\Interfaces;

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
}
