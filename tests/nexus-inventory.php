<?php
/** Run with PHP against synthetic WordPress functions only. No provider request is possible. */
define('ABSPATH', __DIR__ . '/');
set_error_handler(function ($severity, $message) { throw new ErrorException($message, 0, $severity); });
$options = [];
$events = [];
$requests = [];
$home = 'https://wordpress.example.test/';
$reply = ['code' => 200, 'body' => '{"state":"accepted"}'];
$write_failure = false;
$wp_version = '6.8';
$plugins = [
    'arcwind-init/arcwind-init.php' => ['Version' => '2.1.1'],
    'inactive/inactive.php' => ['Version' => '1.0'],
    'network/network.php' => ['Version' => '1.3'],
    'MixedCase/Plugin.php' => ['Version' => 'unexpected private metadata'],
];
$updates = [
    'update_core' => (object) ['last_checked' => time() - 30, 'updates' => [(object) ['response' => 'upgrade', 'current' => '6.9']]],
    'update_plugins' => (object) ['last_checked' => time() - 30, 'response' => ['inactive/inactive.php' => (object) ['new_version' => '1.1']]],
];
class WP_Error {}
class InventoryTheme { public function get($field) { return '1.2'; } }
class InventoryDatabase {
    public $options = 'wp_options';
    public function db_version() { return '8.4'; }
    public function prepare($query, ...$args) { return $args; }
    public function query($args) {
        global $options;
        if (isset($options[$args[0]]) && serialize($options[$args[0]]) === $args[1]) unset($options[$args[0]]);
    }
}
$wpdb = new InventoryDatabase();
function add_action(...$args) {}
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_option($key, $value, ...$args) { global $options; if (array_key_exists($key, $options)) return false; $options[$key] = $value; return true; }
function update_option($key, $value, ...$args) { global $options, $write_failure; if ($write_failure) return false; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); }
function wp_cache_delete(...$args) {}
function maybe_serialize($value) { return serialize($value); }
function wp_parse_url($value) { return parse_url($value); }
function home_url($path) { global $home; return $home; }
function wp_next_scheduled($hook) { global $events; return $events[$hook][0] ?? false; }
function wp_schedule_event($time, $cadence, $hook) { global $events; $events[$hook] = [$time, $cadence]; }
function wp_schedule_single_event($time, $hook) { global $events; $events[$hook] = [$time, 'single']; }
function wp_clear_scheduled_hook($hook) { global $events; unset($events[$hook]); }
function wp_rand($minimum, $maximum) { return $minimum; }
function wp_generate_uuid4() { static $id = 0; return sprintf('55555555-5555-4555-8555-%012d', ++$id); }
function wp_json_encode($value) { return json_encode($value); }
function get_plugins() { global $plugins; return $plugins; }
function is_plugin_active($path) { return $path === 'arcwind-init/arcwind-init.php'; }
function is_plugin_active_for_network($path) { return $path === 'network/network.php'; }
function get_site_transient($key) { global $updates; return $updates[$key] ?? false; }
function wp_get_themes($args) { return ['active' => new InventoryTheme(), 'inactive' => new InventoryTheme()]; }
function get_stylesheet() { return 'active'; }
function get_template() { return 'active'; }
function plugin_basename($value) { return 'arcwind-init/arcwind-init.php'; }
function get_file_data(...$args) { return ['Version' => '2.1.1']; }
function wp_safe_remote_post($url, $args) { global $requests, $reply; $requests[] = [$url, $args]; return $reply; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
require dirname(__DIR__) . '/includes/class-arcwind-init-nexus-inventory.php';

$path = tempnam(sys_get_temp_dir(), 'nexus-reporter-');
$config = ['schema' => 1, 'enabled' => true, 'nexus_origin' => 'https://nexus.example.test',
    'installation_id' => '11111111-1111-4111-8111-111111111111', 'deployment_id' => '22222222-2222-4222-8222-222222222222',
    'site_url' => $home, 'credential' => str_repeat('a', 64)];
function configure($data) { global $path; file_put_contents($path, json_encode($data)); clearstatcache(true, $path); }
$reporter = new ArcWind_Init_Nexus_Inventory($path);
try {
    configure($config + ['unexpected' => true]);
    $reporter->run();
    check(!$requests, 'Unknown enrollment settings caused a request');
    configure(array_replace($config, ['enabled' => false]));
    $reporter->schedule();
    $reporter->run();
    check(!$events && !$requests, 'Disabled reporter scheduled or sent');
    foreach (['http://nexus.example.test', 'https://user:secret@nexus.example.test/', 'https://nexus.example.test/?key=secret', 'https://nexus.example.test/api'] as $origin) {
        configure(array_replace($config, ['nexus_origin' => $origin]));
        $reporter->run();
        check(!$requests, 'Unsafe origin accepted');
    }
    configure($config);
    $home = 'https://demo.example.test/';
    $reporter->run();
    check(!$requests, 'Cloned database with another address reported as production');
    $home = $config['site_url'];
    $reporter->schedule();
    $reporter->schedule();
    $reporter->changed();
    $reporter->changed();
    check(count($events) === 2, 'Schedule did not deduplicate daily/change events');
    $reporter->run();
    check(count($requests) === 1, 'Valid reporter did not send exactly once');
    $sent = json_decode($requests[0][1]['body'], true);
    check($sent['schema'] === 'nexus.inventory.v1' && $sent['installation_id'] === $config['installation_id'] && $sent['deployment_id'] === $config['deployment_id'], 'Enrollment identity changed');
    $components = array_column($sent['components'], null, 'id');
    check(count($components) === 9, 'Inactive plugins/themes or core components were omitted');
    check($components['plugin/network/network.php']['active'] === true, 'Network activation was omitted');
    check($components['plugin/inactive/inactive.php']['active'] === false && $components['theme/inactive']['active'] === false, 'Inactive states changed');
    check($components['plugin/inactive/inactive.php']['available_version'] === '1.1' && $components['wordpress']['available_version'] === '6.9', 'Known update information missing');
    check($components['theme/active']['available_version'] === null && $components['theme/active']['update_checked_at'] === null, 'Missing update data implied an update check');
    $hashed = 'plugin/sha256/' . hash('sha256', 'MixedCase/Plugin.php');
    check(isset($components[$hashed]) && $components[$hashed]['version'] === null, 'Unsafe technical values were not represented as unknown');
    $stored = json_encode($options);
    check(strpos($stored, $config['credential']) === false && strpos($stored, 'Bearer') === false, 'Credential entered database state');
    check($requests[0][1]['redirection'] === 0 && $requests[0][1]['sslverify'] === true && $requests[0][1]['reject_unsafe_urls'] === true && $requests[0][1]['timeout'] === 5 && $requests[0][1]['limit_response_size'] === 8192, 'Outbound bounds changed');
    check($options[ArcWind_Init_Nexus_Inventory::STATE]['status'] === 'accepted', 'HTTP acknowledgement was not validated');
    $previous_sequence = $sent['sequence'];

    $reply = ['code' => 503, 'body' => 'PRIVATE PROVIDER RESPONSE'];
    $reporter->run();
    $first_retry = end($requests)[1]['body'];
    check($options[ArcWind_Init_Nexus_Inventory::STATE]['status'] === 'retry_pending', 'Transient failure was not retryable');
    $reporter->run();
    check(end($requests)[1]['body'] === $first_retry, 'Retry changed the report UUID/body');
    $reporter->run();
    check(end($requests)[1]['body'] === $first_retry && $options[ArcWind_Init_Nexus_Inventory::STATE]['status'] === 'retry_exhausted', 'Retry budget was not bounded');
    check(strpos(json_encode($options), 'PRIVATE PROVIDER RESPONSE') === false, 'Provider body entered state');
    $reply = ['code' => 200, 'body' => '{"state":"duplicate"}'];
    $reporter->run();
    check($options[ArcWind_Init_Nexus_Inventory::STATE]['sequence'] > $previous_sequence, 'Daily sequence did not advance');
    check($options[ArcWind_Init_Nexus_Inventory::STATE]['status'] === 'accepted', 'Duplicate acknowledgement failed');

    $reply = ['code' => 401, 'body' => '{}'];
    $reporter->run();
    $count = count($requests);
    $reporter->run();
    check(count($requests) === $count, 'Permanent enrollment failure retried automatically');
    $config['credential'] = str_repeat('b', 64);
    configure($config);
    $reply = ['code' => 200, 'body' => '{}'];
    $reporter->run();
    check(count($requests) === $count + 1 && $options[ArcWind_Init_Nexus_Inventory::STATE]['status'] === 'retry_pending', 'Credential rotation or malformed acknowledgement handling failed');

    $options[ArcWind_Init_Nexus_Inventory::LOCK] = ['id' => 'another-worker', 'expires' => time() + 100];
    $count = count($requests);
    $reporter->run();
    check(count($requests) === $count, 'Active lock was ignored');
    $options[ArcWind_Init_Nexus_Inventory::LOCK]['expires'] = time() - 1;
    $reporter->run();
    check(count($requests) === $count + 1 && !isset($options[ArcWind_Init_Nexus_Inventory::LOCK]), 'Expired lock was not recovered');
    $write_failure = true;
    $count = count($requests);
    $reporter->run();
    check(count($requests) === $count, 'Request was sent without durable retry state');
    $write_failure = false;

    $config['installation_id'] = '66666666-6666-4666-8666-666666666666';
    $config['deployment_id'] = '77777777-7777-4777-8777-777777777777';
    $config['site_url'] = 'https://demo.example.test/';
    $config['credential'] = str_repeat('c', 64);
    $home = $config['site_url'];
    configure($config);
    $reply = ['code' => 200, 'body' => '{"state":"accepted"}'];
    $reporter->run();
    $clone = json_decode(end($requests)[1]['body'], true);
    check($clone['installation_id'] === $config['installation_id'] && $clone['deployment_id'] === $config['deployment_id'] && $clone['site_url'] === $home, 'Clone enrollment reused a retained production report');
    check(strpos(json_encode($options), $config['credential']) === false, 'Rotated clone credential entered options');

    // Only technical data is emitted; no arbitrary options, content or user records.
    check(array_keys($sent) === ['schema', 'installation_id', 'deployment_id', 'site_url', 'report_id', 'sequence', 'observed_at', 'reporter', 'components'], 'Report gained undeclared fields');
    check(!isset($options['arcwind_init_settings']) && !isset($options['arcwind_init_oneuptime']), 'Existing integration settings were touched');
    ArcWind_Init_Nexus_Inventory::deactivate();
    $reporter->plugin_deactivated('arcwind-init/arcwind-init.php');
    check(!$events, 'Self-deactivation scheduled a new reporter job');
    ArcWind_Init_Nexus_Inventory::uninstall();
    check(!$events && !$options, 'Uninstall left reporter events/state');
    echo "Nexus inventory enrollment, full snapshot, clone, retry, secret and lifecycle checks passed.\n";
} finally {
    unlink($path);
}
