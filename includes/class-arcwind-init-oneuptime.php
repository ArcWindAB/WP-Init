<?php
/**
 * OneUptime integration for ArcWind Init.
 */
if (!defined('ABSPATH')) exit;

class ArcWind_Init_OneUptime {
    const KEY = 'arcwind_init_oneuptime';
    const CACHE = 'arcwind_init_oneuptime_incident';
    const ALERT_CACHE = 'arcwind_init_oneuptime_alerts';
    const HOOK = 'arcwind_init_oneuptime_heartbeat';
    const HEARTBEAT_HEALTH = 'arcwind_init_oneuptime_heartbeat_health';

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('cron_schedules', [$this, 'add_minute_interval']);
        add_action('init', [$this, 'sync_cron']);
        add_action(self::HOOK, [$this, 'send_heartbeat']);
        add_action('wp_body_open', [$this, 'render_banner'], -100);
        add_action('admin_notices', [$this, 'render_admin_notice']);
        add_action('wp_head', [$this, 'banner_styles']);
    }

    public static function options() {
        $saved = (array) get_option(self::KEY, []);
        $options = wp_parse_args($saved, [
            'enabled' => 0, 'host' => '', 'status_url' => '', 'status_page_id' => '',
            'heartbeat_enabled' => 0, 'heartbeat_secret' => '', 'heartbeat_url' => '',
            'alerts_api_key' => '', 'alerts_admin' => 0,
            'banner_enabled' => 1, 'banner_public' => 1, 'banner_admin' => 1,
            'show_incidents' => 1, 'show_announcements' => 1, 'show_maintenance' => 1,
        ]);
        foreach (['host', 'status_url', 'status_page_id', 'heartbeat_secret', 'heartbeat_url', 'alerts_api_key'] as $key) {
            $options[$key] = self::api_text($options[$key]);
        }
        if (!$options['host'] && $options['status_url']) $options['host'] = self::host_from_url($options['status_url']);
        if (!$options['heartbeat_secret'] && $options['heartbeat_url']) {
            $options['heartbeat_secret'] = self::secret_from_url($options['heartbeat_url']);
        }
        if ($options['host'] && $options['status_page_id']) {
            $options['status_url'] = $options['host'] . '/status-page/' . rawurlencode($options['status_page_id']);
        }
        if ($options['host'] && $options['heartbeat_secret'] && (array_key_exists('host', $saved) || !$options['heartbeat_url'])) {
            $options['heartbeat_url'] = $options['host'] . '/heartbeat/' . rawurlencode($options['heartbeat_secret']);
        }
        foreach (['public', 'admin'] as $place) {
            foreach (['incidents', 'announcements', 'maintenance'] as $type) {
                $key = $place . '_' . $type;
                $options[$key] = array_key_exists($key, $saved) ? (int) !empty($saved[$key]) : (int) !empty($options['show_' . $type]);
            }
        }
        if (empty($options['banner_enabled'])) $options['banner_public'] = $options['banner_admin'] = 0;
        return $options;
    }

    private static function host_from_url($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return '';
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path !== '' && !preg_match('~^/(?:status-page|heartbeat)/[^/]+$~', $path)) return '';
        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private static function secret_from_url($url) {
        $parts = wp_parse_url($url);
        $path = is_array($parts) ? rtrim($parts['path'] ?? '', '/') : '';
        return preg_match('~^/heartbeat/([A-Za-z0-9._-]+)$~', $path, $matches) ? $matches[1] : '';
    }

    public function register_settings() {
        register_setting('arcwind_init_oneuptime_group', self::KEY, [
            'type' => 'array', 'sanitize_callback' => [$this, 'sanitize'],
        ]);
        add_settings_section('arcwind_oneuptime_connection', 'Statussida', function () {
            echo '<p>Ange OneUptime-värden och statussidans ID. Statussidans adress skapas automatiskt.</p>';
        }, 'arcwind_init_oneuptime');
        add_settings_section('arcwind_oneuptime_information', 'Driftinformation', function () {
            echo '<p>Välj vilka aktuella händelser som ska visas på webbplatsen och i WordPress-admin.</p>';
        }, 'arcwind_init_oneuptime');
        add_settings_section('arcwind_oneuptime_heartbeat', 'Heartbeat', function () {
            echo '<p>Heartbeat är en återkommande signal från webbplatsen till OneUptime. Skapa först en <strong>Incoming Request-monitor</strong> i OneUptime och ange dess heartbeat-ID nedan.</p>';
            echo '<p>Signalen skickas med WP-Cron ungefär varje minut när webbplatsen får trafik. Använd extern cron för ett tillförlitligt minutintervall.</p>';
        }, 'arcwind_init_oneuptime');
        add_settings_section('arcwind_oneuptime_alerts', 'Alerts', function () {
            echo '<p>Visa olösta alerts för monitorerna på statussidan i WordPress-admin.</p>';
        }, 'arcwind_init_oneuptime');
        $fields = [
            'host' => ['OneUptime-värd', 'url', 'arcwind_oneuptime_connection'],
            'status_page_id' => ['Statussidans ID', 'text', 'arcwind_oneuptime_connection'],
            'banner_public' => ['Visa driftinformation på webbplatsen', 'checkbox', 'arcwind_oneuptime_information'],
            'public_incidents' => ['Visa incidenter på webbplatsen', 'checkbox', 'arcwind_oneuptime_information'],
            'public_announcements' => ['Visa meddelanden på webbplatsen', 'checkbox', 'arcwind_oneuptime_information'],
            'public_maintenance' => ['Visa planerade underhåll på webbplatsen', 'checkbox', 'arcwind_oneuptime_information'],
            'banner_admin' => ['Visa driftinformation i admin', 'checkbox', 'arcwind_oneuptime_information'],
            'admin_incidents' => ['Visa incidenter i admin', 'checkbox', 'arcwind_oneuptime_information'],
            'admin_announcements' => ['Visa meddelanden i admin', 'checkbox', 'arcwind_oneuptime_information'],
            'admin_maintenance' => ['Visa planerade underhåll i admin', 'checkbox', 'arcwind_oneuptime_information'],
            'heartbeat_enabled' => ['Skicka heartbeat', 'checkbox', 'arcwind_oneuptime_heartbeat'],
            'heartbeat_secret' => ['Heartbeat-ID', 'text', 'arcwind_oneuptime_heartbeat'],
            'alerts_api_key' => ['API-nyckel för alerts', 'password', 'arcwind_oneuptime_alerts'],
            'alerts_admin' => ['Visa alerts i admin', 'checkbox', 'arcwind_oneuptime_alerts'],
        ];
        foreach ($fields as $key => $definition) {
            add_settings_field('arcwind_oneuptime_' . $key, $definition[0], function () use ($key, $definition) {
                $settings = $this->options();
                $name = self::KEY . '[' . $key . ']';
                if ($definition[1] === 'checkbox') {
                    echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
                    echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '>';
                    return;
                }
                if ($key === 'alerts_api_key') {
                    if ($settings['alerts_api_key'] !== '') {
                        echo '<p class="description">En API-nyckel är sparad.</p>';
                        echo '<button type="submit" class="button" name="' . esc_attr(self::KEY) . '[alerts_api_key_clear]" value="1">Ta bort API-nyckel och spara ändringar</button>';
                    } else {
                        echo '<input id="arcwind_oneuptime_alerts_api_key" class="regular-text" type="password" name="' . esc_attr($name) . '" value="" autocomplete="new-password">';
                        echo '<p class="description">Nyckeln behöver läsbehörighet för Alert och Alert State.</p>';
                    }
                    return;
                }
                $id = 'arcwind_oneuptime_' . $key;
                echo '<input id="' . esc_attr($id) . '" class="regular-text" type="' . esc_attr($definition[1]) . '" name="' . esc_attr($name) . '" value="' . esc_attr($settings[$key]) . '" autocomplete="off"' . ($key === 'host' ? ' placeholder="https://oneuptime.app.arc.vin/"' : '') . '>';
                if ($key === 'host') echo '<p class="description">Basadressen till din OneUptime-installation, med https://.</p>';
                if ($key === 'status_page_id' && $settings['status_url']) echo '<p class="description">Statussida: <a href="' . esc_url($settings['status_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($settings['status_url']) . '</a></p>';
                if ($key === 'heartbeat_secret') {
                    echo '<p class="description">Du kan klistra in hela heartbeat-adressen; adressen kortas automatiskt till ID:t i fältet.</p>';
                    if ($settings['heartbeat_url']) echo '<p class="description">Heartbeat-adress: <code>' . esc_html($settings['heartbeat_url']) . '</code></p>';
                    echo '<script>(function(){var input=document.getElementById("arcwind_oneuptime_heartbeat_secret"),host=document.getElementById("arcwind_oneuptime_host");function normalize(){var v=input.value.trim(),h=host.value.trim().replace(/\/+$/,"");if(h&&v.indexOf(h+"/heartbeat/")===0)input.value=v.slice((h+"/heartbeat/").length).replace(/\/+$/,"")}input.addEventListener("input",normalize);host.addEventListener("input",normalize)})();</script>';
                }
            }, 'arcwind_init_oneuptime', $definition[2]);
        }
    }

    public static function render_settings_table() {
        $settings = self::options();
        $host_ready = $settings['host'] !== '';
        $status_ready = $settings['host'] !== '' && $settings['status_page_id'] !== '';
        $groups = [
            'arcwind_oneuptime_connection' => ['Statussida', 'Ange OneUptime-värd och statussidans ID. Adressen skapas automatiskt.'],
            'arcwind_oneuptime_information' => ['Driftinformation', 'Välj vilka händelser som visas på webbplatsen och i WordPress-admin.'],
            'arcwind_oneuptime_heartbeat' => ['Heartbeat', 'En heartbeat är en återkommande signal till OneUptime. Skapa en Incoming Request-monitor och ange dess heartbeat-ID. WP-Cron skickar signalen ungefär varje minut när webbplatsen får trafik.'],
            'arcwind_oneuptime_alerts' => ['Alerts', 'Visa olösta alerts från monitorerna på statussidan i WordPress-admin. API-nyckeln behöver läsbehörighet för Alert och Alert State.'],
        ];
        echo '<style>#arcwind-oneuptime-settings tbody[hidden]{display:none}#arcwind-oneuptime-settings .button-link[disabled]{color:#8c8f94;cursor:not-allowed}#arcwind-oneuptime-settings th[colspan="2"]{padding-top:18px;border-top:1px solid #dcdcde}#arcwind-oneuptime-settings th[colspan="2"] .button-link{font-size:16px;font-weight:600;text-decoration:none}</style>';
        echo '<table class="form-table" role="presentation" id="arcwind-oneuptime-settings">';
        foreach ($groups as $section => $details) {
            $open = $section === 'arcwind_oneuptime_connection';
            $locked = ($section === 'arcwind_oneuptime_heartbeat' && !$host_ready) ||
                (in_array($section, ['arcwind_oneuptime_information', 'arcwind_oneuptime_alerts'], true) && !$status_ready);
            $target = $section . '_fields';
            echo '<tbody><tr><th colspan="2" scope="colgroup"><button class="button-link" type="button" aria-controls="' . esc_attr($target) . '" aria-expanded="' . ($open ? 'true' : 'false') . '"' . ($locked ? ' disabled' : '') . '><span class="dashicons dashicons-arrow-' . ($open ? 'down' : 'right') . '-alt2" aria-hidden="true"></span> ' . esc_html($details[0]) . '</button><p class="description" style="font-weight:normal">' . esc_html($details[1]) . ($section === 'arcwind_oneuptime_information' ? ' <span id="arcwind-oneuptime-status-required"' . ($status_ready ? ' hidden' : '') . '>Fyll i värd och statussidans ID för att låsa upp valen.</span>' : '') . ($section === 'arcwind_oneuptime_heartbeat' ? ' <span id="arcwind-oneuptime-host-required"' . ($host_ready ? ' hidden' : '') . '>Fyll i OneUptime-värd för att låsa upp heartbeat.</span>' : '') . '</p></th></tr></tbody>';
            echo '<tbody id="' . esc_attr($target) . '"' . ($open ? '' : ' hidden') . '>';
            do_settings_fields('arcwind_init_oneuptime', $section);
            echo '</tbody>';
        }
        echo '</table>';
        echo '<script>(function(){var table=document.getElementById("arcwind-oneuptime-settings");if(!table)return;var info=table.querySelector("button[aria-controls=arcwind_oneuptime_information_fields]"),body=document.getElementById("arcwind_oneuptime_information_fields"),alerts=table.querySelector("button[aria-controls=arcwind_oneuptime_alerts_fields]"),alertRows=document.getElementById("arcwind_oneuptime_alerts_fields"),heartbeat=table.querySelector("button[aria-controls=arcwind_oneuptime_heartbeat_fields]"),heartbeatRows=document.getElementById("arcwind_oneuptime_heartbeat_fields"),hint=document.getElementById("arcwind-oneuptime-status-required"),heartbeatHint=document.getElementById("arcwind-oneuptime-host-required"),host=document.getElementById("arcwind_oneuptime_host"),id=document.getElementById("arcwind_oneuptime_status_page_id");function lock(button,rows,ready){button.disabled=!ready;if(!ready){rows.hidden=true;button.setAttribute("aria-expanded","false");button.querySelector(".dashicons").className="dashicons dashicons-arrow-right-alt2"}}function gate(){var hostReady=!!host.value.trim(),statusReady=!!(hostReady&&id.value.trim());lock(info,body,statusReady);lock(alerts,alertRows,statusReady);lock(heartbeat,heartbeatRows,hostReady);hint.hidden=statusReady;heartbeatHint.hidden=hostReady}host.addEventListener("input",gate);id.addEventListener("input",gate);table.addEventListener("click",function(event){var button=event.target.closest("button[aria-controls]");if(!button||!table.contains(button)||button.disabled)return;var rows=document.getElementById(button.getAttribute("aria-controls"));if(!rows)return;rows.hidden=!rows.hidden;button.setAttribute("aria-expanded",rows.hidden?"false":"true");var icon=button.querySelector(".dashicons");if(icon)icon.className="dashicons dashicons-arrow-"+(rows.hidden?"right":"down")+"-alt2"})})();</script>';
    }

    public function sanitize($raw) {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (['banner_public', 'banner_admin', 'public_incidents', 'public_announcements', 'public_maintenance',
            'admin_incidents', 'admin_announcements', 'admin_maintenance', 'heartbeat_enabled', 'alerts_admin'] as $key) {
            $out[$key] = empty($raw[$key]) ? 0 : 1;
        }
        $out['banner_enabled'] = (int) ($out['banner_public'] || $out['banner_admin']);
        foreach (['incidents', 'announcements', 'maintenance'] as $type) $out['show_' . $type] = (int) ($out['public_' . $type] || $out['admin_' . $type]);
        $out['host'] = rtrim(esc_url_raw(self::api_text($raw['host'] ?? '')), '/');
        if ($out['host'] && self::host_from_url($out['host']) !== $out['host']) {
            add_settings_error(self::KEY, 'host', 'Ange en giltig HTTPS-värd utan sökväg.', 'error');
            $out['host'] = '';
        }
        $out['status_page_id'] = sanitize_text_field(self::api_text($raw['status_page_id'] ?? ''));
        $out['status_page_id'] = preg_replace('/[^A-Za-z0-9._-]/', '', $out['status_page_id']);
        $out['status_url'] = $out['host'] && $out['status_page_id'] ? $out['host'] . '/status-page/' . rawurlencode($out['status_page_id']) : '';
        $secret = self::api_text($raw['heartbeat_secret'] ?? '');
        if (strpos($secret, '://') !== false) {
            $prefix = $out['host'] . '/heartbeat/';
            if (!$out['host'] || strpos($secret, $prefix) !== 0) {
                add_settings_error(self::KEY, 'heartbeat_secret', 'Heartbeat-adressen måste höra till den angivna värden.', 'error');
                $secret = '';
            } else $secret = substr($secret, strlen($prefix));
        }
        $secret = rtrim($secret, '/');
        if ($secret !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $secret)) {
            add_settings_error(self::KEY, 'heartbeat_secret', 'Ange ett giltigt heartbeat-ID.', 'error');
            $secret = '';
        }
        $out['heartbeat_secret'] = $secret;
        $out['heartbeat_url'] = $out['host'] && $secret ? $out['host'] . '/heartbeat/' . rawurlencode($secret) : '';
        $out['enabled'] = (int) (bool) $out['host'];
        $existing = self::options();
        $new_key = self::api_text($raw['alerts_api_key'] ?? '');
        if ($new_key !== '' && preg_match('/[\x00-\x1f\x7f]/', $new_key)) {
            add_settings_error(self::KEY, 'alerts_api_key', 'API-nyckeln innehåller ogiltiga tecken.', 'error');
            $new_key = '';
        }
        $out['alerts_api_key'] = !empty($raw['alerts_api_key_clear']) ? '' : ($new_key !== '' ? $new_key : $existing['alerts_api_key']);
        delete_transient(self::CACHE);
        delete_transient(self::ALERT_CACHE);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::CACHE);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::LAST_GOOD);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::FAILURE_CACHE);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::HISTORY_CACHE);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::HISTORY_LAST_GOOD);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::HISTORY_FAILURE);
        return $out;
    }

    public function add_minute_interval($schedules) {
        $schedules['arcwind_every_minute'] = ['interval' => 60, 'display' => 'Every minute'];
        return $schedules;
    }

    public static function activate() {
        wp_clear_scheduled_hook(self::HOOK);
        $options = self::options();
        if (!empty($options['enabled']) && !empty($options['heartbeat_enabled']) &&
            self::valid_heartbeat_url(self::api_text($options['heartbeat_url'] ?? ''))) {
            wp_schedule_event(time() + 60, 'arcwind_every_minute', self::HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(ArcWind_Init_OneUptime_Dashboard::HISTORY_HOOK);
    }

    public static function uninstall() {
        self::deactivate();
        delete_option(self::KEY);
        delete_option(self::HEARTBEAT_HEALTH);
        delete_transient(self::CACHE);
        delete_transient(self::ALERT_CACHE);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::CACHE);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::LAST_GOOD);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::FAILURE_CACHE);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::HISTORY_CACHE);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::HISTORY_LAST_GOOD);
        delete_transient(ArcWind_Init_OneUptime_Dashboard::HISTORY_FAILURE);
    }

    public function sync_cron() {
        $o = $this->options();
        $active = $o['enabled'] && $o['heartbeat_enabled'] && $o['heartbeat_url'];
        $event = wp_get_scheduled_event(self::HOOK);
        if ($event && function_exists('_get_cron_array')) {
            $count = 0;
            foreach ((array) _get_cron_array() as $hooks) {
                if (isset($hooks[self::HOOK]) && is_array($hooks[self::HOOK])) $count += count($hooks[self::HOOK]);
            }
            if ($count > 1) {
                wp_clear_scheduled_hook(self::HOOK);
                $event = false;
            }
        }
        if (!$active && $event) wp_clear_scheduled_hook(self::HOOK);
        if ($active && (!$event || $event->schedule !== 'arcwind_every_minute')) {
            wp_clear_scheduled_hook(self::HOOK);
            wp_schedule_event(time() + 60, 'arcwind_every_minute', self::HOOK);
        }
    }

    public function send_heartbeat() {
        $o = $this->options();
        if (!$o['enabled'] || !$o['heartbeat_enabled'] || !$o['heartbeat_url']) return;
        $health = ['last_attempt' => time(), 'last_success' => 0, 'status' => 'failed'];
        $previous = get_option(self::HEARTBEAT_HEALTH, []);
        if (is_array($previous) && isset($previous['last_success']) && is_int($previous['last_success'])) {
            $health['last_success'] = $previous['last_success'];
        }
        try {
            if (!self::valid_heartbeat_url($o['heartbeat_url'])) {
                self::api_diagnostic('heartbeat', 'invalid URL');
            } else {
                $response = wp_safe_remote_get($o['heartbeat_url'], [
                    'timeout' => 5, 'redirection' => 0, 'sslverify' => true,
                ]);
                if (is_wp_error($response) || !is_array($response)) {
                    self::api_diagnostic('heartbeat', 'request failed');
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    if (is_int($code) && $code >= 200 && $code < 300) {
                        $health['last_success'] = $health['last_attempt'];
                        $health['status'] = 'ok';
                    } else {
                        self::api_diagnostic('heartbeat', 'HTTP ' . (is_int($code) ? $code : 'unknown'));
                    }
                }
            }
        } catch (Throwable $error) {
            self::api_diagnostic('heartbeat', 'request exception');
        }
        update_option(self::HEARTBEAT_HEALTH, $health, false);
    }

    public static function valid_heartbeat_url($url) {
        if (!is_string($url)) return false;
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
            !preg_match('~/heartbeat/[^/?#]+/?$~', $parts['path'] ?? '')) return false;
        return wp_http_validate_url($url) !== false;
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
            $messages[] = ['type' => $type, 'title' => $title, 'url' => $url,
                'id' => self::api_text($item['id'] ?? null)];
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

    public static function request_api($url, $endpoint, $method = 'GET', $timeout = 8, $post_body = []) {
        $args = ['timeout' => $timeout, 'redirection' => 0];
        try {
            if ($method === 'POST') {
                $args['headers'] = ['Content-Type' => 'application/json'];
                $args['body'] = wp_json_encode($post_body);
                $response = wp_safe_remote_post($url, $args);
            } else {
                $response = wp_safe_remote_get($url, $args);
            }
        } catch (Throwable $error) {
            self::api_diagnostic($endpoint, 'request exception');
            return new WP_Error('api_unavailable', 'OneUptime kunde inte nås.');
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

    private function active_messages($place) {
        $o = $this->options();
        if (!$o['enabled'] || !$o['banner_' . $place] || !$o['status_page_id'] || !$o['status_url']) return [];
        $cached = get_transient(self::CACHE);
        if ($cached !== false && is_array($cached)) return $this->messages_for_place(self::api_messages($cached), $o, $place);
        $parsed = wp_parse_url($o['status_url']);
        if (!$parsed || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])) return [];
        $base = 'https://' . $parsed['host'];
        if (!empty($parsed['port'])) $base .= ':' . (int) $parsed['port'];
        $api = $base . '/status-page-api/';
        $id = rawurlencode($o['status_page_id']);
        $messages = [];
        $resources = [
            'incidents' => ['enabled' => $o['public_incidents'] || $o['admin_incidents'], 'key' => 'incidents', 'type' => 'incident'],
            'announcements' => ['enabled' => $o['public_announcements'] || $o['admin_announcements'], 'key' => 'announcements', 'type' => 'announcement'],
            'scheduled-maintenance-events' => ['enabled' => $o['public_maintenance'] || $o['admin_maintenance'], 'key' => 'scheduledMaintenanceEvents', 'type' => 'maintenance'],
        ];
        foreach ($resources as $endpoint => $config) {
            if (!$config['enabled']) continue;
            $added = 0;
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
                $messages[] = ['type' => $config['type'], 'title' => $title, 'url' => $o['status_url'],
                    'id' => self::api_id($record['_id'] ?? $record['id'] ?? '')];
                if (++$added >= 10) break;
            }
        }
        set_transient(self::CACHE, $messages, MINUTE_IN_SECONDS);
        return $this->messages_for_place($messages, $o, $place);
    }

    private function messages_for_place($messages, $options, $place) {
        $keys = ['incident' => 'incidents', 'announcement' => 'announcements', 'maintenance' => 'maintenance'];
        return array_slice(array_values(array_filter($messages, function ($message) use ($options, $place, $keys) {
            return !empty($options[$place . '_' . $keys[$message['type']]]);
        })), 0, 10);
    }

    private static function alert_api_list($host, $key, $resource, $body) {
        $url = $host . '/api/' . $resource . '/get-list?skip=0&limit=100';
        try {
            $response = wp_safe_remote_post($url, [
                'timeout' => 5, 'redirection' => 0, 'sslverify' => true,
                'headers' => ['Content-Type' => 'application/json', 'ApiKey' => $key],
                'body' => wp_json_encode($body),
            ]);
        } catch (Throwable $error) {
            self::api_diagnostic($resource, 'request exception');
            return null;
        }
        if (is_wp_error($response) || !is_array($response)) {
            self::api_diagnostic($resource, 'request failed');
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if (!is_int($code) || $code < 200 || $code >= 300) {
            self::api_diagnostic($resource, 'HTTP ' . (is_int($code) ? $code : 'unknown'));
            return null;
        }
        $data = self::parse_api_json(wp_remote_retrieve_body($response));
        if (is_wp_error($data) || !isset($data['data']) || !is_array($data['data'])) {
            self::api_diagnostic($resource, 'invalid response');
            return null;
        }
        return array_values(array_filter($data['data'], 'is_array'));
    }

    private static function safe_monitor_id($value) {
        $id = self::api_id($value);
        return preg_match('/^[A-Za-z0-9._-]+$/', $id) ? $id : '';
    }

    public static function status_page_monitor_ids($overview) {
        if (!is_array($overview) || !isset($overview['statusPageResources']) || !is_array($overview['statusPageResources'])) return [];
        $ids = [];
        $groups = [];
        foreach ($overview['statusPageResources'] as $resource) {
            if (!is_array($resource)) continue;
            $monitor = self::safe_monitor_id($resource['monitorId'] ?? $resource['monitor'] ?? '');
            if ($monitor !== '') $ids[$monitor] = true;
            $group = self::safe_monitor_id($resource['monitorGroupId'] ?? $resource['monitorGroup'] ?? '');
            if ($group !== '') $groups[] = $group;
        }
        $mapping = $overview['monitorsInGroup'] ?? [];
        $seen = [];
        while ($groups && count($seen) < 100) {
            $group = array_shift($groups);
            if (isset($seen[$group])) continue;
            $seen[$group] = true;
            $members = is_array($mapping) ? ($mapping[$group] ?? []) : [];
            if (!is_array($members)) continue;
            foreach ($members as $key => $member) {
                $monitor = '';
                if (is_string($key) && !ctype_digit($key)) $monitor = self::safe_monitor_id($key);
                if (!$monitor && is_array($member)) {
                    $monitor = self::safe_monitor_id($member['monitorId'] ?? $member['monitor'] ?? $member['_id'] ?? '');
                    $nested = self::safe_monitor_id($member['monitorGroupId'] ?? '');
                    if ($nested !== '' && !isset($seen[$nested])) $groups[] = $nested;
                } elseif (!$monitor) {
                    $monitor = self::safe_monitor_id($member);
                }
                if ($monitor !== '') $ids[$monitor] = true;
            }
        }
        return array_keys($ids);
    }

    private static function active_alerts() {
        $o = self::options();
        if (!$o['enabled'] || !$o['alerts_admin'] || !$o['host'] || !$o['status_page_id'] || !$o['alerts_api_key']) return [];
        $cached = get_transient(self::ALERT_CACHE);
        if (is_array($cached)) return $cached;
        $overview = self::request_api($o['host'] . '/status-page-api/overview/' . rawurlencode($o['status_page_id']), 'alert-page-overview', 'GET', 5);
        $monitor_ids = is_wp_error($overview) ? [] : self::status_page_monitor_ids($overview);
        if (!$monitor_ids) {
            set_transient(self::ALERT_CACHE, [], MINUTE_IN_SECONDS);
            return [];
        }
        $allowed = array_fill_keys($monitor_ids, true);
        $states = self::alert_api_list($o['host'], $o['alerts_api_key'], 'alert-state', [
            'select' => ['_id' => true, 'name' => true, 'isResolvedState' => true],
        ]);
        if ($states === null) {
            set_transient(self::ALERT_CACHE, [], MINUTE_IN_SECONDS);
            return [];
        }
        $alerts = [];
        $queried_states = 0;
        foreach ($states as $state) {
            if (!array_key_exists('isResolvedState', $state) || !empty($state['isResolvedState'])) continue;
            $state_id = self::api_id($state['_id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $state_id)) continue;
            if (++$queried_states > 10) break;
            $records = self::alert_api_list($o['host'], $o['alerts_api_key'], 'alert', [
                'select' => ['_id' => true, 'title' => true, 'description' => true, 'currentAlertStateId' => true, 'monitorId' => true, 'createdAt' => true],
                'query' => ['currentAlertStateId' => $state_id],
                'sort' => ['createdAt' => -1],
            ]);
            if ($records === null) continue;
            foreach ($records as $record) {
                if (self::api_id($record['currentAlertStateId'] ?? '') !== $state_id) continue;
                $monitor_id = self::safe_monitor_id($record['monitorId'] ?? '');
                if ($monitor_id === '' || !isset($allowed[$monitor_id])) continue;
                $title = sanitize_text_field(self::api_text($record['title'] ?? ''));
                if ($title === '') continue;
                $alerts[] = ['title' => $title, 'description' => trim(self::api_text($record['description'] ?? '')),
                    'state' => sanitize_text_field(self::api_text($state['name'] ?? '')),
                    'created' => self::parse_api_timestamp($record['createdAt'] ?? null) ?? 0];
            }
        }
        usort($alerts, function ($a, $b) { return $b['created'] <=> $a['created']; });
        $alerts = array_slice($alerts, 0, 10);
        set_transient(self::ALERT_CACHE, $alerts, MINUTE_IN_SECONDS);
        return $alerts;
    }

    public function banner_styles() {
        $o = $this->options();
        if (!$o['enabled'] || !$o['banner_enabled']) return;
        echo '<style id="arcwind-oneuptime-css">#arcwind-oneuptime-banner{position:relative;z-index:999999;font-size:14px;line-height:1.5}#arcwind-oneuptime-banner .arcwind-oneuptime-message{padding:10px 20px;text-align:center;background:#8b211c;color:#fff}#arcwind-oneuptime-banner .arcwind-oneuptime-message.announcement{background:#195b88}#arcwind-oneuptime-banner .arcwind-oneuptime-message.maintenance{background:#f4c75b;color:#181818}#arcwind-oneuptime-banner a{color:inherit;text-decoration:underline;font-weight:600}#arcwind-oneuptime-banner button{margin-left:12px;color:inherit;background:transparent;border:1px solid currentColor;border-radius:3px;cursor:pointer}#arcwind-oneuptime-banner :focus-visible{outline:3px solid currentColor;outline-offset:2px}body.admin-bar #arcwind-oneuptime-banner{margin-top:calc(-1 * var(--wp-admin--admin-bar--height,32px));margin-bottom:var(--wp-admin--admin-bar--height,32px)}body.admin-bar #wpadminbar{top:var(--arcwind-banner-height,0px)}@media(max-width:782px){body.admin-bar #arcwind-oneuptime-banner{margin-top:calc(-1 * var(--wp-admin--admin-bar--height,46px));margin-bottom:var(--wp-admin--admin-bar--height,46px)}}</style>';
    }

    public function render_banner() {
        if (!$this->options()['banner_public']) return;
        $items = $this->active_messages('public');
        if (!$items) return;
        echo '<div id="arcwind-oneuptime-banner" role="region" aria-label="Driftinformation">';
        foreach ($items as $item) {
            $labels = ['incident' => 'Driftstörning', 'announcement' => 'Information', 'maintenance' => 'Planerat underhåll'];
            $id = self::api_text($item['id'] ?? null);
            $dismissible = $id !== '' || $item['type'] !== 'incident';
            $key = hash('sha256', $item['type'] . '|' . ($id !== '' ? $id : $item['title']));
            echo '<div class="arcwind-oneuptime-message ' . esc_attr($item['type']) . '" role="' . ($item['type'] === 'incident' ? 'alert' : 'status') . '" data-dismiss-key="' . esc_attr($key) . '"><strong>' . esc_html($labels[$item['type']]) . ':</strong> ' . esc_html($item['title']) . ' <a href="' . esc_url($item['url']) . '">Läs mer</a>';
            if ($dismissible) echo ' <button type="button" aria-label="Dölj ' . esc_attr($labels[$item['type']]) . '">Stäng</button>';
            echo '</div>';
        }
        echo '</div>';
        echo '<script>(function(){try{var banner=document.getElementById("arcwind-oneuptime-banner"), prefix="arcwind-banner-' . esc_js((string) get_current_user_id()) . '-";if(!banner)return;function update(){document.documentElement.style.setProperty("--arcwind-banner-height",banner.offsetHeight+"px")}Array.prototype.forEach.call(banner.querySelectorAll("[data-dismiss-key]"),function(row){var key=prefix+row.getAttribute("data-dismiss-key"),button=row.querySelector("button");try{if(sessionStorage.getItem(key)==="1")row.remove()}catch(e){}if(button)button.addEventListener("click",function(){try{sessionStorage.setItem(key,"1")}catch(e){}row.remove();update()})});update();if(window.ResizeObserver)new ResizeObserver(update).observe(banner);else window.addEventListener("resize",update)}catch(e){}})();</script>';
    }

    public function render_admin_notice() {
        if ($this->options()['banner_admin']) {
            $items = $this->active_messages('admin');
            foreach ($items as $item) {
                echo '<div class="notice notice-info inline"><p><strong>' . esc_html(ucfirst($item['type'])) . ':</strong> ' . esc_html($item['title']) . ' <a href="' . esc_url($item['url']) . '">Läs mer</a></p></div>';
            }
        }
        if (isset($_GET['page']) && $_GET['page'] === 'arcwind_init') return;
        self::render_alert_notice();
    }

    public static function render_alert_notice() {
        if (!current_user_can('manage_options')) return;
        $alerts = self::active_alerts();
        if ($alerts) {
            echo '<div class="notice notice-warning inline arcwind-oneuptime-alerts"><p><strong>Aktiva alerts för statussidan</strong></p><ul style="list-style:none;padding-left:0">';
            foreach ($alerts as $alert) {
                echo '<li><details style="border-top:1px solid #dcdcde;padding:8px 0"><summary style="cursor:pointer;font-weight:600">' . esc_html($alert['title']);
                if ($alert['state'] !== '') echo ' <span class="description" style="font-weight:normal">(' . esc_html($alert['state']) . ')</span>';
                $description = $alert['description'] ?? '';
                echo '</summary><p style="white-space:pre-wrap;margin:8px 0 4px 18px">' . ($description !== '' ? esc_html($description) : 'Ingen beskrivning finns.') . '</p></details></li>';
            }
            echo '</ul></div>';
        }
    }
}
