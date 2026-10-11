<?php
if (!defined('ABSPATH')) exit;

trait ArcWind_Init_PostHog_Trait {
    /* =========================
     * Minimal admin CSS
     * ========================= */
    public function enqueue_admin_assets($hook_suffix) {
        if ($hook_suffix === 'settings_page_arcwind_init') {
            // Avoid the browser's aborted navigation transition on this settings page.
            wp_dequeue_style('wp-view-transitions-admin');
        }
        if ($hook_suffix !== 'index.php' && $hook_suffix !== 'settings_page_arcwind_init') return;

        $css = "
        .arcwind-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:8px}
        .arcwind-card{background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:12px}
        .arcwind-card h4{margin:0 0 6px 0;font-size:14px;color:#1d2327}
        .arcwind-value{font-size:22px;font-weight:700}
        .arcwind-subtle{font-size:12px;color:#646970}
        ";
        wp_register_style('arcwind_init_inline', false, [], self::get_version());
        wp_enqueue_style('arcwind_init_inline');
        wp_add_inline_style('arcwind_init_inline', $css);
    }

    /* =========================
     * Always-on PostHog snippet (front + admin) using inline stub
     * ========================= */
    public function maybe_print_posthog_snippet_universal() {
        $opts = $this->options();
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
        $region = ($opts['region'] ?? 'eu') === 'us' ? 'us' : 'eu';
        $host = array_key_exists('api_host', $opts)
            ? (($opts['api_host'] ?? '') ?: 'https://' . $region . '.i.posthog.com')
            : ($opts['host'] ?? 'https://tel.arc.vin/_p');
        $host = rtrim($host, '/');
        $public_api_key  = $opts['public_api_key'] ?? '';
        $capture_pageview= !empty($opts['capture_pageview']);
        $autocapture     = !empty($opts['autocapture']);
        if (empty($host) || empty($public_api_key) || wp_parse_url($host, PHP_URL_SCHEME) !== 'https') return;
        $ui_host = ($opts['ui_host'] ?? '') ?: 'https://' . $region . '.posthog.com';
        if (wp_parse_url($ui_host, PHP_URL_SCHEME) !== 'https') return;
        $config = [
            'api_host' => $host,
            'ui_host' => $ui_host,
            'capture_pageview' => $capture_pageview,
            'autocapture' => $autocapture,
        ];
        if (!empty($opts['defaults'])) $config['defaults'] = $opts['defaults'];
        if (in_array($opts['person_profiles'] ?? '', ['identified_only', 'always'], true)) {
            $config['person_profiles'] = $opts['person_profiles'];
        }

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
        <!-- PostHog tracking added by ArcWind Init. -->
        <script>
!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
posthog.init(<?php echo wp_json_encode($public_api_key); ?>, <?php echo wp_json_encode($config); ?>);
<?php echo $register_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values are JSON encoded for JavaScript. ?>
<?php echo $identify_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values are JSON encoded for JavaScript. ?>
        </script>
        <!-- End PostHog tracking. -->
        <?php
    }

    public function enqueue_tracking_bridge() {
        $opts = $this->options();

        if (empty($opts['enable_tracking']) || empty($opts['public_api_key'])) {
            return;
        }

        wp_register_script(
            'arcwind-init-tracker',
            plugins_url('assets/js/arcwind-init-tracker.js', dirname(__DIR__) . '/arcwind-init.php'),
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
