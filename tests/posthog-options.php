<?php
/** Run: php tests/posthog-options.php */
define('ABSPATH', __DIR__ . '/');
$stored = [];
$widgets = [];
$settings_fields = [];
$requests = [];
function get_option($key, $default = []) { global $stored; return $stored[$key] ?? $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url) { return $url; }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr__($value) { return esc_attr($value); }
function esc_html($value) { return esc_attr($value); }
function esc_html__($value) { return esc_html($value); }
function wp_kses_post($value) { return $value; }
function sanitize_text_field($value) { return trim($value); }
function wp_json_encode($value) { return json_encode($value); }
function current_action() { return 'wp_head'; }
function is_user_logged_in() { return false; }
function __($value) { return $value; }
function register_setting() {}
function add_settings_section() {}
function add_settings_field($id, $title, $callback, $page, $section) { global $settings_fields; $settings_fields[$section][] = $callback; }
function do_settings_fields($page, $section) { global $settings_fields; foreach ($settings_fields[$section] ?? [] as $callback) { echo '<tr><td>'; $callback(); echo '</td></tr>'; } }
function checked($value) { return $value ? 'checked' : ''; }
function selected($actual, $expected) { echo $actual === $expected ? 'selected' : ''; }
function wp_add_dashboard_widget($id) { global $widgets; $widgets[] = $id; }
function wp_safe_remote_post($url, $args) { global $requests; $requests[] = [$url, $args]; return ['code' => 200, 'body' => '{"results":[[1]]}']; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function is_wp_error($value) { return false; }
function home_url($path = '') { return 'https://example.test' . $path; }
function is_admin() { return false; }
function add_query_arg($args) { return ''; }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function check_section_state($html, $section, $state) {
    check((bool) preg_match('/aria-controls="' . preg_quote($section, '/') . '"[^>]*>.*?<\/button><span class="arcwind-section-status arcwind-section-status-' . preg_quote($state, '/') . '"/', $html), 'Wrong saved status for ' . $section . ': ' . $state);
}
require_once dirname(__DIR__) . '/includes/class-arcwind-init-settings-trait.php';
require_once dirname(__DIR__) . '/includes/class-arcwind-init-dashboard-trait.php';
require_once dirname(__DIR__) . '/includes/class-arcwind-init-feedback-trait.php';
require_once dirname(__DIR__) . '/includes/class-arcwind-init-posthog-trait.php';
require_once dirname(__DIR__) . '/includes/class-arcwind-init-tracker.php';
class ArcWind_Init { const OPTION_KEY = 'arcwind_init_settings'; public static function get_version() { return 'test'; } }
class ArcWind_Init_Options_Test {
    const OPTION_KEY = 'arcwind_init_settings';
    use ArcWind_Init_Settings_Trait, ArcWind_Init_Dashboard_Trait, ArcWind_Init_Feedback_Trait, ArcWind_Init_PostHog_Trait;
}
$plugin = new ArcWind_Init_Options_Test();
$plugin->register_settings();
$plugin->register_dashboard_widgets();
check($widgets === [], 'Unconfigured dashboard widgets were registered');
check($plugin->sanitize_settings([])['region'] === 'eu', 'Existing settings without region were not handled');
ob_start();
$plugin->render_input_personal_api_key();
$new_key_field = ob_get_clean();
check(strpos($new_key_field, 'name="arcwind_init_settings[personal_api_key]"') !== false, 'Personal API key input was missing before a key was saved');
check(strpos($new_key_field, 'aria-label="Personlig API-nyckel"') !== false, 'Personal API key field has no accessible name');

$stored[ArcWind_Init_Options_Test::OPTION_KEY] = $plugin->sanitize_settings([
    'host' => 'https://eu.posthog.com', 'project_id' => '123',
    'personal_api_key' => 'phx_placeholder', 'public_api_key' => 'phc_placeholder',
    'enable_tracking' => 1, 'feedback_enabled' => 1, 'feedback_uuid' => 'TEST1234',
    'region' => 'us', 'api_host' => 'https://events.example.com/proxy/',
    'ui_host' => 'https://app.example.com/', 'defaults' => '2026-05-30',
    'person_profiles' => 'identified_only',
]);
$options = $stored[ArcWind_Init_Options_Test::OPTION_KEY];
check(!array_key_exists('host', $options), 'Obsolete server host was saved');
ob_start();
$plugin->render_input_personal_api_key();
$saved_key_field = ob_get_clean();
check(strpos($saved_key_field, 'phx_placeholder') === false && strpos($saved_key_field, 'name="arcwind_init_settings[personal_api_key]"') === false, 'Saved personal API key was exposed as an input');
check(strpos($saved_key_field, 'name="arcwind_init_settings[personal_api_key_clear]"') !== false && strpos($saved_key_field, 'type="submit"') !== false, 'Saved personal API key did not have a save-and-remove button');
check($plugin->sanitize_settings([])['personal_api_key'] === 'phx_placeholder', 'Saving unrelated PostHog fields removed the personal API key');
check($plugin->sanitize_settings(['personal_api_key_clear' => 1])['personal_api_key'] === '', 'Remove button did not clear the personal API key');
check($options['api_host'] === 'https://events.example.com/proxy', 'Reverse proxy URL was not saved');
check($options['ui_host'] === 'https://app.example.com', 'UI host was not saved');
$posthog_table = new ReflectionMethod(ArcWind_Init_Options_Test::class, 'render_posthog_settings_table');
ob_start();
$posthog_table->invoke($plugin);
$configured_table = ob_get_clean();
check(substr_count($configured_table, '<table') === 1 && strpos($configured_table, 'aria-controls="arcwind_init_connection_fields" aria-expanded="true"') !== false, 'PostHog connection was not open in one settings table');
foreach (['PostHog API-värd eller proxy', 'PostHog UI-värd', 'Projekt-ID', 'Projekt-token', 'SDK defaults'] as $label) {
    check(strpos($configured_table, 'aria-label="' . $label . '"') !== false, 'PostHog field has no accessible name: ' . $label);
}
foreach (['arcwind_init_frontend_fields', 'arcwind_init_main_fields', 'arcwind_init_errors_fields'] as $section) {
    check(strpos($configured_table, 'aria-controls="' . $section . '" aria-expanded="false"><span') !== false, 'Configured PostHog section remained locked: ' . $section);
}
check_section_state($configured_table, 'arcwind_init_connection_fields', 'active');
check_section_state($configured_table, 'arcwind_init_frontend_fields', 'active');
check_section_state($configured_table, 'arcwind_init_main_fields', 'active');
check_section_state($configured_table, 'arcwind_init_errors_fields', 'inactive');
$connection_start = strpos($configured_table, '<tbody id="arcwind_init_connection_fields"');
$frontend_start = strpos($configured_table, '<tbody id="arcwind_init_frontend_fields"');
$connection_fields = substr($configured_table, $connection_start, strpos($configured_table, '</tbody>', $connection_start) - $connection_start);
$frontend_fields = substr($configured_table, $frontend_start, strpos($configured_table, '</tbody>', $frontend_start) - $frontend_start);
check(strpos($connection_fields, 'arcwind_init_settings[region]') !== false, 'PostHog region was not moved to connection settings');
foreach (['defaults', 'person_profiles', 'load_position'] as $field) {
    check(strpos($frontend_fields, 'arcwind_init_settings[' . $field . ']') !== false, 'SDK field was not moved to web tracking: ' . $field);
}
check(strpos($configured_table, 'arcwind_init_advanced_fields') === false, 'Empty SDK section was still rendered');
check(strpos($configured_table, 'arcwind_init_settings[host]') === false, 'Separate server host was still shown');
check(strpos($configured_table, '<select name="arcwind_init_settings[defaults]" aria-label="SDK defaults">') !== false && strpos($configured_table, 'value="2026-05-30" selected') !== false, 'Saved SDK defaults were not selected in the dropdown');
$query = new ReflectionMethod(ArcWind_Init_Options_Test::class, 'query_posthog');
$errors = [];
$query->invokeArgs($plugin, ['SELECT 1', &$errors]);
check($requests[0][0] === 'https://app.example.com/api/projects/123/query/' && $errors === [], 'Query API did not use the UI host');
check(ArcWind_Init_Tracker::capture('test_event') === true, 'Server capture failed');
check($requests[1][0] === 'https://events.example.com/proxy/capture/', 'Server capture did not use api_host');
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
check($plugin->sanitize_settings(['defaults' => '2026-04-01'])['defaults'] === '', 'Unknown SDK defaults profile was accepted');
ob_start();
$posthog_table->invoke($plugin);
$no_api_table = ob_get_clean();
check(strpos($no_api_table, 'aria-controls="arcwind_init_frontend_fields" aria-expanded="false"><span') !== false, 'Regional API host fallback did not unlock web tracking');
check(strpos($no_api_table, 'aria-controls="arcwind_init_main_fields" aria-expanded="false"><span') !== false, 'Dashboard statistics incorrectly required API host');
check(strpos($no_api_table, 'aria-controls="arcwind_init_errors_fields" aria-expanded="false"><span') !== false, 'Regional API host fallback did not unlock error tracking');
check_section_state($no_api_table, 'arcwind_init_frontend_fields', 'active');
check(strpos($no_api_table, 'api.addEventListener("input",gate)') === false && substr_count($no_api_table, 'return !!token.value.trim()') === 2, 'Live form still depends on an explicit API host');
$stored[ArcWind_Init_Options_Test::OPTION_KEY]['api_host'] = 'https://events.example.com';
$stored[ArcWind_Init_Options_Test::OPTION_KEY]['public_api_key'] = '';
ob_start();
$posthog_table->invoke($plugin);
$no_token_table = ob_get_clean();
check(strpos($no_token_table, 'aria-controls="arcwind_init_frontend_fields" aria-expanded="false" disabled') !== false && strpos($no_token_table, 'aria-controls="arcwind_init_errors_fields" aria-expanded="false" disabled') !== false, 'Sections that need the project token were not locked');
$stored[ArcWind_Init_Options_Test::OPTION_KEY]['public_api_key'] = 'phc_placeholder';
$stored[ArcWind_Init_Options_Test::OPTION_KEY]['project_id'] = '';
ob_start();
$posthog_table->invoke($plugin);
$no_project_table = ob_get_clean();
check(strpos($no_project_table, 'aria-controls="arcwind_init_main_fields" aria-expanded="false" disabled') !== false, 'Dashboard statistics were not locked without project ID');
check(strpos($no_project_table, 'aria-controls="arcwind_init_frontend_fields" aria-expanded="false"><span') !== false, 'Web tracking incorrectly required project ID');
check_section_state($no_project_table, 'arcwind_init_main_fields', 'error');
$stored[ArcWind_Init_Options_Test::OPTION_KEY] = $options;
$widgets = [];
$plugin->register_dashboard_widgets();
check($widgets === ['arcwind_init_dashboard_widget'], 'Unconfigured Feedback widget was registered');
ob_start();
$plugin->maybe_print_posthog_snippet_universal();
$html = ob_get_clean();
check(strpos($html, 'https:\/\/us.posthog.com') !== false, 'Region did not select the UI host');
check(strpos($html, 'https:\/\/us.i.posthog.com') !== false, 'Region did not select the API host');
$requests = [];
$errors = [];
$query->invokeArgs($plugin, ['SELECT 1', &$errors]);
check($requests[0][0] === 'https://us.posthog.com/api/projects/123/query/' && $errors === [], 'Query API did not use the regional UI host');
check(ArcWind_Init_Tracker::capture('test_event') === true && $requests[1][0] === 'https://us.i.posthog.com/capture/', 'Server capture did not use regional API host');

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
