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
        $options = wp_parse_args((array) get_option(self::KEY, []), [
            'enabled' => 0, 'status_url' => '', 'status_page_id' => '',
            'heartbeat_enabled' => 0, 'heartbeat_url' => '',
            'banner_enabled' => 1, 'show_incidents' => 1,
            'show_announcements' => 1, 'show_maintenance' => 1,
        ]);
        foreach (['status_url', 'status_page_id', 'heartbeat_url'] as $key) {
            $options[$key] = self::api_text($options[$key]);
        }
        return $options;
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
            'banner_enabled' => ['Visa informationsbanner', 'checkbox'],
            'show_incidents' => ['Visa incidenter', 'checkbox'],
            'show_announcements' => ['Visa meddelanden (Announcements)', 'checkbox'],
            'show_maintenance' => ['Visa planerade underhåll', 'checkbox'],
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
        foreach (['enabled', 'banner_enabled', 'show_incidents', 'show_announcements', 'show_maintenance', 'heartbeat_enabled'] as $key) {
            $out[$key] = empty($raw[$key]) ? 0 : 1;
        }
        $out['status_page_id'] = sanitize_text_field(self::api_text($raw['status_page_id'] ?? ''));
        $out['status_page_id'] = preg_replace('/[^A-Za-z0-9._-]/', '', $out['status_page_id']);
        $out['status_url'] = esc_url_raw(self::api_text($raw['status_url'] ?? ''));
        $out['heartbeat_url'] = esc_url_raw(self::api_text($raw['heartbeat_url'] ?? ''));
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

    /**
     * Convert OneUptime API date values into Unix timestamps safely.
     * The API may return ISO strings, numeric epoch values or wrapped objects.
     */
    public static function parse_api_timestamp($value) {
        return self::timestamp_value($value, 0);
    }

    private static function timestamp_value($value, $depth) {
        if ($depth > 6) return null;
        if (is_array($value)) {
            foreach (['value', 'date', 'timestamp', '$date'] as $key) {
                if (array_key_exists($key, $value)) return self::timestamp_value($value[$key], $depth + 1);
            }
            return null;
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) return null;
            return (int) (abs($value) >= 100000000000 ? $value / 1000 : $value);
        }
        if (!is_string($value) || trim($value) === '') return null;
        if (is_numeric($value)) return self::timestamp_value((float) $value, $depth + 1);
        try {
            $timestamp = strtotime($value);
            return $timestamp === false ? null : $timestamp;
        } catch (Throwable $error) {
            return null;
        }
    }

    public static function api_text($value, $fallback = '') {
        return is_string($value) ? trim($value) : $fallback;
    }

    public static function api_id($value, $depth = 0) {
        if ($depth > 6) return '';
        if (is_array($value)) {
            foreach (['value', '_id', 'id'] as $key) {
                if (array_key_exists($key, $value)) return self::api_id($value[$key], $depth + 1);
            }
            return '';
        }
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    public static function api_records($data, $key) {
        if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) return [];
        return array_values(array_filter($data[$key], 'is_array'));
    }

    public static function api_messages($value) {
        if (!is_array($value)) return [];
        $messages = [];
        foreach ($value as $item) {
            if (!is_array($item)) continue;
            $type = self::api_text($item['type'] ?? null);
            $title = self::api_text($item['title'] ?? null);
            $url = self::api_text($item['url'] ?? null);
            if (!in_array($type, ['incident', 'announcement', 'maintenance'], true) || $title === '' || $url === '') continue;
            $messages[] = ['type' => $type, 'title' => $title, 'url' => $url];
        }
        return $messages;
    }

    public static function parse_api_json($body) {
        if (!is_string($body) || trim($body) === '') return new WP_Error('empty_response', 'OneUptime skickade ett tomt svar.');
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return new WP_Error('invalid_response', 'OneUptime skickade ett oväntat svar.');
        }
        return $data;
    }

    public static function request_api($url, $endpoint, $method = 'GET', $timeout = 8) {
        $args = ['timeout' => $timeout, 'redirection' => 0];
        if ($method === 'POST') {
            $args['headers'] = ['Content-Type' => 'application/json'];
            $args['body'] = '{}';
            $response = wp_safe_remote_post($url, $args);
        } else {
            $response = wp_safe_remote_get($url, $args);
        }
        if (is_wp_error($response) || !is_array($response)) {
            self::api_diagnostic($endpoint, 'request failed');
            return new WP_Error('api_unavailable', 'OneUptime kunde inte nås.');
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            self::api_diagnostic($endpoint, 'HTTP ' . (is_int($code) ? $code : 'unknown'));
            return new WP_Error('api_error', 'OneUptime kunde inte läsas.');
        }
        $data = self::parse_api_json(wp_remote_retrieve_body($response));
        if (is_wp_error($data)) self::api_diagnostic($endpoint, $data->get_error_code());
        return $data;
    }

    public static function api_diagnostic($endpoint, $reason) {
        // Never log response bodies, URLs, or heartbeat secrets.
        error_log('ArcWind Init OneUptime ' . $endpoint . ': ' . $reason);
    }

    private function active_messages() {
        $o = $this->options();
        if (!$o['enabled'] || !$o['banner_enabled'] || !$o['status_page_id'] || !$o['status_url']) return [];
        $cached = get_transient(self::CACHE);
        if ($cached !== false && is_array($cached)) return self::api_messages($cached);
        $parsed = wp_parse_url($o['status_url']);
        if (!$parsed || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])) return [];
        $base = 'https://' . $parsed['host'];
        if (!empty($parsed['port'])) $base .= ':' . (int) $parsed['port'];
        $api = $base . '/status-page-api/';
        $id = rawurlencode($o['status_page_id']);
        $messages = [];
        $resources = [
            'incidents' => ['enabled' => $o['show_incidents'], 'key' => 'incidents', 'type' => 'incident'],
            'announcements' => ['enabled' => $o['show_announcements'], 'key' => 'announcements', 'type' => 'announcement'],
            'scheduled-maintenance-events' => ['enabled' => $o['show_maintenance'], 'key' => 'scheduledMaintenanceEvents', 'type' => 'maintenance'],
        ];
        foreach ($resources as $endpoint => $config) {
            if (!$config['enabled']) continue;
            $json = self::request_api($api . $endpoint . '/' . $id, $endpoint, 'GET', 5);
            if (is_wp_error($json)) continue;
            if (!isset($json[$config['key']]) || !is_array($json[$config['key']])) {
                self::api_diagnostic($endpoint, 'missing records');
                continue;
            }
            $records = self::api_records($json, $config['key']);
            if (count($records) !== count($json[$config['key']])) self::api_diagnostic($endpoint, 'invalid records');
            foreach ($records as $record) {
                if ($config['type'] === 'incident') {
                    $state = $record['currentIncidentState'] ?? [];
                    if (!is_array($state) || !$state || !empty($state['isResolvedState']) || !empty($state['isClosedState'])) continue;
                    if (preg_match('/resolved|closed|åtgärdad|avslutad/i', self::api_text($state['name'] ?? ''))) continue;
                } elseif ($config['type'] === 'announcement') {
                    $show = $record['showAnnouncementAt'] ?? null;
                    $show_at = self::parse_api_timestamp($show);
                    if ($show !== null && $show_at === null) {
                        self::api_diagnostic($endpoint, 'invalid date');
                        continue;
                    }
                    if ($show_at !== null && $show_at > time()) continue;
                } else {
                    $end = $record['endsAt'] ?? $record['scheduledEndAt'] ?? null;
                    $end_at = self::parse_api_timestamp($end);
                    if ($end !== null && $end_at === null) {
                        self::api_diagnostic($endpoint, 'invalid date');
                        continue;
                    }
                    if ($end_at !== null && $end_at < time()) continue;
                    $state = $record['currentScheduledMaintenanceState'] ?? [];
                    if (is_array($state) && (!empty($state['isCompletedState']) || !empty($state['isCanceledState']))) continue;
                }
                $title = sanitize_text_field(self::api_text($record['title'] ?? ''));
                if (!$title) {
                    self::api_diagnostic($endpoint, 'invalid title');
                    continue;
                }
                $messages[] = ['type' => $config['type'], 'title' => $title, 'url' => $o['status_url']];
                if (count($messages) >= 10) break 2;
            }
        }
        set_transient(self::CACHE, $messages, MINUTE_IN_SECONDS);
        return $messages;
    }

    public function banner_styles() {
        $o = $this->options();
        if (!$o['enabled'] || !$o['banner_enabled']) return;
        echo '<style id="arcwind-oneuptime-css">#arcwind-oneuptime-banner{position:relative;z-index:999999;font-size:14px;line-height:1.5}#arcwind-oneuptime-banner .arcwind-oneuptime-message{padding:10px 20px;text-align:center;background:#8b211c;color:#fff}#arcwind-oneuptime-banner .arcwind-oneuptime-message.announcement{background:#195b88}#arcwind-oneuptime-banner .arcwind-oneuptime-message.maintenance{background:#f4c75b;color:#181818}#arcwind-oneuptime-banner a{color:inherit;text-decoration:underline}</style>';
    }

    public function render_banner() {
        $items = $this->active_messages();
        if (!$items) return;
        echo '<div id="arcwind-oneuptime-banner" role="region" aria-label="Driftinformation">';
        foreach ($items as $item) {
            $labels = ['incident' => 'Driftstörning', 'announcement' => 'Information', 'maintenance' => 'Planerat underhåll'];
            echo '<div class="arcwind-oneuptime-message ' . esc_attr($item['type']) . '"><strong>' . esc_html($labels[$item['type']]) . ':</strong> ' . esc_html($item['title']) . ' <a href="' . esc_url($item['url']) . '">Läs mer</a></div>';
        }
        echo '</div>';
        echo '<style>body.admin-bar #wpadminbar{top:44px}@media(max-width:782px){body.admin-bar #wpadminbar{top:60px}}</style>';
    }

    public function render_admin_notice() {
        $items = $this->active_messages();
        foreach ($items as $item) {
            echo '<div class="notice notice-info"><p><strong>' . esc_html(ucfirst($item['type'])) . ':</strong> ' . esc_html($item['title']) . ' <a href="' . esc_url($item['url']) . '">Läs mer</a></p></div>';
        }
    }
}
