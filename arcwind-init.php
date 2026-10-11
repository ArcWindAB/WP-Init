<?php
/**
 * Plugin Name: ArcWind Init
 * Description: Valfria integrationer för PostHog-spårning och statistik, feedback samt driftstatus och heartbeat.
 * Version:     1.7.0
 * Author:      ArcWind AB
 * License:     GPL-2.0-or-later
 * Text Domain: arcwind-init
 * Update URI:  https://github.com/ArcWindAB/WP-Init
 */

if (!defined('ABSPATH')) exit;
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-tracker.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-oneuptime.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-oneuptime-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-diagnostics.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-settings-trait.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-dashboard-trait.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-feedback-trait.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-posthog-trait.php';
register_activation_hook(__FILE__, ['ArcWind_Init_OneUptime', 'activate']);
register_deactivation_hook(__FILE__, ['ArcWind_Init_OneUptime', 'deactivate']);
register_uninstall_hook(__FILE__, ['ArcWind_Init_OneUptime', 'uninstall']);

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
        add_action('admin_init',         [$this, 'register_settings']);
        new ArcWind_Init_OneUptime();
        new ArcWind_Init_OneUptime_Dashboard();
        $this->diagnostics = new ArcWind_Init_Diagnostics();
        add_action('admin_menu',         [$this, 'add_settings_page']);

        // Dashboard widgets
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widgets']);

        // Minimal admin styling (no modals, no charts)
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Always-on PostHog snippet (front + admin) in chosen position (head/footer)
        add_action('wp_head',      [$this, 'maybe_print_posthog_snippet_universal']);
        add_action('wp_footer',    [$this, 'maybe_print_posthog_snippet_universal']);
        add_action('admin_head',   [$this, 'maybe_print_posthog_snippet_universal']);
        add_action('admin_footer', [$this, 'maybe_print_posthog_snippet_universal']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_tracking_bridge']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_tracking_bridge']);
    }

    public static function get_version() {
        return self::$version;
    }

    use ArcWind_Init_Settings_Trait, ArcWind_Init_Dashboard_Trait, ArcWind_Init_Feedback_Trait, ArcWind_Init_PostHog_Trait;

}

require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-github-updater.php';

new ArcWind_Init();
new ArcWind_Init_GitHub_Updater(__FILE__);
