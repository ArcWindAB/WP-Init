<?php
/** Run only in an isolated WordPress installation before HTTP route checks. */
if (!defined('ABSPATH')) throw new RuntimeException('WordPress is not loaded');

update_option(ArcWind_Init::OPTION_KEY, [
    'api_host' => 'https://posthog.example.test',
    'ui_host' => 'https://posthog.example.test',
    'project_id' => '123',
    'personal_api_key' => 'phx_test_secret',
    'public_api_key' => 'phc_test_public',
    'enable_tracking' => 1,
    'feedback_enabled' => 1,
    'feedback_uuid' => 'TEST1234',
], false);
update_option(ArcWind_Init_OneUptime::KEY, [
    'enabled' => 1,
    'host' => 'https://oneuptime.example.test',
    'status_page_id' => 'page-1',
    'status_url' => 'https://oneuptime.example.test/status-page/page-1',
    'banner_enabled' => 1,
    'banner_public' => 1,
    'banner_admin' => 1,
    'public_incidents' => 1,
    'admin_incidents' => 1,
    'alerts_admin' => 1,
    'alerts_api_key' => 'test-alert-key',
], false);
foreach ([ArcWind_Init_OneUptime::CACHE, ArcWind_Init_OneUptime::ALERT_CACHE,
    ArcWind_Init_OneUptime_Dashboard::CACHE, ArcWind_Init_OneUptime_Dashboard::FAILURE_CACHE,
    'arcwind_init_stats_v1'] as $cache) delete_transient($cache);
