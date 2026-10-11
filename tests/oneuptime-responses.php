<?php
/** Run: php tests/oneuptime-responses.php */
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

$options = ['enabled' => 1, 'status_url' => 'https://status.example.test', 'status_page_id' => 'page-1',
    'banner_enabled' => 1, 'show_incidents' => 1, 'show_announcements' => 1, 'show_maintenance' => 1];
$transients = [];
$responses = [];
$request_count = 0;
$scheduled_history = false;
$widget_title = null;
$alert_requests = [];
$can_manage_options = true;
$log = tempnam(sys_get_temp_dir(), 'oneuptime-log-');
ini_set('log_errors', '1');
ini_set('error_log', $log);
set_error_handler(function ($severity, $message) { throw new ErrorException($message, 0, $severity); });

function get_option($key, $default = []) { global $options; return $options; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $transients; $transients[$key] = $value; }
function wp_parse_url($url) { return parse_url($url); }
function wp_json_encode($value) { return json_encode($value); }
function wp_next_scheduled($hook) { global $scheduled_history; return $scheduled_history; }
function wp_schedule_single_event($time, $hook) { global $scheduled_history; $scheduled_history = $time; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_safe_remote_get($url, $args) { return test_response($url); }
function wp_safe_remote_post($url, $args) {
    global $alert_requests;
    if (strpos($url, '/api/alert') !== false) $alert_requests[] = [$url, $args];
    return test_response($url);
}
function test_response($url) {
    global $responses, $request_count;
    $request_count++;
    if (preg_match('~/api/(alert-state|alert)/get-list~', $url, $matches)) return $responses[$matches[1]] ?? new WP_Error('unavailable', 'secret-token');
    if (!preg_match('~/status-page-api/([^/]+)/~', $url, $matches)) throw new Exception('Unexpected URL');
    return $responses[$matches[1]] ?? new WP_Error('unavailable', 'secret-token');
}
function esc_html($value) { if (!is_string($value)) throw new Exception('Non-string HTML value'); return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_js($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $value)); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { if (!is_string($value)) throw new Exception('Non-string title'); return trim(strip_tags($value)); }
function number_format_i18n($value, $decimals) { return number_format($value, $decimals); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_current_user_id() { return 1; }
function current_user_can($capability) { global $can_manage_options; return $capability === 'manage_options' && $can_manage_options; }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function wp_add_dashboard_widget($id, $title) { global $widget_title; if ($id === 'arcwind_init_oneuptime_status') $widget_title = $title; }

require_once dirname(__DIR__) . '/includes/class-arcwind-init-oneuptime.php';
require_once dirname(__DIR__) . '/includes/class-arcwind-init-oneuptime-dashboard.php';

function check($condition, $message) { if (!$condition) throw new Exception($message); }
function respond($body, $code = 200) { return ['code' => $code, 'body' => $body]; }
function fixture($name) {
    $body = file_get_contents(__DIR__ . '/fixtures/oneuptime-' . $name . '.json');
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}
function load_fixture($name) {
    global $responses, $transients;
    $transients = [];
    $payloads = fixture($name);
    $responses = [];
    foreach ($payloads as $endpoint => $payload) $responses[$endpoint] = respond(json_encode($payload));
    (new ArcWind_Init_OneUptime())->refresh_messages();
    (new ArcWind_Init_OneUptime_Dashboard())->refresh_overview();
}
function render_pair() {
    $banner = new ArcWind_Init_OneUptime();
    $dashboard = new ArcWind_Init_OneUptime_Dashboard();
    ob_start();
    $banner->render_banner();
    $banner_html = ob_get_clean();
    ob_start();
    $banner->render_admin_notice();
    $admin_notice = ob_get_clean();
    ob_start();
    $dashboard->render();
    $dashboard_html = ob_get_clean();
    return [$banner_html, $dashboard_html, $admin_notice];
}
function add_action() {}
function add_filter() {}
function do_settings_fields() {}

try {
    $saved_options = $options;
    $options['host'] = '';
    $options['status_url'] = '';
    ob_start();
    ArcWind_Init_OneUptime::render_settings_table();
    $no_host_settings = ob_get_clean();
    check((bool) preg_match('/aria-controls="arcwind_oneuptime_heartbeat_fields" aria-expanded="false" disabled/', $no_host_settings), 'Heartbeat was not locked when OneUptime host was missing');
    check((bool) preg_match('/aria-controls="arcwind_oneuptime_connection_fields"[^>]*>.*?<\/button><span class="arcwind-section-status arcwind-section-status-error"/', $no_host_settings), 'Incomplete OneUptime status page was not marked as needing configuration');
    check(strpos($no_host_settings, 'Fyll i OneUptime-värd för att låsa upp heartbeat.') !== false, 'Heartbeat host hint was missing');
    $options['host'] = 'https://status.example.test';
    $options['status_page_id'] = '';
    ob_start();
    ArcWind_Init_OneUptime::render_settings_table();
    $host_only_settings = ob_get_clean();
    check((bool) preg_match('/aria-controls="arcwind_oneuptime_heartbeat_fields" aria-expanded="false"><span/', $host_only_settings), 'Heartbeat remained locked when host was provided');
    check((bool) preg_match('/aria-controls="arcwind_oneuptime_information_fields" aria-expanded="false" disabled/', $host_only_settings), 'Driftinformation unlocked without status page ID');
    $options = $saved_options;
    ob_start();
    ArcWind_Init_OneUptime::render_settings_table();
    $configured_settings = ob_get_clean();
    check((bool) preg_match('/aria-controls="arcwind_oneuptime_connection_fields"[^>]*>.*?<\/button><span class="arcwind-section-status arcwind-section-status-active"/', $configured_settings), 'Configured OneUptime status page was not marked active');
    check((bool) preg_match('/aria-controls="arcwind_oneuptime_heartbeat_fields"[^>]*>.*?<\/button><span class="arcwind-section-status arcwind-section-status-inactive"/', $configured_settings), 'Disabled OneUptime heartbeat was not marked inactive');
    load_fixture('valid');
    [$banner, $dashboard, $notice] = render_pair();
    check(ArcWind_Init_OneUptime::options()['status_url'] === 'https://status.example.test/status-page/page-1', 'Legacy status URL was not migrated to a public page link');
    (new ArcWind_Init_OneUptime_Dashboard())->register_widget();
    check($widget_title === 'Example status', 'Widget title did not use status page name');
    check(strpos($dashboard, 'OneUptime') === false && substr_count($dashboard, '<details') >= 3, 'Widget was not white labeled and collapsible');
    check(strpos($banner, 'Open incident') !== false && strpos($banner, 'Planned work') !== false, 'Valid banner missing messages');
    check(strpos($notice, 'Open incident') !== false, 'Valid admin notice missing message');
    $options['public_incidents'] = 0;
    $options['admin_incidents'] = 1;
    $transients = [];
    (new ArcWind_Init_OneUptime())->refresh_messages();
    (new ArcWind_Init_OneUptime_Dashboard())->refresh_overview();
    [$public_filtered, , $admin_filtered] = render_pair();
    check(strpos($public_filtered, 'Open incident') === false && strpos($admin_filtered, 'Open incident') !== false, 'Separate incident controls did not isolate public and admin');
    $options['public_incidents'] = 1;
    $options['admin_incidents'] = 0;
    [$public_cached, , $admin_cached] = render_pair();
    check(strpos($public_cached, 'Open incident') !== false && strpos($admin_cached, 'Open incident') === false, 'Cached incidents ignored destination controls');
    unset($options['public_incidents'], $options['admin_incidents']);
    check(strpos($dashboard, 'Website') !== false && strpos($dashboard, 'Operational') !== false, 'Valid dashboard missing resource');
    check($scheduled_history !== false, 'History was not scheduled in the background');
    $history_dashboard = new ArcWind_Init_OneUptime_Dashboard();
    $requests_before_history = $request_count;
    $history_dashboard->refresh_history();
    check($request_count === $requests_before_history + 1, 'More than one history window was requested');
    check(isset($transients[ArcWind_Init_OneUptime_Dashboard::HISTORY_CACHE]['windows']['7d']) && count($transients[ArcWind_Init_OneUptime_Dashboard::HISTORY_CACHE]['windows']) === 1, 'Seven-day history was not fetched alone');
    ob_start();
    $history_dashboard->render();
    $history_html = ob_get_clean();
    check(strpos($history_html, 'Senaste 7 dagarna: 99.95%') !== false && strpos($history_html, '30 dagar') === false, 'Seven-day uptime was not rendered alone');
    unset($transients[ArcWind_Init_OneUptime_Dashboard::CACHE]);
    $responses['overview'] = new WP_Error('network', 'secret-token');
    (new ArcWind_Init_OneUptime_Dashboard())->refresh_overview();
    [, $stale_dashboard] = render_pair();
    (new ArcWind_Init_OneUptime_Dashboard())->register_widget();
    check($widget_title === 'Example status', 'Stale status page title was lost');
    check(strpos($stale_dashboard, 'ej aktuell') !== false && strpos($stale_dashboard, 'Website') !== false, 'Last good overview not marked stale');
    $requests_after_failure = $request_count;
    render_pair();
    check($request_count === $requests_after_failure, 'Failure backoff did not prevent repeated requests');

    load_fixture('nested');
    [$banner, $dashboard] = render_pair();
    (new ArcWind_Init_OneUptime_Dashboard())->register_widget();
    check($widget_title === 'Nested status', 'Nested status page title was not used');
    check(strpos($banner, 'Nested notice') !== false, 'Wrapped date was not parsed');
    check(strpos($dashboard, 'API') !== false && strpos($dashboard, 'Degraded') !== false, 'Nested current status was not parsed');
    check(substr_count($dashboard, 'Worker') === 1 && strpos($dashboard, 'Backend') < strpos($dashboard, 'Jobs'), 'Nested group was not rendered once under its parent');
    $nested_history = new ArcWind_Init_OneUptime_Dashboard();
    $nested_history->refresh_history();
    ob_start();
    $nested_history->render();
    $nested_html = ob_get_clean();
    check(strpos($nested_html, 'Senaste 7 dagarna: 98.50%') !== false, 'Nested seven-day uptime was not parsed');
    check(strpos($nested_html, 'Senaste 7 dagarna: uppgift saknas') !== false, 'Missing uptime was not labeled unavailable');

    load_fixture('malformed');
    [$banner, $dashboard] = render_pair();
    (new ArcWind_Init_OneUptime_Dashboard())->register_widget();
    check($widget_title === 'Driftstatus', 'Malformed title did not use safe fallback');
    check($banner === '', 'Malformed records appeared in banner');
    check(strpos($dashboard, 'Komponenter och tjänster') !== false, 'Malformed dashboard did not render');

    foreach ([new WP_Error('network', 'secret-token'), respond('', 500), respond(''), respond('{bad json'), respond('{}')] as $failure) {
        load_fixture('valid');
        global $responses;
        $responses['overview'] = $failure;
        unset($transients[ArcWind_Init_OneUptime_Dashboard::CACHE]);
        (new ArcWind_Init_OneUptime_Dashboard())->refresh_overview();
        [, $dashboard] = render_pair();
        check(strpos($dashboard, 'role="alert"') !== false || strpos($dashboard, 'role="status"') !== false, 'Overview failure had no safe fallback');
        check(strpos($dashboard, 'secret-token') === false, 'Response detail leaked to dashboard');
    }

    load_fixture('valid');
    $responses['uptime'] = respond('{bad json');
    unset($transients[ArcWind_Init_OneUptime_Dashboard::CACHE]);
    (new ArcWind_Init_OneUptime_Dashboard())->refresh_overview();
    [, $dashboard] = render_pair();
    check(strpos($dashboard, 'Website') !== false, 'Uptime failure broke overview');

    load_fixture('valid');
    foreach (['incidents', 'announcements', 'scheduled-maintenance-events'] as $endpoint) {
        $responses[$endpoint] = new WP_Error('network', 'secret-token');
    }
    unset($transients[ArcWind_Init_OneUptime::CACHE]);
    (new ArcWind_Init_OneUptime())->refresh_messages();
    [$banner, $dashboard] = render_pair();
    check($banner === '' && strpos($dashboard, 'Website') !== false, 'Banner API failure broke a page');

    load_fixture('valid');
    $options['alerts_admin'] = 1;
    $options['alerts_api_key'] = 'private-alert-key';
    $alert_page = fixture('valid')['overview'];
    $alert_page['statusPageResources'][0]['monitorId'] = 'page-monitor';
    $alert_page['statusPageResources'][] = ['monitorGroupId' => 'page-group'];
    $alert_page['monitorsInGroup'] = ['page-group' => [['monitorId' => 'group-monitor']]];
    $responses['overview'] = respond(json_encode($alert_page));
    check(ArcWind_Init_OneUptime::status_page_monitor_ids($alert_page) === ['page-monitor', 'group-monitor'], 'Status page monitor groups were not expanded');
    $mapped_page = $alert_page;
    $mapped_page['monitorsInGroup'] = ['page-group' => ['mapped-monitor' => ['currentStatus' => ['name' => 'Operational']]], 'unlisted-group' => ['outside-monitor']];
    check(ArcWind_Init_OneUptime::status_page_monitor_ids($mapped_page) === ['page-monitor', 'mapped-monitor'], 'Only referenced monitor groups should contribute monitors');
    $responses['alert-state'] = respond(json_encode(['data' => [
        ['_id' => 'open', 'name' => 'Created', 'isResolvedState' => false],
        ['_id' => 'resolved', 'name' => 'Resolved', 'isResolvedState' => true],
    ]]));
    $responses['alert'] = respond(json_encode(['data' => [
        ['_id' => 'a1', 'title' => 'Database alert', 'description' => "Database connection failed.\nInvestigate the server.", 'currentAlertStateId' => 'open', 'monitorId' => 'page-monitor'],
        ['_id' => 'a2', 'title' => 'Other project alert', 'currentAlertStateId' => 'open', 'monitorId' => 'other-monitor'],
        ['_id' => 'a3', 'title' => 'Group alert', 'description' => '<script>alert(1)</script>', 'currentAlertStateId' => 'open', 'monitorId' => 'group-monitor'],
        ['_id' => 'a4', 'title' => 'Old resolved alert', 'currentAlertStateId' => 'resolved', 'monitorId' => 'page-monitor'],
        ['_id' => 'a5', 'title' => 'Alert without description', 'currentAlertStateId' => 'open', 'monitorId' => 'page-monitor'],
    ]]));
    ArcWind_Init_OneUptime::refresh_alerts();
    [$public_alerts, , $admin_alerts] = render_pair();
    check(strpos($admin_alerts, 'Database alert') !== false && strpos($admin_alerts, 'Group alert') !== false && strpos($admin_alerts, 'Old resolved alert') === false, 'Admin alerts did not filter resolved state or include page groups');
    check(substr_count($admin_alerts, '<details') === 3 && strpos($admin_alerts, '<summary style="cursor:pointer;font-weight:600">Database alert') !== false, 'Alerts are not individually expandable by title');
    check(strpos($admin_alerts, "Database connection failed.\nInvestigate the server.") !== false && strpos($admin_alerts, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($admin_alerts, '<script>alert(1)</script>') === false, 'Alert descriptions were not displayed safely');
    check(strpos($admin_alerts, 'Alert without description') !== false && strpos($admin_alerts, 'Ingen beskrivning finns.') !== false, 'Alert without description displayed an empty panel');
    $alert_query = json_decode($alert_requests[1][1]['body'], true);
    check(!empty($alert_query['select']['description']), 'Alert API request did not select description');
    check(strpos($admin_alerts, 'notice-warning inline') !== false, 'Alert notice can be moved after page load');
    check(strpos($admin_alerts, 'Other project alert') === false, 'Alert from a monitor outside the status page was displayed');
    check(strpos($public_alerts, 'Database alert') === false && strpos($admin_alerts, 'private-alert-key') === false, 'Private alert data appeared publicly');
    check(count($alert_requests) === 2 && $alert_requests[0][1]['headers']['ApiKey'] === 'private-alert-key', 'Authenticated alert requests were not sent');
    $_GET['page'] = 'arcwind_init';
    [, , $settings_hook_notice] = render_pair();
    check(strpos($settings_hook_notice, 'Database alert') === false, 'Alert notice appeared above the settings page title');
    ob_start();
    ArcWind_Init_OneUptime::render_alert_notice();
    $settings_notice = ob_get_clean();
    check(strpos($settings_notice, 'Database alert') !== false && strpos($settings_notice, 'notice-warning inline') !== false, 'Alert notice was not available in the settings layout');
    unset($_GET['page']);
    $can_manage_options = false;
    [, , $editor_notice] = render_pair();
    check(strpos($editor_notice, 'Database alert') === false, 'Private alerts appeared for a non-administrator');
    $can_manage_options = true;
    render_pair();
    check(count($alert_requests) === 2, 'Admin alerts were not cached');
    unset($transients[ArcWind_Init_OneUptime::ALERT_CACHE]);
    $responses['alert-state'] = respond('{bad json');
    ArcWind_Init_OneUptime::refresh_alerts();
    [, , $failed_alerts] = render_pair();
    check(strpos($failed_alerts, 'Database alert') === false, 'Malformed alert API response was displayed');
    unset($transients[ArcWind_Init_OneUptime::ALERT_CACHE]);
    $responses['overview'] = respond(json_encode(['statusPageResources' => []]));
    $private_requests_before_empty_page = count($alert_requests);
    ArcWind_Init_OneUptime::refresh_alerts();
    [, , $empty_page_alerts] = render_pair();
    check(strpos($empty_page_alerts, 'Database alert') === false && count($alert_requests) === $private_requests_before_empty_page, 'Missing page monitors did not fail closed');
    $options['alerts_admin'] = 0;
    unset($options['alerts_api_key']);
    $requests_before_disabled = count($alert_requests);
    render_pair();
    check(count($alert_requests) === $requests_before_disabled, 'Disabled alerts still contacted the private API');

    global $transients, $log;
    $transients[ArcWind_Init_OneUptime::CACHE] = [['type' => ['bad'], 'title' => ['bad'], 'url' => ['bad']]];
    [$banner] = render_pair();
    check($banner === '', 'Malformed cached message appeared');
    check(strpos(file_get_contents($log), 'secret-token') === false && strpos(file_get_contents($log), 'private-alert-key') === false, 'Sensitive error text was logged');
    echo "OneUptime response regression tests passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    unlink($log);
}
