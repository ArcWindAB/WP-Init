<?php
/** Run: php tests/github-updater.php */
define('ABSPATH', __DIR__ . '/');
define('WP_PLUGIN_DIR', dirname(__DIR__));
class WP_Error {
    public function __construct($code, $message) {}
}
$transients = [];
$response = null;
$requests = 0;
function plugin_basename($file) { return 'arcwind-init/arcwind-init.php'; }
function get_file_data($file, $headers, $context) { return ['Version' => '1.7.0']; }
function add_filter() {}
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function set_transient($key, $value, $ttl) { global $transients; $transients[$key] = $value; }
function wp_safe_remote_get($url, $args) { global $response, $requests; $requests++; return $response; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($value) { return $value['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function wp_parse_url($value) { return parse_url($value); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function wp_normalize_path($value) { return str_replace('\\', '/', $value); }
function check($condition, $message) { if (!$condition) throw new Exception($message); }

require_once dirname(__DIR__) . '/includes/class-arcwind-init-github-updater.php';
$updater = new ArcWind_Init_GitHub_Updater(dirname(__DIR__) . '/arcwind-init.php');
$url = 'https://github.com/ArcWindAB/WP-Init/releases/download/v1.8.0/arcwind-init.zip';
$release = ['tag_name' => 'v1.8.0', 'draft' => false, 'prerelease' => false,
    'assets' => [['name' => 'arcwind-init.zip', 'browser_download_url' => $url]], 'body' => 'Release notes'];
check(ArcWind_Init_GitHub_Updater::parse_release($release)['version'] === '1.8.0', 'Valid release rejected');
foreach ([
    array_merge($release, ['tag_name' => 'latest']),
    array_merge($release, ['draft' => true]),
    array_merge($release, ['assets' => [['name' => 'source.zip', 'browser_download_url' => $url]]]),
    array_merge($release, ['assets' => [['name' => 'arcwind-init.zip', 'browser_download_url' => 'http://example.test/plugin.zip']]]),
    ['tag_name' => ['malformed'], 'assets' => []],
] as $invalid) check(ArcWind_Init_GitHub_Updater::parse_release($invalid) === [], 'Unsafe release accepted');

$response = ['code' => 200, 'body' => json_encode($release)];
$update = $updater->filter_update(false, [], 'arcwind-init/arcwind-init.php', []);
check($update['version'] === '1.8.0' && $update['package'] === $url, 'Update was not detected');
check($updater->filter_update(false, [], 'other/plugin.php', []) === false, 'Another plugin was modified');
check($requests === 1, 'Release cache was not used');

$transients = [];
$response = new WP_Error('rate_limited', 'secret-token');
$update = $updater->filter_update(false, [], 'arcwind-init/arcwind-init.php', []);
check($update['version'] === '1.7.0' && $update['package'] === '', 'API failure offered an unsafe update');
$count = $requests;
$updater->filter_update(false, [], 'arcwind-init/arcwind-init.php', []);
check($requests === $count, 'API failure was not cached');
check(is_wp_error($updater->fix_directory_name('/missing', '', null, ['plugin' => 'arcwind-init/arcwind-init.php'])), 'Invalid package did not fail safely');
$package_dir = sys_get_temp_dir() . '/arcwind-updater-test-' . getmypid();
mkdir($package_dir);
file_put_contents($package_dir . '/arcwind-init.php', '<?php /* Version: 1.7.0 */');
$transients[ArcWind_Init_GitHub_Updater::CACHE_KEY] = ['version' => '1.8.0'];
check(is_wp_error($updater->fix_directory_name($package_dir, '', null, ['plugin' => 'arcwind-init/arcwind-init.php', 'action' => 'update'])), 'Mismatched update version was accepted');
$wp_filesystem = new class { public function move($source, $destination, $overwrite) { return true; } };
check(!is_wp_error($updater->fix_directory_name($package_dir, '', null, ['plugin' => 'arcwind-init/arcwind-init.php', 'action' => 'install'])), 'Manual rollback was rejected');
unlink($package_dir . '/arcwind-init.php');
rmdir($package_dir);
echo "GitHub updater regression tests passed.\n";
