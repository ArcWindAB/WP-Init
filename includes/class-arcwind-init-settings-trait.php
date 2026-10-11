<?php
if (!defined('ABSPATH')) exit;

trait ArcWind_Init_Settings_Trait {
    private function options() {
        $options = (array) get_option(self::OPTION_KEY, []);
        foreach (['host', 'project_id', 'personal_api_key', 'public_api_key', 'load_position', 'feedback_uuid', 'error_distinct_id', 'region', 'api_host', 'ui_host', 'defaults', 'person_profiles'] as $key) {
            if (isset($options[$key]) && !is_string($options[$key])) $options[$key] = '';
        }
        return $options;
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
                    'region'             => 'eu',
                    'api_host'           => '',
                    'ui_host'            => '',
                    'defaults'           => '',
                    'person_profiles'    => '',

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
        add_settings_field('arcwind_init_region', __('PostHog-region', 'arcwind-init'),
            [$this, 'render_select_region'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_api_host', __('Webb-API / reverse proxy', 'arcwind-init'),
            [$this, 'render_input_api_host'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_ui_host', __('PostHog UI-värd', 'arcwind-init'),
            [$this, 'render_input_ui_host'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_defaults', __('SDK defaults', 'arcwind-init'),
            [$this, 'render_input_defaults'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_person_profiles', __('Person profiles', 'arcwind-init'),
            [$this, 'render_select_person_profiles'], 'arcwind_init_posthog', 'arcwind_init_frontend');
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
            'arcwind_init_feedback_tab'
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
        $tab = isset($_POST['arcwind_init_active_tab']) && is_string($_POST['arcwind_init_active_tab']) ? sanitize_key(wp_unslash($_POST['arcwind_init_active_tab'])) : 'posthog';
        $input = is_array($input) ? $input : [];
        // Keep the old browser host when an existing installation first gains
        // the separate API host setting. New installations use the region host.
        if (is_array($old) && !array_key_exists('api_host', $old) &&
            !empty($old['host']) && empty($input['api_host']) && is_string($old['host'])) {
            $input['api_host'] = $old['host'];
        }
        $old = wp_parse_args((array) $old, ['feedback_enabled' => 1]);
        $input = array_merge($old, $input);
        $out = [];
        // Server (widget)
        $out['host']               = isset($input['host']) && is_string($input['host']) ? esc_url_raw(trim($input['host'])) : 'https://tel.arc.vin/_p';
        $out['project_id']         = isset($input['project_id']) && is_scalar($input['project_id']) ? preg_replace('/[^0-9]/', '', (string) $input['project_id']) : '';
        $new_personal_key = isset($input['personal_api_key']) && is_string($input['personal_api_key']) ? sanitize_text_field(trim($input['personal_api_key'])) : '';
        $out['personal_api_key'] = !empty($input['personal_api_key_clear']) ? '' : ($new_personal_key !== '' ? $new_personal_key : (is_string($old['personal_api_key'] ?? null) ? $old['personal_api_key'] : ''));

        // Front-end
        $out['enable_tracking']    = empty($input['enable_tracking']) ? 0 : 1;
        $out['public_api_key']     = isset($input['public_api_key']) && is_string($input['public_api_key']) ? sanitize_text_field(trim($input['public_api_key'])) : '';
        $out['capture_pageview']   = empty($input['capture_pageview']) ? 0 : 1;
        $out['autocapture']        = empty($input['autocapture']) ? 0 : 1;
        $out['load_position']      = in_array(($input['load_position'] ?? 'head'), ['head','footer'], true) ? ($input['load_position'] ?? 'head') : 'head';
        $region = $input['region'] ?? 'eu';
        $out['region']             = in_array($region, ['eu', 'us'], true) ? $region : 'eu';
        foreach (['api_host', 'ui_host'] as $key) {
            $url = isset($input[$key]) && is_string($input[$key]) ? esc_url_raw(trim($input[$key])) : '';
            $parts = $url !== '' ? wp_parse_url($url) : false;
            $out[$key] = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) &&
                !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']) ? rtrim($url, '/') : '';
        }
        $defaults = isset($input['defaults']) && is_string($input['defaults']) ? trim($input['defaults']) : '';
        $out['defaults'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $defaults) && checkdate((int) substr($defaults, 5, 2), (int) substr($defaults, 8, 2), (int) substr($defaults, 0, 4)) ? $defaults : '';
        $out['person_profiles'] = in_array(($input['person_profiles'] ?? ''), ['identified_only', 'always'], true) ? $input['person_profiles'] : '';

        // Feedback
        $out['feedback_enabled'] = empty($input['feedback_enabled']) ? 0 : 1;
        $out['feedback_uuid']      = isset($input['feedback_uuid']) && is_string($input['feedback_uuid']) ? preg_replace('/[^A-Za-z0-9]/', '', strtoupper($input['feedback_uuid'])) : '';

        // Error tracking
        $out['enable_error_tracking'] = empty($input['enable_error_tracking']) ? 0 : 1;
        $out['error_distinct_id']     = isset($input['error_distinct_id']) && is_string($input['error_distinct_id']) ? sanitize_text_field(trim($input['error_distinct_id'])) : 'arcwind-init:server';

        return $out;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'posthog';
        if (!in_array($tab, ['posthog', 'feedback', 'oneuptime', 'diagnostics'], true)) $tab = 'posthog';
        $tabs = ['posthog' => 'PostHog', 'feedback' => 'Feedback', 'oneuptime' => 'OneUptime', 'diagnostics' => 'Diagnostik'];
        echo '<div class="wrap"><h1>ArcWind Init – Inställningar</h1><nav class="nav-tab-wrapper" aria-label="Integrationer">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(['page' => 'arcwind_init', 'tab' => $key], admin_url('options-general.php'));
            echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if ($tab === 'diagnostics') {
            $this->diagnostics->render();
            echo '</div>';
            return;
        }
        echo '<form method="post" action="options.php">';
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
    public function render_input_host()              { $o = $this->options(); ?>
        <input type="url" style="width:420px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[host]" value="<?php echo esc_attr($o['host'] ?? 'https://tel.arc.vin/_p'); ?>" placeholder="https://tel.arc.vin/_p" /><?php
    }
    public function render_input_project_id()        { $o = $this->options(); ?>
        <input type="text" style="width:200px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[project_id]" value="<?php echo esc_attr($o['project_id'] ?? ''); ?>" placeholder="t.ex. 1234" /><?php
    }
    public function render_input_personal_api_key()  { $o = $this->options(); ?>
        <input type="password" style="width:420px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[personal_api_key]" value="" placeholder="phx_..." autocomplete="off" />
        <?php if (!empty($o['personal_api_key'])): ?><p class="description">En nyckel är sparad. Lämna fältet tomt för att behålla den.</p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[personal_api_key_clear]" value="1"> Radera sparad nyckel</label><?php endif; ?><?php
    }
    public function render_checkbox_enable_tracking(){ $o = $this->options(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_tracking]" value="1" <?php checked(!empty($o['enable_tracking'])); ?> /> Aktivera PostHog-spårning på webbplatsen</label><?php
    }
    public function render_input_public_api_key()    { $o = $this->options(); ?>
        <input type="text" style="width:420px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[public_api_key]" value="<?php echo esc_attr($o['public_api_key'] ?? ''); ?>" placeholder="phc_..." /><?php
    }
    public function render_select_region()           { $o = $this->options(); $region = $o['region'] ?? 'eu'; ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[region]">
            <option value="eu" <?php selected($region, 'eu'); ?>>EU</option>
            <option value="us" <?php selected($region, 'us'); ?>>US</option>
        </select><p class="description">Väljer standardvärde för UI-värden. Anpassade API-adresser ändras inte automatiskt.</p><?php
    }
    public function render_input_api_host()          { $o = $this->options(); ?>
        <input type="url" style="width:420px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_host]" value="<?php echo esc_attr($o['api_host'] ?? ''); ?>" placeholder="https://events.example.com" />
        <p class="description">PostHog <code>api_host</code> i webbläsaren. Ange en HTTPS reverse proxy, inklusive eventuell sökväg. Tomt använder vald regions direktadress. På äldre installationer behålls tidigare värd vid första sparningen.</p><?php
    }
    public function render_input_ui_host()           { $o = $this->options(); ?>
        <input type="url" style="width:420px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ui_host]" value="<?php echo esc_attr($o['ui_host'] ?? ''); ?>" placeholder="https://eu.posthog.com" />
        <p class="description">PostHog <code>ui_host</code> för toolbar och inloggning. Tomt använder vald region.</p><?php
    }
    public function render_input_defaults()          { $o = $this->options(); ?>
        <input type="date" name="<?php echo esc_attr(self::OPTION_KEY); ?>[defaults]" value="<?php echo esc_attr($o['defaults'] ?? ''); ?>" />
        <p class="description">PostHog <code>defaults</code>, till exempel <code>2026-05-30</code>. Tomt bevarar SDK:ns befintliga beteende.</p><?php
    }
    public function render_select_person_profiles()  { $o = $this->options(); $value = $o['person_profiles'] ?? ''; ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[person_profiles]">
            <option value="" <?php selected($value, ''); ?>>SDK-standard</option>
            <option value="identified_only" <?php selected($value, 'identified_only'); ?>>Bara identifierade</option>
            <option value="always" <?php selected($value, 'always'); ?>>Alltid</option>
        </select><?php
    }
    public function render_checkbox_capture_pageview(){ $o = $this->options(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[capture_pageview]" value="1" <?php checked(!empty($o['capture_pageview'])); ?> /> Kör <code>capture_pageview</code> vid laddning</label><?php
    }
    public function render_checkbox_autocapture()    { $o = $this->options(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[autocapture]" value="1" <?php checked(!empty($o['autocapture'])); ?> /> Aktivera <code>autocapture</code></label><?php
    }
    public function render_select_load_position()    { $o = $this->options(); $val = $o['load_position'] ?? 'head'; ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[load_position]">
            <option value="head"   <?php selected($val, 'head'); ?>>Head</option>
            <option value="footer" <?php selected($val, 'footer'); ?>>Footer</option>
        </select><?php
    }
    public function render_checkbox_enable_error_tracking(){ $o = $this->options(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_error_tracking]" value="1" <?php checked(!empty($o['enable_error_tracking'])); ?> /> Skicka plugin-fel som events</label><?php
    }
    public function render_input_error_distinct_id() { $o = $this->options(); ?>
        <input type="text" style="width:260px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[error_distinct_id]" value="<?php echo esc_attr($o['error_distinct_id'] ?? 'arcwind-init:server'); ?>" placeholder="arcwind-init:server" /><?php
    }
}
