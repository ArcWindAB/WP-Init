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
function do_settings_fields($page, $section) { global $fields; foreach ($fields as $callback) { echo '<tr><td>'; $callback(); echo '</td></tr>'; } }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function __($value) { return $value; }
function esc_html($value) { return esc_attr($value); }
function esc_html__($value) { return esc_html($value); }
function esc_url($value) { return esc_attr($value); }
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
$saved['alerts_api_key'] = 'private-alert-key';
ob_start();
$fields['arcwind_oneuptime_alerts_api_key']();
$key_html = ob_get_clean();
check(strpos($key_html, 'private-alert-key') === false && strpos($key_html, 'sparad') !== false, 'Saved alert API key was exposed in HTML');
check(strpos($key_html, 'name="arcwind_init_oneuptime[alerts_api_key_clear]"') !== false && strpos($key_html, 'type="submit"') !== false, 'Saved alert API key did not have a save-and-remove button');
check(strpos($key_html, 'name="arcwind_init_oneuptime[alerts_api_key]"') === false, 'New alert API key field appeared while a key was saved');
check($integration->sanitize(['host' => 'https://monitor.example.test', 'alerts_api_key' => ''])['alerts_api_key'] === 'private-alert-key', 'Blank alert key did not preserve saved key');
check($integration->sanitize(['host' => 'https://monitor.example.test', 'alerts_api_key_clear' => 1])['alerts_api_key'] === '', 'Alert key could not be cleared');
unset($saved['alerts_api_key']);
ob_start();
$fields['arcwind_oneuptime_alerts_api_key']();
$empty_key_html = ob_get_clean();
check(strpos($empty_key_html, 'name="arcwind_init_oneuptime[alerts_api_key]"') !== false && strpos($empty_key_html, 'type="password"') !== false, 'Alert API key field was not shown when no key was saved');
check(strpos($empty_key_html, 'aria-label="API-nyckel för alerts"') !== false, 'Alert API key field has no accessible name');
check(strpos($empty_key_html, 'alerts_api_key_clear') === false, 'Remove button appeared when no alert API key was saved');
$saved['alerts_api_key'] = 'private-alert-key';
ob_start();
$fields['arcwind_oneuptime_heartbeat_secret']();
$html = ob_get_clean();
check(strpos($html, 'secret-token') !== false && strpos($html, 'kortas automatiskt till ID:t') !== false, 'Heartbeat ID was not shown in new field');
check(strpos($html, 'aria-label="Heartbeat-ID"') !== false, 'Heartbeat ID field has no accessible name');
$input = ['host' => 'https://monitor.example.test/', 'status_page_id' => 'page-1',
    'heartbeat_enabled' => 1, 'heartbeat_secret' => 'https://monitor.example.test/heartbeat/new-token/'];
$parsed = $integration->sanitize($input);
check($parsed['host'] === 'https://monitor.example.test' && $parsed['status_url'] === 'https://monitor.example.test/status-page/page-1', 'Status URL was not derived');
check($parsed['heartbeat_secret'] === 'new-token' && $parsed['heartbeat_url'] === 'https://monitor.example.test/heartbeat/new-token', 'Full heartbeat URL was not reduced to its ID');
check($integration->sanitize(['host' => 'https://monitor.example.test', 'heartbeat_secret' => 'secret-only'])['heartbeat_url'] === 'https://monitor.example.test/heartbeat/secret-only', 'Heartbeat-only configuration did not derive its URL');
check($integration->sanitize(['host' => 'https://monitor.example.test', 'heartbeat_secret' => ''])['heartbeat_url'] === '', 'Clearing the heartbeat ID failed');
ob_start();
ArcWind_Init_OneUptime::render_settings_table();
$table = ob_get_clean();
check(substr_count($table, '<table') === 1 && substr_count($table, 'aria-controls=') >= 3, 'Settings were not grouped in one collapsible table');
check(strpos($table, 'arcwind_oneuptime_information_fields') !== false && strpos($table, ' disabled') !== false, 'Status information was not locked without a page ID');
$saved['host'] = 'https://monitor.example.test';
$saved['status_page_id'] = 'page-1';
ob_start();
ArcWind_Init_OneUptime::render_settings_table();
$table = ob_get_clean();
check(strpos($table, 'id="arcwind-oneuptime-status-required" hidden') !== false, 'Status information remained locked after configuration');
unset($saved['host'], $saved['status_page_id']);
unset($saved['alerts_api_key']);
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
