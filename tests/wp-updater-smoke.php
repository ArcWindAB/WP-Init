<?php
/** Run inside an isolated WP-CLI installation. The installed plugin is replaced. */
if (!defined('ABSPATH')) throw new RuntimeException('WordPress is not loaded');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';

function arcwind_updater_check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$plugin = 'arcwind-init/arcwind-init.php';
$installed = WP_PLUGIN_DIR . '/' . $plugin;
$current = get_plugin_data($installed, false, false)['Version'];
$parts = array_map('intval', explode('.', $current));
$target = $parts[0] . '.' . $parts[1] . '.' . ($parts[2] + 1);
$package = 'https://github.com/ArcWindAB/WP-Init/releases/download/v' . $target . '/arcwind-init.zip';
$archive_path = tempnam(sys_get_temp_dir(), 'arcwind-update-');
$zip = new ZipArchive();
arcwind_updater_check($zip->open($archive_path, ZipArchive::OVERWRITE) === true, 'Could not create update fixture');
$root = dirname($installed);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = 'arcwind-init/' . substr($file->getPathname(), strlen($root) + 1);
    if ($relative === 'arcwind-init/arcwind-init.php') {
        $main = file_get_contents($file->getPathname());
        $main = preg_replace('/(^\s*\* Version:\s*)' . preg_quote($current, '/') . '/m', '${1}' . $target, $main, 1);
        arcwind_updater_check(is_string($main) && strpos($main, '* Version:     ' . $target) !== false, 'Could not set fixture version');
        $zip->addFromString($relative, $main);
    } else {
        $zip->addFile($file->getPathname(), $relative);
    }
}
$zip->close();
$release = ['tag_name' => 'v' . $target, 'draft' => false, 'prerelease' => false,
    'assets' => [['name' => 'arcwind-init.zip', 'browser_download_url' => $package]], 'body' => 'Test release'];
$mock = static function ($pre, $args, $url) use ($release, $package, $archive_path) {
    if ($url === 'https://api.github.com/repos/ArcWindAB/WP-Init/releases/latest') {
        $body = wp_json_encode($release);
    } elseif (strpos($url, 'api.wordpress.org/plugins/update-check/') !== false) {
        $body = wp_json_encode(['plugins' => [], 'translations' => [], 'no_update' => []]);
    } elseif ($url === $package && !empty($args['stream']) && !empty($args['filename'])) {
        copy($archive_path, $args['filename']);
        $body = '';
    } elseif (wp_parse_url($url, PHP_URL_HOST) === '127.0.0.1' || wp_parse_url($url, PHP_URL_HOST) === 'localhost') {
        return $pre;
    } else {
        return new WP_Error('offline', 'External service unavailable');
    }
    return ['headers' => [], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => $args['filename'] ?? null];
};

$old_auto = get_site_option('auto_update_plugins', []);
$old_settings = get_option(ArcWind_Init::OPTION_KEY, []);
$old_oneuptime = get_option(ArcWind_Init_OneUptime::KEY, []);
try {
    add_filter('pre_http_request', $mock, 10, 3);
    delete_transient(ArcWind_Init_GitHub_Updater::CACHE_KEY);
    delete_site_transient('update_plugins');
    wp_update_plugins();
    $updates = get_site_transient('update_plugins');
    arcwind_updater_check(isset($updates->response[$plugin]), 'WordPress did not list the GitHub release as an update');
    $item = $updates->response[$plugin];
    arcwind_updater_check($item->new_version === $target && $item->package === $package, 'WordPress update offer was incorrect');
    $info = apply_filters('plugins_api', false, 'plugin_information', (object) ['slug' => 'arcwind-init']);
    arcwind_updater_check(is_object($info) && $info->version === $target, 'Plugin information did not use GitHub release');
    $item->plugin = $plugin;
    $automatic = new WP_Automatic_Updater();
    update_site_option('auto_update_plugins', []);
    arcwind_updater_check(!$automatic->should_update('plugin', $item, WP_PLUGIN_DIR), 'Automatic updates were forced without opt-in');
    update_site_option('auto_update_plugins', [$plugin]);
    arcwind_updater_check($automatic->should_update('plugin', $item, WP_PLUGIN_DIR), 'Native WordPress auto-update toggle was ignored');
    update_option(ArcWind_Init::OPTION_KEY, ['public_api_key' => 'phc_keep', 'feedback_uuid' => 'KEEP1234']);
    update_option(ArcWind_Init_OneUptime::KEY, ['host' => 'https://status.example.test', 'status_page_id' => 'keep-page']);

    $bad_source = tempnam(sys_get_temp_dir(), 'arcwind-bad-');
    file_put_contents($bad_source, 'not a ZIP');
    $bad_mock = static function ($pre, $args, $url) use ($package, $bad_source) {
        if ($url !== $package || empty($args['filename'])) return $pre;
        copy($bad_source, $args['filename']);
        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => $args['filename']];
    };
    add_filter('pre_http_request', $bad_mock, 20, 3);
    $failed = (new Plugin_Upgrader(new Automatic_Upgrader_Skin()))->upgrade($plugin);
    remove_filter('pre_http_request', $bad_mock, 20);
    unlink($bad_source);
    arcwind_updater_check(is_wp_error($failed) || empty($failed), 'Broken package unexpectedly installed');
    arcwind_updater_check(is_file($installed) && get_plugin_data($installed, false, false)['Version'] === $current, 'Broken package damaged installed plugin');

    if (!is_plugin_active($plugin)) activate_plugin($plugin);
    add_filter('wp_doing_cron', '__return_true');
    $result = $automatic->update('plugin', $item);
    remove_filter('wp_doing_cron', '__return_true');
    arcwind_updater_check($result === true || (is_array($result) && !empty($result['result'])), 'Automatic installation failed: ' . print_r($result, true));
    arcwind_updater_check(get_plugin_data($installed, false, false)['Version'] === $target, 'Automatic update installed wrong version');
    arcwind_updater_check(is_plugin_active($plugin), 'Automatic update deactivated plugin');
    arcwind_updater_check(get_option(ArcWind_Init::OPTION_KEY)['feedback_uuid'] === 'KEEP1234', 'Automatic update lost Feedback settings');
    arcwind_updater_check(get_option(ArcWind_Init_OneUptime::KEY)['status_page_id'] === 'keep-page', 'Automatic update lost OneUptime settings');
    echo "WordPress automatic GitHub update regression test passed.\n";
} finally {
    remove_filter('pre_http_request', $mock, 10);
    update_site_option('auto_update_plugins', $old_auto);
    update_option(ArcWind_Init::OPTION_KEY, $old_settings);
    update_option(ArcWind_Init_OneUptime::KEY, $old_oneuptime);
    delete_transient(ArcWind_Init_GitHub_Updater::CACHE_KEY);
    delete_site_transient('update_plugins');
    unlink($archive_path);
}
