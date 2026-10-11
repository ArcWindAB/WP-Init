<?php
/** Run: php tests/posthog-options.php */
define('ABSPATH', __DIR__ . '/');
$stored = [];
$widgets = [];
function get_option($key, $default = []) { global $stored; return $stored[$key] ?? $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url) { return $url; }
function sanitize_text_field($value) { return trim($value); }
function wp_json_encode($value) { return json_encode($value); }
function current_action() { return 'wp_head'; }
function is_user_logged_in() { return false; }
function __($value) { return $value; }
function wp_add_dashboard_widget($id) { global $widgets; $widgets[] = $id; }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
require_once dirname(__DIR__) . '/includes/class-arcwind-init-settings-trait.php';
require_once dirname(__DIR__) . '/includes/class-arcwind-init-dashboard-trait.php';
require_once dirname(__DIR__) . '/includes/class-arcwind-init-posthog-trait.php';
class ArcWind_Init_Options_Test {
    const OPTION_KEY = 'arcwind_init_settings';
    use ArcWind_Init_Settings_Trait, ArcWind_Init_Dashboard_Trait, ArcWind_Init_PostHog_Trait;
}
$plugin = new ArcWind_Init_Options_Test();
$plugin->register_dashboard_widgets();
check($widgets === [], 'Unconfigured dashboard widgets were registered');
check($plugin->sanitize_settings([])['region'] === 'eu', 'Existing settings without region were not handled');

$stored[ArcWind_Init_Options_Test::OPTION_KEY] = $plugin->sanitize_settings([
    'host' => 'https://eu.posthog.com', 'project_id' => '123',
    'personal_api_key' => 'phx_placeholder', 'public_api_key' => 'phc_placeholder',
    'enable_tracking' => 1, 'feedback_enabled' => 1, 'feedback_uuid' => 'TEST1234',
    'region' => 'us', 'api_host' => 'https://events.example.com/proxy/',
    'ui_host' => 'https://app.example.com/', 'defaults' => '2026-05-30',
    'person_profiles' => 'identified_only',
]);
$options = $stored[ArcWind_Init_Options_Test::OPTION_KEY];
check($options['api_host'] === 'https://events.example.com/proxy', 'Reverse proxy URL was not saved');
check($options['ui_host'] === 'https://app.example.com', 'UI host was not saved');
$widgets = [];
$plugin->register_dashboard_widgets();
check($widgets === ['arcwind_init_dashboard_widget', 'arcwind_init_feedback_widget'], 'Configured widgets were not registered');
ob_start();
$plugin->maybe_print_posthog_snippet_universal();
$html = ob_get_clean();
check((bool) preg_match('/posthog\.init\("phc_placeholder", (\{[^;]+\})\);/', $html, $match), 'PostHog snippet was not rendered');
$config = json_decode($match[1], true);
check($config['api_host'] === 'https://events.example.com/proxy', 'Reverse proxy not used by snippet');
check($config['ui_host'] === 'https://app.example.com', 'UI host not used by snippet');
check($config['defaults'] === '2026-05-30' && $config['person_profiles'] === 'identified_only', 'SDK choices not used');

$stored[ArcWind_Init_Options_Test::OPTION_KEY] = $plugin->sanitize_settings([
    'region' => 'us', 'api_host' => 'http://unsafe.example.com', 'ui_host' => 'https://user@app.example.com',
    'defaults' => '2026-02-30', 'person_profiles' => 'invalid', 'feedback_uuid' => '',
]);
$options = $stored[ArcWind_Init_Options_Test::OPTION_KEY];
check($options['api_host'] === '' && $options['ui_host'] === '', 'Unsafe hosts were accepted');
check($options['defaults'] === '' && $options['person_profiles'] === '', 'Invalid SDK options were accepted');
$widgets = [];
$plugin->register_dashboard_widgets();
check($widgets === ['arcwind_init_dashboard_widget'], 'Unconfigured Feedback widget was registered');
ob_start();
$plugin->maybe_print_posthog_snippet_universal();
$html = ob_get_clean();
check(strpos($html, 'https:\/\/us.posthog.com') !== false, 'Region did not select the UI host');
check(strpos($html, 'https:\/\/us.i.posthog.com') !== false, 'Region did not select the API host');

$stored[ArcWind_Init_Options_Test::OPTION_KEY] = [
    'host' => 'https://legacy.example.com/proxy', 'public_api_key' => 'phc_legacy',
    'enable_tracking' => 1,
];
ob_start();
$plugin->maybe_print_posthog_snippet_universal();
$html = ob_get_clean();
check(strpos($html, 'https:\/\/legacy.example.com\/proxy') !== false, 'Legacy browser host changed');
$migrated = $plugin->sanitize_settings(['api_host' => '', 'region' => 'us']);
check($migrated['api_host'] === 'https://legacy.example.com/proxy', 'Legacy proxy was not preserved on save');
echo "PostHog settings and dashboard regression tests passed.\n";
