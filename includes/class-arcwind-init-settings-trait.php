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
            'arcwind_init_connection',
            __('PostHog-anslutning', 'arcwind-init'),
            function () {
                echo '<p>Välj region och ange webb-API, PostHogs gränssnitt, projekt-ID och projekt-token.</p>';
            },
            'arcwind_init_posthog'
        );
        add_settings_field('arcwind_init_region', __('PostHog-region', 'arcwind-init'),
            [$this, 'render_select_region'], 'arcwind_init_posthog', 'arcwind_init_connection');
        add_settings_field('arcwind_init_api_host', __('PostHog API-värd eller proxy', 'arcwind-init') . ' <code>api_host</code>',
            [$this, 'render_input_api_host'], 'arcwind_init_posthog', 'arcwind_init_connection');
        add_settings_field('arcwind_init_ui_host', __('PostHog UI-värd', 'arcwind-init') . ' <code>ui_host</code>',
            [$this, 'render_input_ui_host'], 'arcwind_init_posthog', 'arcwind_init_connection');
        add_settings_field('arcwind_init_project_id', __('Projekt-ID', 'arcwind-init'),
            [$this, 'render_input_project_id'], 'arcwind_init_posthog', 'arcwind_init_connection');
        add_settings_field('arcwind_init_public_api_key', __('Projekt-token (phc_...)', 'arcwind-init'),
            [$this, 'render_input_public_api_key'], 'arcwind_init_posthog', 'arcwind_init_connection');

        add_settings_section(
            'arcwind_init_main',
            __('Dashboardstatistik', 'arcwind-init'),
            function () {
                echo '<p>Anslut PostHog till statistikwidgeten i WordPress-admin.</p>';
            },
            'arcwind_init_posthog'
        );
        add_settings_field('arcwind_init_personal_api_key', __('Personlig API-nyckel (phx_...)', 'arcwind-init'),
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
        add_settings_field('arcwind_init_capture_pageview', __('Registrera sidvisning vid laddning', 'arcwind-init'),
            [$this, 'render_checkbox_capture_pageview'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_autocapture', __('Autocapture (klick, formulär, m.m.)', 'arcwind-init'),
            [$this, 'render_checkbox_autocapture'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_defaults', __('SDK defaults', 'arcwind-init'),
            [$this, 'render_input_defaults'], 'arcwind_init_posthog', 'arcwind_init_frontend');
        add_settings_field('arcwind_init_person_profiles', __('Person profiles', 'arcwind-init'),
            [$this, 'render_select_person_profiles'], 'arcwind_init_posthog', 'arcwind_init_frontend');
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
        if (method_exists($this, 'render_feedback_enabled')) {
            add_settings_field('arcwind_init_feedback_enabled', __('Aktivera feedback', 'arcwind-init'),
                [$this, 'render_feedback_enabled'], 'arcwind_init_feedback_tab', 'arcwind_init_feedback');
            add_settings_field('arcwind_init_feedback_uuid', __('Feedback-UUID (t.ex. MM0H1A5A)', 'arcwind-init'),
                [$this, 'render_input_feedback_uuid'], 'arcwind_init_feedback_tab', 'arcwind_init_feedback');
        }

        add_settings_section(
            'arcwind_init_errors',
            __('Felspårning', 'arcwind-init'),
            function () {
                echo '<p>Skicka fel från dashboardens PostHog-frågor som händelser till PostHog med projekt-token.</p>';
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
        $out['defaults'] = in_array($defaults, self::posthog_defaults_dates(), true) ? $defaults : '';
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
        // The tab only selects page content; no action is performed here.
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'posthog'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!in_array($tab, ['posthog', 'feedback', 'oneuptime', 'diagnostics'], true)) $tab = 'posthog';
        $tabs = ['posthog' => 'PostHog', 'feedback' => 'Feedback', 'oneuptime' => 'OneUptime', 'diagnostics' => 'Diagnostik'];
        echo '<div class="wrap"><h1>ArcWind Init – Inställningar</h1><nav class="nav-tab-wrapper" aria-label="Integrationer">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(['page' => 'arcwind_init', 'tab' => $key], admin_url('options-general.php'));
            echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if (class_exists('ArcWind_Init_OneUptime')) ArcWind_Init_OneUptime::render_alert_notice();
        if ($tab === 'diagnostics') {
            if ($this->diagnostics) $this->diagnostics->render();
            else echo '<p>' . esc_html__('Diagnostics are temporarily unavailable.', 'arcwind-init') . '</p>';
            echo '</div>';
            return;
        }
        echo '<form method="post" action="options.php">';
        if ($tab === 'oneuptime') {
            settings_fields('arcwind_init_oneuptime_group');
            if (class_exists('ArcWind_Init_OneUptime')) ArcWind_Init_OneUptime::render_settings_table();
            else echo '<p>' . esc_html__('Service status settings are temporarily unavailable.', 'arcwind-init') . '</p>';
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
                $this->render_posthog_settings_table();
            }
        }
        submit_button(__('Spara ändringar', 'arcwind-init'));
        echo '</form></div>';
    }

    private function render_posthog_settings_table() {
        $settings = $this->options();
        $api_ready = !empty($settings['api_host']) && wp_parse_url($settings['api_host'], PHP_URL_SCHEME) === 'https';
        $project_ready = !empty($settings['project_id']);
        $token_ready = !empty($settings['public_api_key']);
        $ui_ready = empty($settings['ui_host']) || wp_parse_url($settings['ui_host'], PHP_URL_SCHEME) === 'https';
        $web_ready = $api_ready && $token_ready && $ui_ready;
        $dashboard_ready = $ui_ready && $project_ready && !empty($settings['personal_api_key']);
        $errors_ready = $api_ready && $token_ready;
        $states = [
            'arcwind_init_connection' => ($web_ready || $dashboard_ready || (!empty($settings['enable_error_tracking']) && $errors_ready)) ? 'active' :
                (!empty($settings['api_host']) || $project_ready || $token_ready || !empty($settings['ui_host']) || !empty($settings['personal_api_key']) ? 'error' : 'inactive'),
            'arcwind_init_frontend' => empty($settings['enable_tracking']) ? 'inactive' :
                ($web_ready ? 'active' : (!empty($settings['api_host']) || $token_ready ? 'error' : 'inactive')),
            'arcwind_init_main' => $dashboard_ready ? 'active' :
                ($project_ready || !empty($settings['personal_api_key']) ? 'error' : 'inactive'),
            'arcwind_init_errors' => empty($settings['enable_error_tracking']) ? 'inactive' :
                ($errors_ready ? 'active' : ($token_ready ? 'error' : 'inactive')),
        ];
        $icons = ['active' => ['yes-alt', 'Aktiv'], 'inactive' => ['minus', 'Av'], 'error' => ['warning', 'Behöver konfigureras']];
        $groups = [
            'arcwind_init_connection' => ['PostHog-anslutning', 'Välj region och ange adresserna för webbtrafik och gränssnitt, projekt-ID och publik projekt-token.', true, ''],
            'arcwind_init_frontend' => ['Webbspårning', 'Välj hur PostHog spårar besök och interaktioner samt hur SDK:n laddas på webbplatsen.', $api_ready && $token_ready, 'Fyll i API-värd och projekt-token ovan för att låsa upp webbspårning.'],
            'arcwind_init_main' => ['Dashboardstatistik', 'Visa statistik i WordPress-admin. Frågor till PostHog använder UI-värden och en personlig API-nyckel.', $project_ready, 'Fyll i projekt-ID ovan för att låsa upp dashboardstatistik.'],
            'arcwind_init_errors' => ['Felspårning', 'Skicka fel från dashboardens PostHog-frågor via API-värden med projekt-token.', $api_ready && $token_ready, 'Fyll i API-värd och projekt-token ovan för att låsa upp felspårning.'],
        ];
        echo '<style>#arcwind-posthog-settings tbody[hidden]{display:none}#arcwind-posthog-settings .button-link[disabled]{color:#8c8f94;cursor:not-allowed}#arcwind-posthog-settings th[colspan="2"]{padding-top:18px;border-top:1px solid #dcdcde}#arcwind-posthog-settings th[colspan="2"] .button-link{font-size:16px;font-weight:600;text-decoration:none}#arcwind-posthog-settings .arcwind-section-status{display:inline-flex;vertical-align:middle;margin-left:8px}#arcwind-posthog-settings .arcwind-section-status-active{color:#008a20}#arcwind-posthog-settings .arcwind-section-status-inactive{color:#646970}#arcwind-posthog-settings .arcwind-section-status-error{color:#b26200}</style>';
        echo '<p class="description">Ikonerna visar sparad status: grön bock = aktiv, grått streck = av, orange varning = behöver konfigureras.</p>';
        echo '<table class="form-table" role="presentation" id="arcwind-posthog-settings">';
        foreach ($groups as $section => $details) {
            $open = $section === 'arcwind_init_connection';
            $locked = !$details[2];
            $target = $section . '_fields';
            $state = $states[$section];
            $status_label = 'Sparad status: ' . $icons[$state][1];
            echo '<tbody><tr><th colspan="2" scope="colgroup"><button class="button-link" type="button" aria-controls="' . esc_attr($target) . '" aria-expanded="' . ($open ? 'true' : 'false') . '"' . ($locked ? ' disabled' : '') . '><span class="dashicons dashicons-arrow-' . ($open ? 'down' : 'right') . '-alt2" aria-hidden="true"></span> ' . esc_html($details[0]) . '</button><span class="arcwind-section-status arcwind-section-status-' . esc_attr($state) . '" role="img" aria-label="' . esc_attr($status_label) . '" title="' . esc_attr($status_label) . '"><span class="dashicons dashicons-' . esc_attr($icons[$state][0]) . '" aria-hidden="true"></span></span><p class="description" style="font-weight:normal">' . esc_html($details[1]) . ($details[3] !== '' ? ' <span id="' . esc_attr($section) . '_required"' . ($locked ? '' : ' hidden') . '>' . esc_html($details[3]) . '</span>' : '') . '</p></th></tr></tbody>';
            echo '<tbody id="' . esc_attr($target) . '"' . ($open ? '' : ' hidden') . '>';
            do_settings_fields('arcwind_init_posthog', $section);
            echo '</tbody>';
        }
        echo '</table>';
        echo '<script>(function(){var table=document.getElementById("arcwind-posthog-settings");if(!table)return;var api=document.getElementById("arcwind_init_api_host"),project=document.getElementById("arcwind_init_project_id"),token=document.getElementById("arcwind_init_public_api_key"),sections={arcwind_init_frontend_fields:function(){return !!(api.value.trim()&&token.value.trim())},arcwind_init_main_fields:function(){return !!project.value.trim()},arcwind_init_errors_fields:function(){return !!(api.value.trim()&&token.value.trim())}};function gate(){Object.keys(sections).forEach(function(target){var button=table.querySelector("button[aria-controls="+target+"]"),rows=document.getElementById(target),hint=document.getElementById(target.replace(/_fields$/,"_required")),ready=sections[target]();button.disabled=!ready;if(!ready){rows.hidden=true;button.setAttribute("aria-expanded","false");button.querySelector(".dashicons").className="dashicons dashicons-arrow-right-alt2"}hint.hidden=ready})}api.addEventListener("input",gate);project.addEventListener("input",gate);token.addEventListener("input",gate);table.addEventListener("click",function(event){var button=event.target.closest("button[aria-controls]");if(!button||!table.contains(button)||button.disabled)return;var rows=document.getElementById(button.getAttribute("aria-controls"));if(!rows)return;rows.hidden=!rows.hidden;button.setAttribute("aria-expanded",rows.hidden?"false":"true");var icon=button.querySelector(".dashicons");if(icon)icon.className="dashicons dashicons-arrow-"+(rows.hidden?"right":"down")+"-alt2"})})();</script>';
    }

    /* ===== Field renderers ===== */
    private static function posthog_defaults_dates() {
        return ['2025-05-24', '2025-11-30', '2026-01-30', '2026-05-30', '2026-06-25', '2026-08-29', '2026-08-30'];
    }

    public function render_input_project_id()        { $o = $this->options(); ?>
        <input id="arcwind_init_project_id" type="text" style="width:200px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[project_id]" value="<?php echo esc_attr($o['project_id'] ?? ''); ?>" placeholder="t.ex. 1234" />
        <p class="description">Behövs för statistikwidgeten i admin. Webbspårningen använder i stället projekt-token.</p><?php
    }
    public function render_input_personal_api_key()  { $o = $this->options(); ?>
        <?php if (!empty($o['personal_api_key'])): ?>
            <p class="description">En API-nyckel är sparad.</p>
            <button type="submit" class="button" name="<?php echo esc_attr(self::OPTION_KEY); ?>[personal_api_key_clear]" value="1">Ta bort API-nyckel och spara ändringar</button>
        <?php else: ?>
            <input type="password" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[personal_api_key]" value="" placeholder="phx_..." autocomplete="new-password" />
        <?php endif; ?><?php
    }
    public function render_checkbox_enable_tracking(){ $o = $this->options(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_tracking]" value="1" <?php checked(!empty($o['enable_tracking'])); ?> /> Aktivera PostHog-spårning på webbplatsen</label><?php
    }
    public function render_input_public_api_key()    { $o = $this->options(); ?>
        <input id="arcwind_init_public_api_key" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[public_api_key]" value="<?php echo esc_attr($o['public_api_key'] ?? ''); ?>" placeholder="phc_..." />
        <p class="description">Publik projekt-token för webbspårning och felhändelser. Den börjar normalt med <code>phc_</code>.</p><?php
    }
    public function render_select_region()           { $o = $this->options(); $region = $o['region'] ?? 'eu'; ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[region]">
            <option value="eu" <?php selected($region, 'eu'); ?>>EU</option>
            <option value="us" <?php selected($region, 'us'); ?>>US</option>
        </select><p class="description">Väljer direkta standardadresser för både <code>api_host</code> och <code>ui_host</code>. Egna adresser ändras inte när du byter region.</p><?php
    }
    public function render_input_api_host()          { $o = $this->options(); ?>
        <input id="arcwind_init_api_host" type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_host]" value="<?php echo esc_attr($o['api_host'] ?? ''); ?>" placeholder="https://eu.i.posthog.com" />
        <p class="description">Adress för SDK-filer och event från webbläsaren samt serverns felhändelser. Ange PostHogs API-värd eller din HTTPS reverse proxy, inklusive eventuell sökväg. Tomt använder vald regions direktadress. På äldre installationer behålls tidigare värd vid första sparningen.</p><?php
    }
    public function render_input_ui_host()           { $o = $this->options(); ?>
        <input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ui_host]" value="<?php echo esc_attr($o['ui_host'] ?? ''); ?>" placeholder="https://eu.posthog.com" />
        <p class="description">Adress till PostHogs gränssnitt, toolbar och privata Query API. Vid reverse proxy är detta normalt PostHogs riktiga domän. Tomt använder vald region.</p><?php
    }
    public function render_input_defaults()          { $o = $this->options(); ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[defaults]">
            <option value="" <?php selected($o['defaults'] ?? '', ''); ?>>SDK-standard (ingen vald profil)</option>
            <?php foreach (self::posthog_defaults_dates() as $date): ?>
                <option value="<?php echo esc_attr($date); ?>" <?php selected($o['defaults'] ?? '', $date); ?>><?php echo esc_html($date); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description">Väljer en daterad profil för PostHogs standardbeteenden. Nyare datum kan ändra SDK:ns förval; dina val för sidvisning och autocapture gäller fortfarande. SDK-standard utelämnar <code>defaults</code>.</p><?php
    }
    public function render_select_person_profiles()  { $o = $this->options(); $value = $o['person_profiles'] ?? ''; ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[person_profiles]">
            <option value="" <?php selected($value, ''); ?>>SDK-standard</option>
            <option value="identified_only" <?php selected($value, 'identified_only'); ?>>Bara identifierade</option>
            <option value="always" <?php selected($value, 'always'); ?>>Alltid</option>
        </select><p class="description"><code>identified_only</code> skapar personprofiler för identifierade besökare; övriga händelser är anonyma. <code>always</code> skapar profiler även för anonyma besökare och kan öka antalet debiterade händelser. SDK-standard använder PostHogs eget förval.</p><?php
    }
    public function render_checkbox_capture_pageview(){ $o = $this->options(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[capture_pageview]" value="1" <?php checked(!empty($o['capture_pageview'])); ?> /> Kör <code>capture_pageview</code> vid laddning</label>
        <p class="description">Skickar en <code>$pageview</code> när sidan laddas så att besök syns i statistiken. Avmarkera om sidvisningar registreras på annat sätt.</p><?php
    }
    public function render_checkbox_autocapture()    { $o = $this->options(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[autocapture]" value="1" <?php checked(!empty($o['autocapture'])); ?> /> Aktivera <code>autocapture</code></label>
        <p class="description">Låter PostHog registrera vanliga interaktioner automatiskt, till exempel klick och formulärhändelser. Avmarkera om du bara vill skicka sidvisningar och egna händelser.</p><?php
    }
    public function render_select_load_position()    { $o = $this->options(); $val = $o['load_position'] ?? 'head'; ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[load_position]">
            <option value="head"   <?php selected($val, 'head'); ?>>Head – tidigt</option>
            <option value="footer" <?php selected($val, 'footer'); ?>>Footer – senare</option>
        </select><p class="description">Väljer var PostHog laddas på webbplatsen och i admin. Head startar spårningen tidigt; Footer väntar tills sidans innehåll har laddats längre.</p><?php
    }
    public function render_checkbox_enable_error_tracking(){ $o = $this->options(); ?>
        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_error_tracking]" value="1" <?php checked(!empty($o['enable_error_tracking'])); ?> /> Skicka fel från PostHog-statistiken som händelser</label><?php
    }
    public function render_input_error_distinct_id() { $o = $this->options(); ?>
        <input type="text" style="width:260px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[error_distinct_id]" value="<?php echo esc_attr($o['error_distinct_id'] ?? 'arcwind-init:server'); ?>" placeholder="arcwind-init:server" /><?php
    }
}
