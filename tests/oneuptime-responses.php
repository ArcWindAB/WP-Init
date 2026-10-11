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
function wp_safe_remote_post($url, $args) { return test_response($url); }
function test_response($url) {
    global $responses, $request_count;
    $request_count++;
    if (!preg_match('~/status-page-api/([^/]+)/~', $url, $matches)) throw new Exception('Unexpected URL');
    return $responses[$matches[1]] ?? new WP_Error('unavailable', 'secret-token');
}
function esc_html($value) { if (!is_string($value)) throw new Exception('Non-string HTML value'); return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_js($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function sanitize_text_field($value) { if (!is_string($value)) throw new Exception('Non-string title'); return trim(strip_tags($value)); }
function number_format_i18n($value, $decimals) { return number_format($value, $decimals); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_current_user_id() { return 1; }
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

try {
    load_fixture('valid');
    [$banner, $dashboard, $notice] = render_pair();
    (new ArcWind_Init_OneUptime_Dashboard())->register_widget();
    check($widget_title === 'Example status', 'Widget title did not use status page name');
    check(strpos($dashboard, 'OneUptime') === false && substr_count($dashboard, '<details') >= 3, 'Widget was not white labeled and collapsible');
    check(strpos($banner, 'Open incident') !== false && strpos($banner, 'Planned work') !== false, 'Valid banner missing messages');
    check(strpos($notice, 'Open incident') !== false, 'Valid admin notice missing message');
    check(strpos($dashboard, 'Website') !== false && strpos($dashboard, '99.95') !== false, 'Valid dashboard missing resource');
    check($scheduled_history !== false, 'History was not scheduled in the background');
    $history_dashboard = new ArcWind_Init_OneUptime_Dashboard();
    $history_dashboard->refresh_history();
    check(isset($transients[ArcWind_Init_OneUptime_Dashboard::HISTORY_CACHE]['windows']['30d']), 'History windows were not fetched');
    unset($transients[ArcWind_Init_OneUptime_Dashboard::CACHE]);
    ob_start();
    $history_dashboard->render();
    $history_html = ob_get_clean();
    check(strpos($history_html, '24 timmar') !== false && strpos($history_html, '30 dagar') !== false, 'History bars were not rendered');
    unset($transients[ArcWind_Init_OneUptime_Dashboard::CACHE]);
    $responses['overview'] = new WP_Error('network', 'secret-token');
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
    check(strpos($dashboard, 'API') !== false && strpos($dashboard, '98.50') !== false, 'Nested uptime was not parsed');
    check(substr_count($dashboard, 'Worker') === 1 && strpos($dashboard, 'Backend') < strpos($dashboard, 'Jobs'), 'Nested group was not rendered once under its parent');
    check(strpos($dashboard, 'Drifttid: uppgift saknas') !== false, 'Missing uptime was not labeled unavailable');

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
        [, $dashboard] = render_pair();
        check(strpos($dashboard, 'role="alert"') !== false, 'Overview failure had no safe error');
        check(strpos($dashboard, 'secret-token') === false, 'Response detail leaked to dashboard');
    }

    load_fixture('valid');
    $responses['uptime'] = respond('{bad json');
    [, $dashboard] = render_pair();
    check(strpos($dashboard, 'Website') !== false, 'Uptime failure broke overview');

    load_fixture('valid');
    foreach (['incidents', 'announcements', 'scheduled-maintenance-events'] as $endpoint) {
        $responses[$endpoint] = new WP_Error('network', 'secret-token');
    }
    [$banner, $dashboard] = render_pair();
    check($banner === '' && strpos($dashboard, 'Website') !== false, 'Banner API failure broke a page');

    global $transients, $log;
    $transients[ArcWind_Init_OneUptime::CACHE] = [['type' => ['bad'], 'title' => ['bad'], 'url' => ['bad']]];
    [$banner] = render_pair();
    check($banner === '', 'Malformed cached message appeared');
    check(strpos(file_get_contents($log), 'secret-token') === false, 'Sensitive error text was logged');
    echo "OneUptime response regression tests passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    unlink($log);
}
