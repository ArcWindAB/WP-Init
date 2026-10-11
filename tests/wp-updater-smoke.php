<?php
/** Run inside an isolated WP-CLI installation: wp eval-file tests/wp-updater-smoke.php */
if (!defined('ABSPATH')) throw new RuntimeException('WordPress is not loaded');
function arcwind_updater_check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
set_error_handler(static function ($severity, $message) { throw new ErrorException($message, 0, $severity); });
$release = ['tag_name' => 'v1.8.0', 'draft' => false, 'prerelease' => false,
    'assets' => [['name' => 'arcwind-init.zip', 'browser_download_url' => 'https://github.com/ArcWindAB/WP-Init/releases/download/v1.8.0/arcwind-init.zip']],
    'body' => 'Test release'];
$mock = static function ($pre, $args, $url) use ($release) {
    if ($url === 'https://api.github.com/repos/ArcWindAB/WP-Init/releases/latest') {
        return ['headers' => [], 'body' => wp_json_encode($release), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }
    if (strpos($url, 'api.wordpress.org/plugins/update-check/') !== false) {
        return ['headers' => [], 'body' => wp_json_encode(['plugins' => [], 'translations' => [], 'no_update' => []]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }
    return new WP_Error('offline', 'External service unavailable');
};
try {
    add_filter('pre_http_request', $mock, 10, 3);
    delete_transient(ArcWind_Init_GitHub_Updater::CACHE_KEY);
    delete_site_transient('update_plugins');
    wp_update_plugins();
    $updates = get_site_transient('update_plugins');
    $plugin = 'arcwind-init/arcwind-init.php';
    arcwind_updater_check(isset($updates->response[$plugin]), 'WordPress did not list the GitHub release as an update');
    $item = $updates->response[$plugin];
    arcwind_updater_check($item->new_version === '1.8.0', 'WordPress update version was incorrect');
    arcwind_updater_check($item->package === $release['assets'][0]['browser_download_url'], 'WordPress update package was incorrect');
    $info = apply_filters('plugins_api', false, 'plugin_information', (object) ['slug' => 'arcwind-init']);
    arcwind_updater_check(is_object($info) && $info->version === '1.8.0', 'Plugin information did not use GitHub release');
    echo "WordPress GitHub updater smoke test passed.\n";
} finally {
    remove_filter('pre_http_request', $mock, 10);
    delete_transient(ArcWind_Init_GitHub_Updater::CACHE_KEY);
    delete_site_transient('update_plugins');
    restore_error_handler();
}
