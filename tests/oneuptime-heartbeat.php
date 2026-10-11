<?php
/** Run: php tests/oneuptime-heartbeat.php */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    public function __construct($code, $message) {}
}
class ArcWind_Init_OneUptime_Dashboard {
    const CACHE = 'dashboard-cache';
    const LAST_GOOD = 'dashboard-good';
    const FAILURE_CACHE = 'dashboard-failure';
    const HISTORY_CACHE = 'history-cache';
    const HISTORY_LAST_GOOD = 'history-good';
    const HISTORY_FAILURE = 'history-failure';
    const HISTORY_HOOK = 'history-hook';
}

$saved = ['enabled' => 1, 'heartbeat_enabled' => 1, 'heartbeat_url' => 'https://monitor.example.test/heartbeat/secret-token'];
$health = [];
$requests = [];
$response = ['code' => 204];
$fields = [];
$scheduled = [];
set_error_handler(function ($severity, $message) { throw new ErrorException($message, 0, $severity); });

function get_option($key, $default = []) { global $saved, $health; return $key === ArcWind_Init_OneUptime::KEY ? $saved : ($health ?: $default); }
function update_option($key, $value, $autoload = null) { global $health; $health = $value; }
function delete_option($key) {}
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_http_validate_url($url) {
    $host = parse_url($url, PHP_URL_HOST);
    return in_array($host, ['localhost', '127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.1.1'], true) ? false : $url;
}
function wp_safe_remote_get($url, $args) { global $requests, $response; $requests[] = [$url, $args]; return $response; }
function wp_remote_retrieve_response_code($value) { return $value['code']; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function delete_transient($key) {}
function esc_url_raw($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function add_settings_error() {}
function add_action() {}
function add_filter() {}
function register_setting() {}
function add_settings_section() {}
function add_settings_field($id, $title, $callback) { global $fields; $fields[$id] = $callback; }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function checked($checked, $current = true, $echo = false) { return $checked === $current ? 'checked' : ''; }
function wp_clear_scheduled_hook($hook) { global $scheduled; $scheduled = []; }
function wp_schedule_event($time, $schedule, $hook) { global $scheduled; $scheduled[] = [$time, $schedule, $hook]; }

require_once dirname(__DIR__) . '/includes/class-arcwind-init-oneuptime.php';
function check($condition, $message) { if (!$condition) throw new Exception($message); }

foreach (['http://monitor.example.test/heartbeat/x', 'https://127.0.0.1/heartbeat/x',
    'https://10.0.0.1/heartbeat/x', 'https://localhost/heartbeat/x',
    'https://monitor.example.test/path', 'https://user:pass@monitor.example.test/heartbeat/x',
    'https://monitor.example.test/heartbeat/x#fragment'] as $url) {
    check(!ArcWind_Init_OneUptime::valid_heartbeat_url($url), 'Unsafe URL accepted: ' . $url);
}
check(ArcWind_Init_OneUptime::valid_heartbeat_url($saved['heartbeat_url']), 'Valid HTTPS URL rejected');
$integration = new ArcWind_Init_OneUptime();
$integration->register_settings();
ob_start();
$fields['arcwind_oneuptime_heartbeat_url']();
$html = ob_get_clean();
check(strpos($html, 'secret-token') === false && strpos($html, 'sparad') !== false, 'Heartbeat secret exposed in HTML');
check($integration->sanitize(['heartbeat_url' => '', 'status_page_id' => '', 'status_url' => ''])['heartbeat_url'] === $saved['heartbeat_url'], 'Blank save did not retain secret');
check($integration->sanitize(['heartbeat_clear' => 1, 'heartbeat_url' => ''])['heartbeat_url'] === '', 'Explicit clear did not remove secret');
$integration->send_heartbeat();
check(count($requests) === 1 && $requests[0][1]['redirection'] === 0, 'Safe heartbeat was not sent with redirects disabled');
check($health['status'] === 'ok' && $health['last_success'] > 0, 'Successful heartbeat was not recorded');
$saved['heartbeat_url'] = 'https://127.0.0.1/heartbeat/secret-token';
$integration->send_heartbeat();
check(count($requests) === 1 && $health['status'] === 'failed', 'Private heartbeat target was requested');
$saved['heartbeat_url'] = 'https://monitor.example.test/heartbeat/secret-token';
ArcWind_Init_OneUptime::activate();
check(count($scheduled) === 1, 'Activation did not schedule exactly one heartbeat');
ArcWind_Init_OneUptime::deactivate();
check($scheduled === [], 'Deactivation left a scheduled heartbeat');
echo "OneUptime heartbeat regression tests passed.\n";
