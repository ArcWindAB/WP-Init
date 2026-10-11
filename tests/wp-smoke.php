<?php
/** Run inside an isolated WP-CLI installation: wp eval-file tests/wp-smoke.php */
if (!defined('ABSPATH')) throw new RuntimeException('WordPress is not loaded');
set_error_handler(static function ($severity, $message) { throw new ErrorException($message, 0, $severity); });
function arcwind_smoke_check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
arcwind_smoke_check(class_exists('ArcWind_Init') && class_exists('ArcWind_Init_OneUptime_Dashboard'), 'Plugin did not load');

$old_settings = get_option(ArcWind_Init::OPTION_KEY, []);
$old_oneuptime = get_option(ArcWind_Init_OneUptime::KEY, []);
try {
    if (getenv('ARCWIND_EXPECT_UPGRADE')) {
        arcwind_smoke_check(is_array($old_settings) && ($old_settings['feedback_uuid'] ?? '') === 'TEST1234', 'Upgrade lost feedback setting');
        arcwind_smoke_check(($old_settings['public_api_key'] ?? '') === 'phc_placeholder', 'Upgrade lost PostHog setting');
    }
    $plugin = new ArcWind_Init();
    $sanitized = $plugin->sanitize_settings(['feedback_enabled' => 0]);
    arcwind_smoke_check(isset($sanitized['feedback_enabled']) && $sanitized['feedback_enabled'] === 0, 'Feedback toggle failed');
    $oneuptime = new ArcWind_Init_OneUptime();
    $options = $oneuptime->sanitize(['host' => 'https://example.com', 'banner_public' => 1, 'banner_admin' => 1,
        'status_page_id' => 'page-1', 'public_incidents' => 1, 'admin_incidents' => 1,
        'public_announcements' => 1, 'admin_announcements' => 1, 'public_maintenance' => 1, 'admin_maintenance' => 1]);
    arcwind_smoke_check($options['enabled'] === 1, 'OneUptime settings sanitization failed');
    update_option(ArcWind_Init_OneUptime::KEY, $options, false);
    wp_set_current_user(1);
    require_once ABSPATH . 'wp-admin/includes/template.php';
    $plugin->register_settings();
    $oneuptime->register_settings();
    $secret_settings = (array) $old_settings;
    $secret_settings['personal_api_key'] = 'phx_secret-token';
    update_option(ArcWind_Init::OPTION_KEY, $secret_settings, false);
    $secret_oneuptime = $options;
    $secret_oneuptime['heartbeat_enabled'] = 1;
    $secret_oneuptime['heartbeat_secret'] = 'secret-token';
    $secret_oneuptime['heartbeat_url'] = 'https://example.com/heartbeat/secret-token';
    $secret_oneuptime['alerts_api_key'] = 'private-alert-key';
    update_option(ArcWind_Init_OneUptime::KEY, $secret_oneuptime, false);
    foreach (['posthog', 'feedback', 'oneuptime', 'diagnostics'] as $tab) {
        $_GET['tab'] = $tab;
        ob_start();
        $plugin->render_settings_page();
        $html = ob_get_clean();
        arcwind_smoke_check(strpos($html, 'ArcWind Init') !== false, 'Settings tab did not render: ' . $tab);
        if ($tab !== 'oneuptime') arcwind_smoke_check(strpos($html, 'secret-token') === false, 'Settings page leaked a secret: ' . $tab);
        arcwind_smoke_check(strpos($html, 'private-alert-key') === false, 'Settings page leaked the alert API key: ' . $tab);
        if ($tab === 'posthog') {
            arcwind_smoke_check(substr_count($html, '<table class="form-table"') === 1, 'PostHog settings did not use one table');
            foreach (['arcwind_init_connection_fields', 'arcwind_init_frontend_fields', 'arcwind_init_main_fields', 'arcwind_init_errors_fields'] as $section) {
                arcwind_smoke_check(strpos($html, 'aria-controls="' . $section . '"') !== false && strpos($html, 'id="' . $section . '"') !== false, 'PostHog settings section missing: ' . $section);
            }
            foreach (['api_host', 'ui_host', 'region', 'defaults', 'person_profiles'] as $field) {
                arcwind_smoke_check(strpos($html, 'arcwind_init_settings[' . $field . ']') !== false, 'PostHog field missing: ' . $field);
            }
            arcwind_smoke_check(strpos($html, 'arcwind_init_settings[host]') === false, 'Old PostHog server host was still shown');
            arcwind_smoke_check(strpos($html, 'name="arcwind_init_settings[personal_api_key_clear]"') !== false, 'Saved PostHog API key did not have a remove button');
            arcwind_smoke_check(strpos($html, 'name="arcwind_init_settings[personal_api_key]"') === false, 'PostHog API key input appeared while a key was saved');
        }
        if ($tab === 'oneuptime') {
            arcwind_smoke_check(substr_count($html, '<table class="form-table"') === 1, 'OneUptime settings did not use one table');
            foreach (['host', 'status_page_id', 'public_incidents', 'admin_incidents', 'heartbeat_secret', 'alerts_admin'] as $field) {
                arcwind_smoke_check(strpos($html, 'arcwind_init_oneuptime[' . $field . ']') !== false, 'OneUptime field missing: ' . $field);
            }
            arcwind_smoke_check(strpos($html, 'arcwind_init_oneuptime[alerts_api_key_clear]') !== false, 'Saved alert key did not have a remove button');
            arcwind_smoke_check(strpos($html, 'name="arcwind_init_oneuptime[alerts_api_key]"') === false, 'Alert key input appeared while a key was saved');
        }
    }
    ArcWind_Init_OneUptime::activate();
    arcwind_smoke_check(wp_next_scheduled(ArcWind_Init_OneUptime::HOOK) !== false, 'Heartbeat was not scheduled');
    ArcWind_Init_OneUptime::deactivate();
    arcwind_smoke_check(wp_next_scheduled(ArcWind_Init_OneUptime::HOOK) === false, 'Heartbeat remained after deactivation');
    update_option(ArcWind_Init_OneUptime::KEY, $options, false);
    delete_transient(ArcWind_Init_OneUptime_Dashboard::CACHE);
    delete_transient(ArcWind_Init_OneUptime::CACHE);
    $mock = static function ($pre, $args, $url) {
        if (strpos($url, 'example.com/status-page-api/overview/') !== false) $body = ['statusPage' => ['name' => 'Smoke status'], 'overallStatus' => ['name' => 'Operational'], 'statusPageResources' => []];
        elseif (strpos($url, 'example.com/status-page-api/uptime/') !== false) $body = ['statusPageResourceUptimes' => []];
        elseif (strpos($url, 'example.com/status-page-api/incidents/') !== false) $body = ['incidents' => [['title' => 'Smoke incident', 'currentIncidentState' => ['name' => 'Investigating']]]];
        elseif (strpos($url, 'example.com/status-page-api/announcements/') !== false) $body = ['announcements' => []];
        elseif (strpos($url, 'example.com/status-page-api/scheduled-maintenance-events/') !== false) $body = ['scheduledMaintenanceEvents' => []];
        else return new WP_Error('offline', 'External service unavailable');
        return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    };
    add_filter('pre_http_request', $mock, 10, 3);
    $oneuptime->refresh_messages();
    (new ArcWind_Init_OneUptime_Dashboard())->refresh_overview();
    ob_start();
    (new ArcWind_Init_OneUptime_Dashboard())->render();
    $dashboard = ob_get_clean();
    arcwind_smoke_check(strpos($dashboard, 'Operational') !== false && strpos($dashboard, '<details') !== false, 'Dashboard did not render');
    ob_start();
    $oneuptime->render_banner();
    $banner = ob_get_clean();
    arcwind_smoke_check(strpos($banner, 'Smoke incident') !== false, 'Banner did not render');
    remove_filter('pre_http_request', $mock, 10);

    update_option(ArcWind_Init::OPTION_KEY, [
        'host' => 'https://example.com', 'project_id' => '123',
        'personal_api_key' => 'phx_test_secret', 'public_api_key' => 'phc_test_public',
        'enable_tracking' => 1, 'enable_error_tracking' => 1,
        'feedback_enabled' => 1, 'feedback_uuid' => 'TEST1234',
        'load_position' => 'head',
    ], false);
    delete_transient(ArcWind_Init_OneUptime_Dashboard::CACHE);
    delete_transient(ArcWind_Init_OneUptime::CACHE);
    delete_transient('arcwind_init_stats_v1');
    $offline = static function () { return new WP_Error('offline', 'External service unavailable'); };
    add_filter('pre_http_request', $offline, 10, 3);
    (new ArcWind_Init_OneUptime_Dashboard())->refresh_overview();
    ob_start();
    (new ArcWind_Init_OneUptime_Dashboard())->render();
    $offline_html = ob_get_clean();
    arcwind_smoke_check(strpos($offline_html, 'ej aktuell') !== false || strpos($offline_html, 'otillgängligt') !== false, 'Offline status was not handled');
    ob_start();
    $oneuptime->render_banner();
    ob_end_clean();
    ob_start();
    $plugin->render_dashboard_widget();
    $posthog_html = ob_get_clean();
    arcwind_smoke_check(strpos($posthog_html, '—') !== false, 'PostHog outage was not isolated');
    ob_start();
    $plugin->render_feedback_widget();
    $feedback_html = ob_get_clean();
    arcwind_smoke_check(strpos($feedback_html, 'TEST1234') !== false, 'Feedback broke during external outages');
    ob_start();
    $plugin->maybe_print_posthog_snippet_universal();
    $tracking_html = ob_get_clean();
    arcwind_smoke_check(strpos($tracking_html, 'phc_test_public') !== false, 'Tracking snippet failed during external outages');
    $plugin->enqueue_tracking_bridge();
    $tracker_script = wp_scripts()->registered['arcwind-init-tracker']->src ?? '';
    arcwind_smoke_check(strpos($tracker_script, '/arcwind-init/assets/js/arcwind-init-tracker.js') !== false &&
        strpos($tracker_script, '/includes/assets/') === false, 'Tracker script URL did not point to the plugin root');
    remove_filter('pre_http_request', $offline, 10);

    $oneuptime_failure_options = $options;
    $oneuptime_failure_options['alerts_admin'] = 1;
    $oneuptime_failure_options['alerts_api_key'] = 'private-test-key';
    update_option(ArcWind_Init_OneUptime::KEY, $oneuptime_failure_options, false);
    $failures = [
        new WP_Error('offline', 'secret-response-text'),
        ['headers' => [], 'body' => 'rate limited', 'response' => ['code' => 429, 'message' => 'Too Many Requests']],
        ['headers' => [], 'body' => '{bad json', 'response' => ['code' => 200, 'message' => 'OK']],
        ['headers' => [], 'body' => ['unexpected' => 'array'], 'response' => ['code' => 200, 'message' => 'OK']],
        'exception',
    ];
    foreach ($failures as $failure) {
        $failure_mock = static function () use ($failure) {
            if ($failure === 'exception') throw new RuntimeException('secret-response-text');
            return $failure;
        };
        add_filter('pre_http_request', $failure_mock, 10, 3);
        foreach ([ArcWind_Init_OneUptime::CACHE, ArcWind_Init_OneUptime::ALERT_CACHE,
            ArcWind_Init_OneUptime_Dashboard::CACHE, ArcWind_Init_OneUptime_Dashboard::FAILURE_CACHE,
            'arcwind_init_stats_v1'] as $cache) delete_transient($cache);
        $started = microtime(true);
        ob_start();
        $oneuptime->render_banner();
        $oneuptime->render_admin_notice();
        (new ArcWind_Init_OneUptime_Dashboard())->render();
        $plugin->render_dashboard_widget();
        $plugin->render_feedback_widget();
        $html = ob_get_clean();
        arcwind_smoke_check(microtime(true) - $started < 2, 'External failures delayed page rendering');
        arcwind_smoke_check(strpos($html, 'TEST1234') !== false && strpos($html, 'secret-response-text') === false, 'External failure broke Feedback or leaked a response');
        $oneuptime->refresh_messages();
        (new ArcWind_Init_OneUptime_Dashboard())->refresh_overview();
        ArcWind_Init_OneUptime::refresh_alerts();
        $plugin->refresh_posthog_stats();
        remove_filter('pre_http_request', $failure_mock, 10);
    }
    echo "WordPress activation and failure smoke tests passed.\n";
} finally {
    update_option(ArcWind_Init::OPTION_KEY, $old_settings, false);
    update_option(ArcWind_Init_OneUptime::KEY, $old_oneuptime, false);
    restore_error_handler();
}
