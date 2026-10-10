<?php
/**
 * Plugin Name: ArcWind Init
 * Description: Initierar PostHog (EU som standard) och lägger till en enkel dashboard-widget “Statistik” (aktiva användare & sidvisningar) samt en feedback-widget. Inkluderar felspårning.
 * Version:     1.7.0
 * Author:      ArcWind AB
 * License:     GPL-2.0-or-later
 * Text Domain: arcwind-init
 * Update URI:  https://github.com/ArcWindAB/WP-Init
 */

if (!defined('ABSPATH')) exit;
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-tracker.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-arcwind-init-oneuptime.php';

class ArcWind_Init {
    const OPTION_KEY    = 'arcwind_init_settings';
    const NONCE_KEY     = 'arcwind_init_nonce';
    const ERR_TRANSIENT = 'arcwind_init_last_errors';
    const ERR_TTL       = 30 * MINUTE_IN_SECONDS;

    private static $version = '0.0.0';

    public function __construct() {        
        $plugin_data = get_file_data(__FILE__, [
            'Version' => 'Version',
        ], 'plugin');

        self::$version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

        // Settings + menu
        add_action('admin_init',         [$this, 'register_settings']);
        new ArcWind_Init_OneUptime();
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

    /* =========================
     * Settings
     * ========================= */
    public function register_settings() {
        register_setting(
            'arcwind_init_settings_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => [
                    // Server-side (dashboard widget)
                    'host'               => 'https://tel.arc.vin/_p',
                    'project_id'         => '',
                    'personal_api_key'   => '',

                    // Front-end tracking
                    'enable_tracking'    => 1,
                    'public_api_key'     => '',
                    'capture_pageview'   => 1,
                    'autocapture'        => 1,
                    'load_position'      => 'head', // head|footer

                    // Feedback widget
                    'feedback_uuid'      => '',
                    'feedback_enabled'   => 1,

                    // Error tracking
                    'enable_error_tracking' => 1,
                    'error_distinct_id'     => 'arcwind-init:server',
                ],
            ]
        );

        add_settings_section(
            'arcwind_init_main',
            __('PostHog-anslutning (server)', 'arcwind-init'),
            function () {
                echo '<p>Konfigurera PostHog för startsidans widget (server-side Query API). EU-molnets värd är förifylld.</p>';
            },
            'arcwind_init_posthog'
        );
        add_settings_field('arcwind_init_host', __('PostHog-värd', 'arcwind-init'),
            [$this, 'render_input_host'], 'arcwind_init_posthog', 'arcwind_init_main');
        add_settings_field('arcwind_init_project_id', __('Project ID', 'arcwind-init'),
            [$this, 'render_input_project_id'], 'arcwind_init_posthog', 'arcwind_init_main');
        add_settings_field('arcwind_init_personal_api_key', __('Personal API Key (phx_...)', 'arcwind-init'),
            [$this, 'render_input_personal_api_key'], 'arcwind_init_posthog', 'arcwind_init_main');

        add_settings_section(
            'arcwind_init_frontend',
            __('Webbspårning (webbläsare)', 'arcwind-init'),
            function () {
                echo '<p>Aktivera PostHog på din webbplats med din publika API-nyckel (phc_...). Snippet laddas alltid; inloggade användare identifieras.</p>';
            },
            'arcwind_init_posthog'
        );
        add_settings_field('arcwind_init_enable_tracking', __('Aktivera spårning', 'arcwind-init'),
            [$this, 'render_checkbox_enable_tracking'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_public_api_key', __('Public API Key (phc_...)', 'arcwind-init'),
            [$this, 'render_input_public_api_key'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_capture_pageview', __('Registrera sidvisning vid laddning', 'arcwind-init'),
            [$this, 'render_checkbox_capture_pageview'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_autocapture', __('Autocapture (klick, formulär, m.m.)', 'arcwind-init'),
            [$this, 'render_checkbox_autocapture'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_load_position', __('Laddningsposition', 'arcwind-init'),
            [$this, 'render_select_load_position'], 'arcwind_init_posthog', 'arcwind_init_frontend');

        add_settings_section(
            'arcwind_init_feedback',
            __('Feedback-widget', 'arcwind-init'),
            function () {
                echo '<p>Ställ in kort-UUID som används av knappen <em>“Rapportera fel eller önska funktion”</em> (öppnar <code>https://t.arc.vin/s/{UUID}</code>).</p>';
            },
            'arcwind_init_posthog'
        );
        add_settings_field('arcwind_init_feedback_enabled', __('Aktivera feedback', 'arcwind-init'),
            [$this, 'render_feedback_enabled'], 'arcwind_init_feedback_tab', 'arcwind_init_feedback');
        add_settings_field('arcwind_init_feedback_uuid', __('Feedback-UUID (t.ex. MM0H1A5A)', 'arcwind-init'),
            [$this, 'render_input_feedback_uuid'], 'arcwind_init_feedback_tab', 'arcwind_init_feedback');

        add_settings_section(
            'arcwind_init_errors',
            __('Felspårning', 'arcwind-init'),
            function () {
                echo '<p>Skicka plugin-/serverfel som events till PostHog (använder din publika API-nyckel via server-side capture).</p>';
            },
            'arcwind_init_posthog'
        );
        add_settings_field('arcwind_init_enable_error_tracking', __('Aktivera felspårning', 'arcwind-init'),
            [$this, 'render_checkbox_enable_error_tracking'], 'arcwind_init_posthog', 'arcwind_init_errors');
        add_settings_field('arcwind_init_error_distinct_id', __('Distinct ID för fel', 'arcwind-init'),
            [$this, 'render_input_error_distinct_id'], 'arcwind_init_posthog', 'arcwind_init_errors');
    }

    public function add_settings_page() {
        add_options_page(
            __('ArcWind Init', 'arcwind-init'),
            __('ArcWind Init', 'arcwind-init'),
            'manage_options',
            'arcwind_init',
            [$this, 'render_settings_page']
        );
    }

    public function sanitize_settings($input) {
        $old = get_option(self::OPTION_KEY, []);
        $tab = isset($_POST['arcwind_init_active_tab']) ? sanitize_key(wp_unslash($_POST['arcwind_init_active_tab'])) : 'posthog';
        $input = is_array($input) ? $input : [];
        $input = array_merge((array) $old, $input);
        $out = [];
        // Server (widget)
        $out['host']               = isset($input['host']) ? esc_url_raw(trim($input['host'])) : 'https://tel.arc.vin/_p';
        $out['project_id']         = isset($input['project_id']) ? preg_replace('/[^0-9]/', '', $input['project_id']) : '';
        $out['personal_api_key']   = isset($input['personal_api_key']) ? sanitize_text_field(trim($input['personal_api_key'])) : '';

        // Front-end
        $out['enable_tracking']    = empty($input['enable_tracking']) ? 0 : 1;
        $out['public_api_key']     = isset($input['public_api_key']) ? sanitize_text_field(trim($input['public_api_key'])) : '';
        $out['capture_pageview']   = empty($input['capture_pageview']) ? 0 : 1;
        $out['autocapture']        = empty($input['autocapture']) ? 0 : 1;
        $out['load_position']      = in_array(($input['load_position'] ?? 'head'), ['head','footer'], true) ? $input['load_position'] : 'head';

        // Feedback
        $out['feedback_enabled'] = empty($input['feedback_enabled']) ? 0 : 1;
        $out['feedback_uuid']      = isset($input['feedback_uuid']) ? preg_replace('/[^A-Za-z0-9]/', '', strtoupper($input['feedback_uuid'])) : '';

        // Error tracking
        $out['enable_error_tracking'] = empty($input['enable_error_tracking']) ? 0 : 1;
        $out['error_distinct_id']     = isset($input['error_distinct_id']) ? sanitize_text_field(trim($input['error_distinct_id'])) : 'arcwind-init:server';

        return $out;
    }

    public function render_feedback_enabled() {
        $o = get_option(self::OPTION_KEY);
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[feedback_enabled]" value="1" ' . checked(!isset($o['feedback_enabled']) || $o['feedback_enabled'], true, false) . '> Aktivera feedback</label>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'posthog';
        if (!in_array($tab, ['posthog', 'feedback', 'oneuptime'], true)) $tab = 'posthog';
        $tabs = ['posthog' => 'PostHog', 'feedback' => 'Feedback', 'oneuptime' => 'OneUptime'];
        echo '<div class="wrap"><h1>ArcWind Init – Inställningar</h1><nav class="nav-tab-wrapper" aria-label="Integrationer">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(['page' => 'arcwind_init', 'tab' => $key], admin_url('options-general.php'));
            echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><form method="post" action="options.php">';
        if ($tab === 'oneuptime') {
            settings_fields('arcwind_init_oneuptime_group');
            do_settings_sections('arcwind_init_oneuptime');
        } else {
            settings_fields('arcwind_init_settings_group');
            echo '<input type="hidden" name="arcwind_init_active_tab" value="' . esc_attr($tab) . '">';
            if ($tab === 'feedback') {
                echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY) . '[feedback_enabled]" value="0">';
                do_settings_sections('arcwind_init_feedback_tab');
            } else {
                echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY) . '[enable_tracking]" value="0">';
                echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY) . '[capture_pageview]" value="0">';
                echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY) . '[autocapture]" value="0">';
                echo '<input type="hidden" name="' . esc_attr(self::OPTION_KEY) . '[enable_error_tracking]" value="0">';
                do_settings_sections('arcwind_init_posthog');
            }
        }
        submit_button(__('Spara ändringar', 'arcwind-init'));
        echo '</form></div>';
    }

    /* ===== Field renderers ===== */
    public function render_input_host()              { $o = get_option(self::OPTION_KEY); ?>
        <input type="url" style="width:420px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[host]" value="<?php echo esc_attr($o['host'] ?? 'https://tel.arc.vin/_p'); ?>" placeholder="https://tel.arc.vin/_p" /><?php
    }
    public function render_input_project_id()        { $o = get_option(self::OPTION_KEY); ?>
        <input type="text" style="width:200px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[project_id]" value="<?php echo esc_attr($o['project_id'] ?? ''); ?>" placeholder="t.ex. 1234" /><?php
    }
    public function render_input_personal_api_key()  { $o = get_option(self::OPTION_KEY); ?>
        <input type="password" style="width:420px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[personal_api_key]" value="<?php echo esc_attr($o['personal_api_key'] ?? ''); ?>" placeholder="phx_..." /><?php
    }
    public function render_checkbox_enable_tracking(){ $o = get_option(self::OPTION_KEY); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_tracking]" value="1" <?php checked(!empty($o['enable_tracking'])); ?> /> Aktivera PostHog-spårning på webbplatsen</label><?php
    }
    public function render_input_public_api_key()    { $o = get_option(self::OPTION_KEY); ?>
        <input type="text" style="width:420px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[public_api_key]" value="<?php echo esc_attr($o['public_api_key'] ?? ''); ?>" placeholder="phc_..." /><?php
    }
    public function render_checkbox_capture_pageview(){ $o = get_option(self::OPTION_KEY); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[capture_pageview]" value="1" <?php checked(!empty($o['capture_pageview'])); ?> /> Kör <code>capture_pageview</code> vid laddning</label><?php
    }
    public function render_checkbox_autocapture()    { $o = get_option(self::OPTION_KEY); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[autocapture]" value="1" <?php checked(!empty($o['autocapture'])); ?> /> Aktivera <code>autocapture</code></label><?php
    }
    public function render_select_load_position()    { $o = get_option(self::OPTION_KEY); $val = $o['load_position'] ?? 'head'; ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[load_position]">
            <option value="head"   <?php selected($val, 'head'); ?>>Head</option>
            <option value="footer" <?php selected($val, 'footer'); ?>>Footer</option>
        </select><?php
    }
    public function render_input_feedback_uuid()     { $o = get_option(self::OPTION_KEY); ?>
        <input type="text" style="width:260px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[feedback_uuid]" value="<?php echo esc_attr($o['feedback_uuid'] ?? ''); ?>" placeholder="MM0H1A5A" /><?php
    }
    public function render_checkbox_enable_error_tracking(){ $o = get_option(self::OPTION_KEY); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_error_tracking]" value="1" <?php checked(!empty($o['enable_error_tracking'])); ?> /> Skicka plugin-fel som events</label><?php
    }
    public function render_input_error_distinct_id() { $o = get_option(self::OPTION_KEY); ?>
        <input type="text" style="width:260px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[error_distinct_id]" value="<?php echo esc_attr($o['error_distinct_id'] ?? 'arcwind-init:server'); ?>" placeholder="arcwind-init:server" /><?php
    }

    /* =========================
     * Dashboard widgets
     * ========================= */
    public function register_dashboard_widgets() {
        wp_add_dashboard_widget(
            'arcwind_init_dashboard_widget',
            __('Statistik', 'arcwind-init'),
            [$this, 'render_dashboard_widget']
        );

        $feedback_options = get_option(self::OPTION_KEY, []);
        if (!isset($feedback_options['feedback_enabled']) || $feedback_options['feedback_enabled']) wp_add_dashboard_widget(
            'arcwind_init_feedback_widget',
            __('Rapportera fel eller önska funktion', 'arcwind-init'),
            [$this, 'render_feedback_widget']
        );
    }

    public function render_feedback_widget() {
        $o = get_option(self::OPTION_KEY);
        $uuid = $o['feedback_uuid'] ?? '';
        $url  = $uuid ? ('https://t.arc.vin/s/' . rawurlencode($uuid)) : '';
        echo '<p>Öppna formuläret för att rapportera fel eller önska en ny funktion.</p>';
        if ($uuid) {
            echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url($url) . '">Öppna feedbackformulär</a></p>';
        } else {
            echo '<p><em>Ange din UUID under <a href="' . esc_url(admin_url('options-general.php?page=arcwind_init')) . '">Inställningar → ArcWind Init</a> för att visa knappen.</em></p>';
        }
    }

    public function render_dashboard_widget() {
        $opts = get_option(self::OPTION_KEY);
        $missing = [];

        if (empty($opts['host']))             $missing[] = 'Värd';
        if (empty($opts['project_id']))       $missing[] = 'Project ID';
        if (empty($opts['personal_api_key'])) $missing[] = 'Personal API Key';

        if (!empty($missing)) {
            echo '<p><strong>PostHog är inte konfigurerat.</strong></p>';
            echo '<p>Saknas: ' . esc_html(implode(', ', $missing)) . '</p>';
            echo '<p><a href="' . esc_url(admin_url('options-general.php?page=arcwind_init')) . '">Gå till inställningar</a></p>';
            return;
        }

        $cache_key = 'arcwind_init_stats_v1';
        $data = get_transient($cache_key);

        if (false === $data) {
            $errors = [];

            // Active users (7 days)
            $active_7d = $this->fetch_scalar(
                'SELECT count(DISTINCT distinct_id) AS c FROM events WHERE timestamp >= now() - INTERVAL 7 DAY',
                $errors
            );

            // Pageviews (7 days)
            $pageviews_7d = $this->fetch_scalar(
                'SELECT count() AS c FROM events WHERE event = \'$pageview\' AND timestamp >= now() - INTERVAL 7 DAY',
                $errors
            );

            $data = [
                'active_7d'    => is_numeric($active_7d) ? (int)$active_7d : null,
                'pageviews_7d' => is_numeric($pageviews_7d) ? (int)$pageviews_7d : null,
                'errors'       => $errors,
                'fetched_at'   => current_time('mysql'),
            ];

            set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);

            if (!empty($errors)) {
                set_transient(self::ERR_TRANSIENT, $errors, self::ERR_TTL);
            }
        }

        echo '<div class="arcwind-grid">';
        $this->render_stat_card('Aktiva användare (7 dagar)', $this->format_num($data['active_7d']));
        $this->render_stat_card('Sidvisningar (7 dagar)', $this->format_num($data['pageviews_7d']));
        echo '</div>';

        echo '<p class="arcwind-subtle">Uppdaterad: ' . esc_html($data['fetched_at']) . '. Cache: 10 min.</p>';
    }

    private function render_stat_card($title, $value) {
        echo '<div class="arcwind-card">';
        echo '<h4>' . esc_html($title) . '</h4>';
        echo '<div class="arcwind-value">' . esc_html($value ?? '—') . '</div>';
        echo '</div>';
    }

    private function format_num($n) {
        if ($n === null) return '—';
        return number_format_i18n((int)$n);
    }

    /* =========================
     * Query API helpers + error capture
     * ========================= */
    private function fetch_scalar($hogql, &$errors) {
        $res = $this->query_posthog($hogql, $errors);
        if (!$res || empty($res['results']) || !is_array($res['results'])) {
            $errors[] = 'Scalar query returned no results.';
            return null;
        }
        $first = $res['results'][0] ?? null;
        if (is_array($first)) {
            $vals = array_values($first);
            return $vals[0] ?? null;
        }
        return $first;
    }

    private function query_posthog($hogql, &$errors) {
        $opts = get_option(self::OPTION_KEY);
        $host       = rtrim($opts['host'] ?? 'https://tel.arc.vin/_p', '/');
        $project_id = $opts['project_id'] ?? '';
        $api_key    = $opts['personal_api_key'] ?? '';

        if (empty($host) || empty($project_id) || empty($api_key)) {
            $errors[] = 'Saknad konfiguration.';
            return null;
        }

        $endpoint = $host . '/api/projects/' . rawurlencode($project_id) . '/query/';

        $body = [
            'query' => [
                'kind'  => 'HogQLQuery',
                'query' => $hogql,
            ],
        ];

        $args = [
            'method'      => 'POST',
            'timeout'     => 25,
            'headers'     => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'        => wp_json_encode($body),
            'data_format' => 'body',
        ];

        $resp = wp_remote_post($endpoint, $args);

        if (is_wp_error($resp)) {
            $msg = 'HTTP-fel: ' . $resp->get_error_message();
            $errors[] = $msg;
            $this->capture_error('http_error', $msg, ['endpoint' => $endpoint, 'hogql' => $hogql]);
            return null;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);

        if ($code < 200 || $code >= 300) {
            $msg = 'API-fel ' . $code . ': ' . $raw;
            $errors[] = $msg;
            $this->capture_error('api_error', $msg, ['endpoint' => $endpoint, 'hogql' => $hogql, 'status' => $code]);
            return null;
        }

        $json = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $msg = 'Ogiltig JSON från API.';
            $errors[] = $msg;
            $this->capture_error('json_error', $msg, ['endpoint' => $endpoint, 'snippet' => substr($raw, 0, 500)]);
            return null;
        }

        if (!isset($json['results'])) {
            if (isset($json['result'])) {
                $json['results'] = $json['result'];
            } else {
                $msg = 'Oväntat API-format.';
                $errors[] = $msg;
                $this->capture_error('shape_error', $msg, ['endpoint' => $endpoint, 'payload_keys' => implode(',', array_keys($json))]);
                return null;
            }
        }

        return $json;
    }

    private function capture_error($kind, $message, $props = []) {
        $opts = get_option(self::OPTION_KEY);
        if (empty($opts['enable_error_tracking'])) {
            return;
        }

        $distinct_id = $opts['error_distinct_id'] ?: 'arcwind-init:server';

        ArcWind_Init_Tracker::capture('arcwind_init_error', array_merge([
            'kind'    => (string) $kind,
            'message' => (string) $message,
            'php'     => PHP_VERSION,
            'wp'      => get_bloginfo('version'),
        ], is_array($props) ? $props : []), $distinct_id);

        $prev = get_transient(self::ERR_TRANSIENT);
        $prev = is_array($prev) ? $prev : [];
        $prev[] = strtoupper($kind) . ': ' . $message;
        $prev = array_slice($prev, -5);
        set_transient(self::ERR_TRANSIENT, $prev, self::ERR_TTL);
    }

    /* =========================
     * Minimal admin CSS
     * ========================= */
    public function enqueue_admin_assets($hook_suffix) {
        if ($hook_suffix !== 'index.php' && $hook_suffix !== 'settings_page_arcwind_init') return;

        $css = "
        .arcwind-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:8px}
        .arcwind-card{background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:12px}
        .arcwind-card h4{margin:0 0 6px 0;font-size:14px;color:#1d2327}
        .arcwind-value{font-size:22px;font-weight:700}
        .arcwind-subtle{font-size:12px;color:#646970}
        ";
        wp_register_style('arcwind_init_inline', false);
        wp_enqueue_style('arcwind_init_inline');
        wp_add_inline_style('arcwind_init_inline', $css);
    }

    /* =========================
     * Always-on PostHog snippet (front + admin) using inline stub
     * ========================= */
    public function maybe_print_posthog_snippet_universal() {
        $opts = get_option(self::OPTION_KEY);
        if (empty($opts['enable_tracking'])) return;

        $position = $opts['load_position'] ?? 'head';
        $current  = current_action(); // wp_head|wp_footer|admin_head|admin_footer
        $need_head   = ($position === 'head');
        $need_footer = ($position === 'footer');

        if (
            ($current === 'wp_head'      && !$need_head)   ||
            ($current === 'wp_footer'    && !$need_footer) ||
            ($current === 'admin_head'   && !$need_head)   ||
            ($current === 'admin_footer' && !$need_footer)
        ) {
            return;
        }

        $this->print_snippet($opts);
    }

    private function print_snippet($opts) {
        $host            = rtrim($opts['host'] ?? 'https://tel.arc.vin/_p', '/');
        $public_api_key  = $opts['public_api_key'] ?? '';
        $capture_pageview= !empty($opts['capture_pageview']);
        $autocapture     = !empty($opts['autocapture']);
        if (empty($host) || empty($public_api_key)) return;

        // Build identify + register payloads if user is logged in
        $identify_js = '';
        $register_js = 'posthog.register({wp_is_logged_in:false});';

        if (is_user_logged_in()) {
            $u     = wp_get_current_user();
            $uid   = (string) $u->ID;                 // stable distinct_id
            $uname = (string) $u->user_login;
            $dname = (string) $u->display_name;       // name to send
            $roles = array_values((array) $u->roles);
            $isAdm = user_can($u, 'manage_options') ? true : false;

            $email = is_string($u->user_email) ? strtolower(trim($u->user_email)) : '';
            $email_hash = $email ? hash('sha256', $email) : null;

            $personProps = [
                'name'            => $dname,
                'wp_user_id'      => $uid,
                'wp_username'     => $uname,
                'wp_user_roles'   => $roles,
                'wp_is_admin'     => $isAdm,
                'wp_email_sha256' => $email_hash,
                'site_url'        => home_url('/'),
            ];
            $eventProps = [
                'wp_is_logged_in' => true,
                'wp_user_id'      => $uid,
                'wp_user_roles'   => $roles,
                'wp_is_admin'     => $isAdm,
            ];

            $identify_js = "posthog.identify(" .
                wp_json_encode($uid) . ", " .
                wp_json_encode($personProps) .
            ");";

            $register_js = "posthog.register(" . wp_json_encode($eventProps) . ");";
        }
        ?>
        <!-- PostHog spårning (injekterad av ArcWind Init) -->
        <script>
!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
posthog.init('<?php echo esc_js($public_api_key); ?>', {
    api_host: '<?php echo esc_js($host); ?>',
    ui_host: 'https://eu.posthog.com', 
    capture_pageview: <?php echo $capture_pageview ? 'true' : 'false'; ?>,
    autocapture: <?php echo $autocapture ? 'true' : 'false'; ?>
});
<?php echo $register_js; ?>
<?php echo $identify_js; ?>
        </script>
        <!-- /PostHog spårning -->
        <?php
    }   

    public function enqueue_tracking_bridge() {
        $opts = get_option(self::OPTION_KEY);

        if (empty($opts['enable_tracking']) || empty($opts['public_api_key'])) {
            return;
        }

        wp_register_script(
            'arcwind-init-tracker',
            plugins_url('assets/js/arcwind-init-tracker.js', __FILE__),
            [],
            self::get_version(),
            true
        );

        wp_enqueue_script('arcwind-init-tracker');

        $user_data = null;
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            $user_data = [
                'id'    => (string) $u->ID,
                'name'  => (string) $u->display_name,
                'roles' => array_values((array) $u->roles),
            ];
        }

        wp_add_inline_script(
            'arcwind-init-tracker',
            'window.ArcWindInitConfig = ' . wp_json_encode([
                'version'  => self::get_version(),
                'isAdmin'  => is_admin(),
                'pageType' => is_admin() ? 'admin' : 'frontend',
                'user'     => $user_data,
            ]) . ';',
            'before'
        );
    }
}

class ArcWind_Init_GitHub_Updater {
    const GITHUB_REPO = 'ArcWindAB/WP-Init';
    const CACHE_KEY   = 'arcwind_init_github_release';
    const CACHE_TTL   = 3600;

    private $plugin_file;
    private $plugin_basename;
    private $plugin_slug;
    private $current_version;

    public function __construct($plugin_file) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);

        $this->plugin_slug = dirname($this->plugin_basename);
        if ($this->plugin_slug === '.' || $this->plugin_slug === '/') {
            $this->plugin_slug = basename($this->plugin_basename, '.php');
        }

        $plugin_data = get_file_data($plugin_file, [
            'Version' => 'Version',
        ], 'plugin');

        $this->current_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

        add_filter('update_plugins_github.com', [$this, 'filter_update'], 10, 4);
        add_filter('plugins_api', [$this, 'filter_plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_directory_name'], 10, 4);
    }

    public function filter_update($update, $plugin_data, $plugin_file, $locales) {
        if ($plugin_file !== $this->plugin_basename) {
            return $update;
        }

        $release = $this->get_latest_release();
        if (empty($release) || empty($release['version']) || empty($release['package'])) {
            return $update;
        }

        if (version_compare($release['version'], $this->current_version, '<=')) {
            return $update;
        }

        return [
            'id'          => 'https://github.com/' . self::GITHUB_REPO,
            'slug'        => $this->plugin_slug,
            'version'     => $release['version'],
            'url'         => 'https://github.com/' . self::GITHUB_REPO,
            'package'     => $release['package'],
            'tested'      => '',
            'requires_php'=> '',
        ];
    }

    public function filter_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (empty($release)) {
            return $result;
        }

        $info = new stdClass();
        $info->name          = 'ArcWind Init';
        $info->slug          = $this->plugin_slug;
        $info->version       = !empty($release['version']) ? $release['version'] : $this->current_version;
        $info->author        = '<a href="https://arcwind.se">ArcWind AB</a>';
        $info->homepage      = 'https://github.com/' . self::GITHUB_REPO;
        $info->requires      = '';
        $info->tested        = '';
        $info->requires_php  = '';
        $info->download_link = !empty($release['package']) ? $release['package'] : '';
        $info->sections      = [
            'description' => 'ArcWind Init för PostHog, dashboard-widget och feedback-widget.',
            'changelog'   => !empty($release['changelog']) ? wp_kses_post($release['changelog']) : 'Ingen changelog tillgänglig.',
        ];

        return $info;
    }

    public function fix_directory_name($source, $remote_source, $upgrader, $hook_extra) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }

        $desired = trailingslashit(WP_PLUGIN_DIR . '/' . $this->plugin_slug);

        if (wp_normalize_path($source) === wp_normalize_path($desired)) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $source;
        }

        if ($wp_filesystem->move($source, $desired, true)) {
            return $desired;
        }

        return $source;
    }

    private function get_latest_release() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300 || empty($body)) {
            return false;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return false;
        }

        $tag_name = isset($json['tag_name']) ? trim($json['tag_name']) : '';
        $version  = ltrim($tag_name, 'vV');

        $package = '';
        if (!empty($json['assets']) && is_array($json['assets'])) {
            foreach ($json['assets'] as $asset) {
                if (empty($asset['name']) || empty($asset['browser_download_url'])) {
                    continue;
                }

                if (preg_match('/\.zip$/i', $asset['name'])) {
                    $package = $asset['browser_download_url'];
                    break;
                }
            }
        }

        if (empty($package) && !empty($json['zipball_url'])) {
            $package = $json['zipball_url'];
        }

        $release = [
            'version'   => $version,
            'package'   => $package,
            'changelog' => !empty($json['body']) ? nl2br(esc_html($json['body'])) : '',
            'tag_name'  => $tag_name,
        ];

        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }
}

new ArcWind_Init();
new ArcWind_Init_GitHub_Updater(__FILE__);
