<?php
/**
 * Plugin Name: ArcWind Init
 * Description: PostHog-spårning och dashboardstatistik, feedbacklänk samt OneUptime-status, driftinformation, alerts och heartbeat.
 * Version:     2.1.1
 * Author:      ArcWind AB
 * License:     GPL-2.0-or-later
 * Text Domain: arcwind-init
 * Update URI:  https://github.com/ArcWindAB/WP-Init
 */

if (!defined('ABSPATH')) exit;

function arcwind_init_load_module($filename) {
    $path = plugin_dir_path(__FILE__) . 'includes/' . $filename;
    if (!is_readable($path)) {
        error_log('ArcWind Init module unavailable: ' . $filename);
        return false;
    }
    try {
        require_once $path;
        return true;
    } catch (Throwable $error) {
        error_log('ArcWind Init module could not load: ' . $filename);
        return false;
    }
}

arcwind_init_load_module('class-arcwind-init-error-log.php');
arcwind_init_load_module('class-arcwind-init-tracker.php');
arcwind_init_load_module('class-arcwind-init-oneuptime.php');
arcwind_init_load_module('class-arcwind-init-oneuptime-dashboard.php');
arcwind_init_load_module('class-arcwind-init-diagnostics.php');
$arcwind_settings_loaded = arcwind_init_load_module('class-arcwind-init-settings-trait.php') && trait_exists('ArcWind_Init_Settings_Trait');
arcwind_init_load_module('class-arcwind-init-dashboard-trait.php');
arcwind_init_load_module('class-arcwind-init-feedback-trait.php');
arcwind_init_load_module('class-arcwind-init-posthog-trait.php');

if (!trait_exists('ArcWind_Init_Settings_Trait')) { trait ArcWind_Init_Settings_Trait {} }
if (!trait_exists('ArcWind_Init_Dashboard_Trait')) { trait ArcWind_Init_Dashboard_Trait {} }
if (!trait_exists('ArcWind_Init_Feedback_Trait')) { trait ArcWind_Init_Feedback_Trait {} }
if (!trait_exists('ArcWind_Init_PostHog_Trait')) { trait ArcWind_Init_PostHog_Trait {} }
define('ARCWIND_INIT_SETTINGS_LOADED', $arcwind_settings_loaded);

// WordPress options.php checks these capabilities on direct settings submissions.
add_filter('option_page_capability_arcwind_init_settings_group', static function () { return 'manage_options'; }, PHP_INT_MAX);
add_filter('option_page_capability_arcwind_init_oneuptime_group', static function () { return 'manage_options'; }, PHP_INT_MAX);

if (class_exists('ArcWind_Init_OneUptime')) {
    register_activation_hook(__FILE__, ['ArcWind_Init_OneUptime', 'activate']);
    register_deactivation_hook(__FILE__, ['ArcWind_Init_OneUptime', 'deactivate']);
    register_uninstall_hook(__FILE__, ['ArcWind_Init_OneUptime', 'uninstall']);
}

class ArcWind_Init {
    const OPTION_KEY    = 'arcwind_init_settings';
    const NONCE_KEY     = 'arcwind_init_nonce';
    const ERR_TRANSIENT = 'arcwind_init_last_errors';
    const ERR_TTL       = 30 * MINUTE_IN_SECONDS;

    private static $version = '0.0.0';
    private $diagnostics;

    public function __construct() {
        $plugin_data = get_file_data(__FILE__, [
            'Version' => 'Version',
        ], 'plugin');

        self::$version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

        // Settings + menu
        if (class_exists('ArcWind_Init_OneUptime')) new ArcWind_Init_OneUptime();
        if (class_exists('ArcWind_Init_OneUptime_Dashboard') && class_exists('ArcWind_Init_OneUptime')) new ArcWind_Init_OneUptime_Dashboard();
        if (!ARCWIND_INIT_SETTINGS_LOADED) return;
        add_action('admin_init',         [$this, 'register_settings']);
        if (class_exists('ArcWind_Init_Diagnostics') && class_exists('ArcWind_Init_OneUptime') &&
            class_exists('ArcWind_Init_OneUptime_Dashboard') && class_exists('ArcWind_Init_GitHub_Updater')) {
            $this->diagnostics = new ArcWind_Init_Diagnostics();
        }
        add_action('admin_menu',         [$this, 'add_settings_page']);

        // Dashboard widgets
        if (method_exists($this, 'register_dashboard_widgets')) add_action('wp_dashboard_setup', [$this, 'register_dashboard_widgets']);
        if (method_exists($this, 'refresh_posthog_stats')) add_action('arcwind_init_refresh_posthog_stats', [$this, 'refresh_posthog_stats']);

        // Minimal admin styling (no modals, no charts)
        if (method_exists($this, 'enqueue_admin_assets')) add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Always-on PostHog snippet (front + admin) in chosen position (head/footer)
        if (method_exists($this, 'maybe_print_posthog_snippet_universal')) {
            add_action('wp_head',      [$this, 'maybe_print_posthog_snippet_universal']);
            add_action('wp_footer',    [$this, 'maybe_print_posthog_snippet_universal']);
            add_action('admin_head',   [$this, 'maybe_print_posthog_snippet_universal']);
            add_action('admin_footer', [$this, 'maybe_print_posthog_snippet_universal']);
        }

        if (method_exists($this, 'enqueue_tracking_bridge')) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_tracking_bridge']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_tracking_bridge']);
        }
    }

    public static function get_version() {
        return self::$version;
    }

    use ArcWind_Init_Settings_Trait, ArcWind_Init_Dashboard_Trait, ArcWind_Init_Feedback_Trait, ArcWind_Init_PostHog_Trait;

}

arcwind_init_load_module('class-arcwind-init-github-updater.php');

new ArcWind_Init();
if (class_exists('ArcWind_Init_GitHub_Updater')) new ArcWind_Init_GitHub_Updater(__FILE__);
