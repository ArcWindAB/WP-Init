<?php
if (!defined('ABSPATH')) exit;

trait ArcWind_Init_Dashboard_Trait {
    /* =========================
     * Dashboard widgets
     * ========================= */
    public function register_dashboard_widgets() {
        $options = $this->options();
        $region = ($options['region'] ?? 'eu') === 'us' ? 'us' : 'eu';
        $ui_host = ($options['ui_host'] ?? '') ?: 'https://' . $region . '.posthog.com';
        if (wp_parse_url($ui_host, PHP_URL_SCHEME) === 'https' &&
            !empty($options['project_id']) && !empty($options['personal_api_key'])) {
            wp_add_dashboard_widget(
                'arcwind_init_dashboard_widget',
                __('Statistik', 'arcwind-init'),
                [$this, 'render_dashboard_widget']
            );
        }

        if (method_exists($this, 'render_feedback_widget') &&
            (!isset($options['feedback_enabled']) || $options['feedback_enabled']) &&
            !empty($options['feedback_uuid']) && preg_match('/^[A-Za-z0-9]+$/', $options['feedback_uuid'])) {
            wp_add_dashboard_widget(
                'arcwind_init_feedback_widget',
                __('Rapportera fel eller önska funktion', 'arcwind-init'),
                [$this, 'render_feedback_widget']
            );
        }
    }

    public function render_dashboard_widget() {
        $opts = $this->options();
        $missing = [];

        if (empty($opts['project_id']))       $missing[] = 'Project ID';
        if (empty($opts['personal_api_key'])) $missing[] = 'Personal API Key';

        if (!empty($missing)) {
            echo '<p><strong>' . esc_html__('PostHog är inte konfigurerat.', 'arcwind-init') . '</strong></p>';
            echo '<p>' . esc_html__('Saknas:', 'arcwind-init') . ' ' . esc_html(implode(', ', $missing)) . '</p>';
            echo '<p><a href="' . esc_url(admin_url('options-general.php?page=arcwind_init')) . '">' . esc_html__('Gå till inställningar', 'arcwind-init') . '</a></p>';
            return;
        }

        $cache_key = 'arcwind_init_stats_v1';
        $data = get_transient($cache_key);
        if (!is_array($data) || !array_key_exists('active_7d', $data) || !array_key_exists('pageviews_7d', $data)) $data = false;

        if (false === $data) {
            if (!wp_next_scheduled('arcwind_init_refresh_posthog_stats')) {
                wp_schedule_single_event(time(), 'arcwind_init_refresh_posthog_stats');
                if (function_exists('spawn_cron') && (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON)) spawn_cron(time());
            }
            $data = ['active_7d' => null, 'pageviews_7d' => null, 'fetched_at' => __('väntar på hämtning', 'arcwind-init')];
        }

        echo '<div class="arcwind-grid">';
        $this->render_stat_card(__('Aktiva användare (7 dagar)', 'arcwind-init'), $this->format_num($data['active_7d']));
        $this->render_stat_card(__('Sidvisningar (7 dagar)', 'arcwind-init'), $this->format_num($data['pageviews_7d']));
        echo '</div>';

        echo '<p class="arcwind-subtle">' . esc_html__('Uppdaterad:', 'arcwind-init') . ' ' . esc_html(is_string($data['fetched_at'] ?? null) ? $data['fetched_at'] : __('okänd', 'arcwind-init')) . '. ' . esc_html__('Cache: 10 min.', 'arcwind-init') . '</p>';
    }

    public function refresh_posthog_stats() {
        $opts = $this->options();
        if (empty($opts['project_id']) || empty($opts['personal_api_key'])) return;
        $errors = [];
        $active_7d = $this->fetch_scalar(
            'SELECT count(DISTINCT distinct_id) AS c FROM events WHERE timestamp >= now() - INTERVAL 7 DAY',
            $errors
        );
        $pageviews_7d = $this->fetch_scalar(
            'SELECT count() AS c FROM events WHERE event = \'$pageview\' AND timestamp >= now() - INTERVAL 7 DAY',
            $errors
        );
        set_transient('arcwind_init_stats_v1', [
            'active_7d' => is_numeric($active_7d) ? (int) $active_7d : null,
            'pageviews_7d' => is_numeric($pageviews_7d) ? (int) $pageviews_7d : null,
            'errors' => $errors,
            'fetched_at' => current_time('mysql'),
        ], empty($errors) ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);
        if ($errors) set_transient(self::ERR_TRANSIENT, $errors, self::ERR_TTL);
    }

    private function render_stat_card($title, $value) {
        echo '<div class="arcwind-card">';
        echo '<h4>' . esc_html($title) . '</h4>';
        echo '<div class="arcwind-value">' . esc_html($value ?? '—') . '</div>';
        echo '</div>';
    }

    private function format_num($n) {
        if (!is_scalar($n) || !is_numeric($n)) return '—';
        return number_format_i18n((int)$n);
    }

    /* =========================
     * Query API helpers + error capture
     * ========================= */
    private function fetch_scalar($hogql, &$errors) {
        $res = $this->query_posthog($hogql, $errors);
        if (!$res || empty($res['results']) || !is_array($res['results'])) {
            $errors[] = __('Statistikfrågan gav inget resultat.', 'arcwind-init');
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
        $opts = $this->options();
        $region     = ($opts['region'] ?? 'eu') === 'us' ? 'us' : 'eu';
        $host       = is_string($opts['ui_host'] ?? null) && $opts['ui_host'] !== ''
            ? rtrim($opts['ui_host'], '/') : 'https://' . $region . '.posthog.com';
        $project_id = is_scalar($opts['project_id'] ?? null) ? (string) $opts['project_id'] : '';
        $api_key    = is_string($opts['personal_api_key'] ?? null) ? $opts['personal_api_key'] : '';

        if (empty($host) || empty($project_id) || empty($api_key) || wp_parse_url($host, PHP_URL_SCHEME) !== 'https') {
            $errors[] = __('Saknad konfiguration.', 'arcwind-init');
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
            'timeout'     => 5,
            'redirection' => 0,
            'headers'     => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'        => wp_json_encode($body),
            'data_format' => 'body',
        ];

        try {
            $resp = wp_safe_remote_post($endpoint, $args);
        } catch (Throwable $error) {
            $resp = new WP_Error('request_exception', 'PostHog request failed');
        }

        if (is_wp_error($resp) || !is_array($resp)) {
            $msg = __('PostHog kunde inte nås.', 'arcwind-init');
            $errors[] = $msg;
            $this->capture_error('http_error', $msg);
            return null;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);

        if (!is_int($code) || $code < 200 || $code >= 300) {
            $msg = __('PostHog svarade med HTTP-fel.', 'arcwind-init');
            $errors[] = $msg;
            $this->capture_error('api_error', $msg, ['status' => is_int($code) ? $code : 0]);
            return null;
        }

        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            $msg = __('Ogiltig JSON från API.', 'arcwind-init');
            $errors[] = $msg;
            $this->capture_error('json_error', $msg);
            return null;
        }

        if (!isset($json['results'])) {
            if (isset($json['result'])) {
                $json['results'] = $json['result'];
            } else {
                $msg = __('Oväntat API-format.', 'arcwind-init');
                $errors[] = $msg;
                $this->capture_error('shape_error', $msg);
                return null;
            }
        }

        if (!is_array($json['results'])) {
            $msg = __('Oväntat API-format.', 'arcwind-init');
            $errors[] = $msg;
            $this->capture_error('shape_error', $msg);
            return null;
        }

        return $json;
    }

    private function capture_error($kind, $message, $props = []) {
        $opts = $this->options();
        if (empty($opts['enable_error_tracking'])) {
            return;
        }

        $distinct_id = is_string($opts['error_distinct_id'] ?? null) && $opts['error_distinct_id'] !== '' ? $opts['error_distinct_id'] : 'arcwind-init:server';

        if (class_exists('ArcWind_Init_Tracker')) ArcWind_Init_Tracker::capture('arcwind_init_error', array_merge([
            'kind'    => (string) $kind,
            'message' => (string) $message,
            'php'     => PHP_VERSION,
            'wp'      => get_bloginfo('version'),
        ], is_array($props) ? $props : []), $distinct_id, false);

        $prev = get_transient(self::ERR_TRANSIENT);
        $prev = is_array($prev) ? $prev : [];
        $prev[] = strtoupper($kind) . ': ' . $message;
        $prev = array_slice($prev, -5);
        set_transient(self::ERR_TRANSIENT, $prev, self::ERR_TTL);
    }
}
