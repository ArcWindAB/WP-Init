<?php
/** Run: php tests/error-log.php */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
$options = [];
$allowed = true;
$fail_write = false;
function __($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function current_user_can($capability) { global $allowed; return $allowed && $capability === 'manage_options'; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = null) { global $options, $fail_write; if ($fail_write) throw new RuntimeException('Write failed'); $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); return true; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { private $code; private $message; public function __construct($code, $message) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
require_once dirname(__DIR__) . '/includes/class-arcwind-init-error-log.php';

ArcWind_Init_Error_Log::record('PostHog', 'dashboard-query', 'error', 'authentication', ['http_status' => 401, 'url' => 'https://user:secret@example.test/?token=secret']);
ArcWind_Init_Error_Log::record('PostHog', 'dashboard-query', 'error', 'authentication', ['http_status' => 401]);
$entries = ArcWind_Init_Error_Log::entries();
check(count($entries) === 1 && $entries[0]['count'] === 2 && $entries[0]['first'] <= $entries[0]['last'], 'Repeated errors were not deduplicated');
check(strpos(serialize($entries), 'secret') === false && $entries[0]['context'] === ['http_status' => 401], 'Sensitive context was retained');
ArcWind_Init_Error_Log::record('PostHog', 'secret-token', 'error', 'transport');
ArcWind_Init_Error_Log::record('NotAllowed', 'query', 'error', 'transport');
check(count(ArcWind_Init_Error_Log::entries()) === 1, 'Unknown source or action was retained');
ArcWind_Init_Error_Log::recover('PostHog', 'dashboard-query');
check(count(ArcWind_Init_Error_Log::entries()) === 2 && ArcWind_Init_Error_Log::entries()[1]['code'] === 'recovered', 'Recovery was not recorded');
ArcWind_Init_Error_Log::recover('PostHog', 'dashboard-query');
check(count(ArcWind_Init_Error_Log::entries()) === 2, 'Repeated recovery created a new row');
$old = $entries[0];
$old['last'] = time() - 31 * DAY_IN_SECONDS;
$options[ArcWind_Init_Error_Log::OPTION] = [$old];
ArcWind_Init_Error_Log::record('GitHub', 'release-check', 'error', 'http', ['http_status' => 503]);
check(count(ArcWind_Init_Error_Log::entries()) === 1, 'Expired entry was not pruned');
for ($index = 0; $index < 120; ++$index) ArcWind_Init_Error_Log::record('OneUptime', 'overview', 'error', 'http', ['http_status' => 400 + $index]);
check(count(ArcWind_Init_Error_Log::entries()) === ArcWind_Init_Error_Log::MAX_ENTRIES, 'Record limit was not enforced');
$allowed = false;
check(ArcWind_Init_Error_Log::entries() === [] && ArcWind_Init_Error_Log::clear() === false, 'Unauthorized user read or cleared the log');
$allowed = true;
$fail_write = true;
ArcWind_Init_Error_Log::record('PostHog', 'dashboard-query', 'error', 'transport');
$fail_write = false;
check(ArcWind_Init_Error_Log::clear() === true && ArcWind_Init_Error_Log::entries() === [], 'Administrator could not clear the log');
check(ArcWind_Init_Error_Log::transport_code(new WP_Error('timeout', 'secret-token')) === 'timeout', 'Safe timeout category was lost');
check(ArcWind_Init_Error_Log::transport_code(new WP_Error('http_request_failed', 'cURL error 6: secret-token')) === 'dns', 'DNS category was lost');
check(ArcWind_Init_Error_Log::transport_code(new WP_Error('http_request_failed', 'cURL error 60: secret-token')) === 'tls', 'TLS category was lost');
echo "Diagnostic error log regression tests passed.\n";
