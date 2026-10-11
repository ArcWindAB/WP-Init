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
    $options = $oneuptime->sanitize(['enabled' => 1, 'banner_enabled' => 1, 'banner_public' => 1, 'banner_admin' => 1,
        'status_url' => 'https://example.com', 'status_page_id' => 'page-1',
        'show_incidents' => 1, 'show_announcements' => 1, 'show_maintenance' => 1]);
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
    $secret_oneuptime['heartbeat_url'] = 'https://example.com/heartbeat/secret-token';
    update_option(ArcWind_Init_OneUptime::KEY, $secret_oneuptime, false);
    foreach (['posthog', 'feedback', 'oneuptime', 'diagnostics'] as $tab) {
        $_GET['tab'] = $tab;
        ob_start();
        $plugin->render_settings_page();
        $html = ob_get_clean();
        arcwind_smoke_check(strpos($html, 'ArcWind Init') !== false, 'Settings tab did not render: ' . $tab);
        arcwind_smoke_check(strpos($html, 'secret-token') === false, 'Settings page leaked a secret: ' . $tab);
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
    ob_start();
    (new ArcWind_Init_OneUptime_Dashboard())->render();
    $dashboard = ob_get_clean();
    arcwind_smoke_check(strpos($dashboard, 'Smoke status') !== false, 'Dashboard did not render');
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
    remove_filter('pre_http_request', $offline, 10);
    echo "WordPress activation and failure smoke tests passed.\n";
} finally {
    update_option(ArcWind_Init::OPTION_KEY, $old_settings, false);
    update_option(ArcWind_Init_OneUptime::KEY, $old_oneuptime, false);
    restore_error_handler();
}
