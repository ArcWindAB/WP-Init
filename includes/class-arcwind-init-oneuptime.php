<?php
/**
 * OneUptime integration for ArcWind Init.
 */
if (!defined('ABSPATH')) exit;

class ArcWind_Init_OneUptime {
    const KEY = 'arcwind_init_oneuptime';
    const CACHE = 'arcwind_init_oneuptime_incident';
    const HOOK = 'arcwind_init_oneuptime_heartbeat';

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('cron_schedules', [$this, 'add_minute_interval']);
        add_action('init', [$this, 'sync_cron']);
        add_action(self::HOOK, [$this, 'send_heartbeat']);
        add_action('wp_body_open', [$this, 'render_banner'], -100);
        add_action('admin_notices', [$this, 'render_admin_notice']);
        add_action('wp_head', [$this, 'banner_styles']);
    }

    public function options() {
        return wp_parse_args((array) get_option(self::KEY, []), [
            'enabled' => 0, 'status_url' => '', 'status_page_id' => '',
            'heartbeat_enabled' => 0, 'heartbeat_url' => '',
            'banner_enabled' => 1,
        ]);
    }

    public function register_settings() {
        register_setting('arcwind_init_oneuptime_group', self::KEY, [
            'type' => 'array', 'sanitize_callback' => [$this, 'sanitize'],
        ]);
        add_settings_section('arcwind_init_oneuptime_section', 'OneUptime',
            function () {
                echo '<p>Visa öppna incidenter från en publik OneUptime-statussida och övervaka WordPress med heartbeat.</p>';
                echo '<p><strong>Obs:</strong> WP-Cron körs när webbplatsen får trafik. För ett tillförlitligt minutintervall krävs en extern cron som anropar wp-cron.php varje minut.</p>';
            }, 'arcwind_init_oneuptime');
        foreach ([
            'enabled' => ['Aktivera OneUptime', 'checkbox'],
            'status_url' => ['Publik statussida (URL)', 'url'],
            'status_page_id' => ['Status Page ID (eller domän)', 'text'],
            'banner_enabled' => ['Visa incidentbanner', 'checkbox'],
            'heartbeat_enabled' => ['Aktivera heartbeat', 'checkbox'],
            'heartbeat_url' => ['Heartbeat-URL (hemlig)', 'password'],
        ] as $key => $definition) {
            add_settings_field('arcwind_oneuptime_' . $key, $definition[0],
                function () use ($key, $definition) {
                    $settings = $this->options();
                    $name = self::KEY . '[' . $key . ']';
                    if ($definition[1] === 'checkbox') {
                        echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
                        echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '>';
                    } else {
                        echo '<input class="regular-text" type="' . esc_attr($definition[1]) . '" name="' . esc_attr($name) . '" value="' . esc_attr($settings[$key]) . '" autocomplete="off">';
                    }
                }, 'arcwind_init_oneuptime', 'arcwind_init_oneuptime_section');
        }
    }

    public function sanitize($raw) {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (['enabled', 'banner_enabled', 'heartbeat_enabled'] as $key) {
            $out[$key] = empty($raw[$key]) ? 0 : 1;
        }
        $out['status_page_id'] = sanitize_text_field($raw['status_page_id'] ?? '');
        $out['status_page_id'] = preg_replace('/[^A-Za-z0-9._-]/', '', $out['status_page_id']);
        $out['status_url'] = esc_url_raw(trim($raw['status_url'] ?? ''));
        $out['heartbeat_url'] = esc_url_raw(trim($raw['heartbeat_url'] ?? ''));
        foreach (['status_url', 'heartbeat_url'] as $key) {
            if ($out[$key] && wp_parse_url($out[$key], PHP_URL_SCHEME) !== 'https') {
                add_settings_error(self::KEY, $key, 'Endast HTTPS-adresser tillåts.', 'error');
                $out[$key] = '';
            }
        }
        if ($out['heartbeat_url'] && !preg_match('~/heartbeat/[^/]+/?$~', $out['heartbeat_url'])) {
            add_settings_error(self::KEY, 'heartbeat_url', 'Heartbeat-adressen måste innehålla /heartbeat/SECRET.', 'error');
            $out['heartbeat_url'] = '';
        }
        delete_transient(self::CACHE);
        return $out;
    }

    public function add_minute_interval($schedules) {
        $schedules['arcwind_every_minute'] = ['interval' => 60, 'display' => 'Every minute'];
        return $schedules;
    }

    public function sync_cron() {
        $o = $this->options();
        $active = $o['enabled'] && $o['heartbeat_enabled'] && $o['heartbeat_url'];
        $event = wp_get_scheduled_event(self::HOOK);
        if (!$active && $event) wp_clear_scheduled_hook(self::HOOK);
        if ($active && (!$event || $event->schedule !== 'arcwind_every_minute')) {
            wp_clear_scheduled_hook(self::HOOK);
            wp_schedule_event(time() + 60, 'arcwind_every_minute', self::HOOK);
        }
    }

    public function send_heartbeat() {
        $o = $this->options();
        if (!$o['enabled'] || !$o['heartbeat_enabled'] || !$o['heartbeat_url']) return;
        wp_remote_get($o['heartbeat_url'], [
            'timeout' => 8, 'redirection' => 0, 'sslverify' => true,
        ]);
    }

    private function active_incident() {
        $o = $this->options();
        if (!$o['enabled'] || !$o['banner_enabled'] || !$o['status_page_id'] || !$o['status_url']) return null;
        $cached = get_transient(self::CACHE);
        if ($cached !== false) return $cached === 'none' ? null : $cached;
        $base = untrailingslashit($o['status_url']);
        $parsed = wp_parse_url($base);
        if (!$parsed || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])) return null;
        $api_url = 'https://' . $parsed['host'] . '/status-page-api/incidents/' . rawurlencode($o['status_page_id']);
        $response = wp_safe_remote_get($api_url, ['timeout' => 5, 'redirection' => 0]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || !isset($json['incidents']) || !is_array($json['incidents'])) return null;
        foreach ($json['incidents'] as $incident) {
            $state = $incident['currentIncidentState'] ?? [];
            if (!empty($state['isResolvedState']) || !empty($state['isClosedState'])) continue;
            // OneUptime may also return historical incidents. Only show active states.
            $name = strtolower((string) ($state['name'] ?? ''));
            if (!$state || preg_match('/resolved|closed|åtgärdad|avslutad/', $name)) continue;
            $item = ['title' => sanitize_text_field($incident['title'] ?? 'Pågående driftstörning'), 'url' => $o['status_url']];
            set_transient(self::CACHE, $item, MINUTE_IN_SECONDS);
            return $item;
        }
        set_transient(self::CACHE, 'none', MINUTE_IN_SECONDS);
        return null;
    }

    public function banner_styles() {
        $o = $this->options();
        if (!$o['enabled'] || !$o['banner_enabled']) return;
        echo '<style id="arcwind-oneuptime-css">#arcwind-oneuptime-banner{position:relative;z-index:999999;background:#8b211c;color:#fff;padding:12px 20px;text-align:center;font-size:14px;line-height:1.5}#arcwind-oneuptime-banner a{color:#fff;text-decoration:underline}body.admin-bar #wpadminbar{top:44px}body.admin-bar{padding-top:0!important}@media(max-width:782px){body.admin-bar #wpadminbar{top:60px}} </style>';
    }

    public function render_banner() {
        $item = $this->active_incident();
        if (!$item) return;
        echo '<div id="arcwind-oneuptime-banner" role="status" aria-live="polite"><strong>Driftinformation:</strong> ' . esc_html($item['title']) . ' <a href="' . esc_url($item['url']) . '">Läs mer på statussidan</a></div>';
    }

    public function render_admin_notice() {
        $item = $this->active_incident();
        if (!$item) return;
        echo '<div class="notice notice-warning"><p><strong>Driftinformation:</strong> ' . esc_html($item['title']) . ' <a href="' . esc_url($item['url']) . '">Läs mer</a></p></div>';
    }
}
