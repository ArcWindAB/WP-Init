<?php
/** Administrator-only connection checks and integration health. */
if (!defined('ABSPATH')) exit;

class ArcWind_Init_Diagnostics {
    const RESULT_PREFIX = 'arcwind_init_diagnostics_';

    public function __construct() {
        add_action('admin_post_arcwind_init_diagnostics', [$this, 'handle_action']);
    }

    public function handle_action() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Åtkomst nekad.', 'arcwind-init'));
        check_admin_referer('arcwind_init_diagnostics');
        $task = isset($_POST['diagnostic_task']) && is_string($_POST['diagnostic_task']) ? sanitize_key(wp_unslash($_POST['diagnostic_task'])) : '';
        if ($task === 'clear') {
            foreach (['arcwind_init_stats_v1', ArcWind_Init_OneUptime::CACHE, ArcWind_Init_OneUptime::ALERT_CACHE,
                ArcWind_Init_OneUptime_Dashboard::CACHE, ArcWind_Init_OneUptime_Dashboard::LAST_GOOD,
                ArcWind_Init_OneUptime_Dashboard::FAILURE_CACHE, ArcWind_Init_OneUptime_Dashboard::HISTORY_CACHE,
                ArcWind_Init_OneUptime_Dashboard::HISTORY_LAST_GOOD, ArcWind_Init_OneUptime_Dashboard::HISTORY_FAILURE,
                ArcWind_Init_GitHub_Updater::CACHE_KEY] as $key) {
                delete_transient($key);
            }
            $result = [__('Cache rensad.', 'arcwind-init')];
        } elseif ($task === 'test') {
            $result = $this->test_connections();
        } else {
            $result = [__('Ogiltig åtgärd.', 'arcwind-init')];
        }
        set_transient(self::RESULT_PREFIX . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('options-general.php?page=arcwind_init&tab=diagnostics'));
        exit;
    }

    public function test_connections() {
        $result = [];
        $settings = (array) get_option(ArcWind_Init::OPTION_KEY, []);
        $region = ($settings['region'] ?? 'eu') === 'us' ? 'us' : 'eu';
        $host = is_string($settings['ui_host'] ?? null) && $settings['ui_host'] !== ''
            ? rtrim($settings['ui_host'], '/') : 'https://' . $region . '.posthog.com';
        $project = is_scalar($settings['project_id'] ?? null) ? (string) $settings['project_id'] : '';
        $key = is_string($settings['personal_api_key'] ?? null) ? $settings['personal_api_key'] : '';
        if (!$host || !$project || !$key || wp_parse_url($host, PHP_URL_SCHEME) !== 'https') {
            $result[] = __('PostHog: ofullständig eller ogiltig konfiguration.', 'arcwind-init');
        } else {
            try {
                $response = wp_safe_remote_post($host . '/api/projects/' . rawurlencode($project) . '/query/', [
                    'timeout' => 5, 'redirection' => 0,
                    'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
                    'body' => wp_json_encode(['query' => ['kind' => 'HogQLQuery', 'query' => 'SELECT 1 AS c']]),
                ]);
                $ok = !is_wp_error($response) && is_array($response) && wp_remote_retrieve_response_code($response) === 200;
                if ($ok) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $ok = is_array($body) && isset($body['results']) && is_array($body['results']);
                }
                $result[] = $ok ? __('PostHog: anslutningen fungerar.', 'arcwind-init') : __('PostHog: anslutningen eller API-svaret misslyckades.', 'arcwind-init');
            } catch (Throwable $error) {
                $result[] = __('PostHog: anslutningen misslyckades.', 'arcwind-init');
            }
        }

        $uuid = $settings['feedback_uuid'] ?? '';
        $result[] = is_string($uuid) && preg_match('/^[A-Za-z0-9]+$/', $uuid) ? __('Feedback: giltig UUID-konfiguration.', 'arcwind-init') : __('Feedback: UUID saknas eller är ogiltig.', 'arcwind-init');

        $oneuptime = ArcWind_Init_OneUptime::options();
        $status_url = ArcWind_Init_OneUptime::api_text($oneuptime['status_url'] ?? '');
        $page_id = ArcWind_Init_OneUptime::api_text($oneuptime['status_page_id'] ?? '');
        $parts = $status_url ? wp_parse_url($status_url) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !$page_id) {
            $result[] = __('OneUptime: status-URL eller sid-ID saknas.', 'arcwind-init');
        } else {
            $base = 'https://' . $parts['host'] . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '');
            $data = ArcWind_Init_OneUptime::request_api($base . '/status-page-api/overview/' . rawurlencode($page_id), 'diagnostic-overview');
            $result[] = !is_wp_error($data) && array_key_exists('overallStatus', $data) ? __('OneUptime: status-API fungerar.', 'arcwind-init') : __('OneUptime: status-API kunde inte läsas.', 'arcwind-init');
        }
        return $result;
    }

    public function render() {
        $settings = (array) get_option(ArcWind_Init::OPTION_KEY, []);
        $oneuptime = ArcWind_Init_OneUptime::options();
        $health = get_option(ArcWind_Init_OneUptime::HEARTBEAT_HEALTH, []);
        $health = is_array($health) ? $health : [];
        echo '<h2>' . esc_html__('Diagnostik', 'arcwind-init') . '</h2><ul>';
        echo '<li>PostHog: ' . esc_html(!empty($settings['enable_tracking']) ? __('spårning aktiv', 'arcwind-init') : __('spårning av', 'arcwind-init')) . '; ' . esc_html(!empty($settings['personal_api_key']) ? __('servernyckel sparad', 'arcwind-init') : __('servernyckel saknas', 'arcwind-init')) . '.</li>';
        echo '<li>Feedback: ' . esc_html(!empty($settings['feedback_enabled']) ? __('aktiv', 'arcwind-init') : __('av', 'arcwind-init')) . '; ' . esc_html(!empty($settings['feedback_uuid']) ? __('UUID sparad', 'arcwind-init') : __('UUID saknas', 'arcwind-init')) . '.</li>';
        echo '<li>OneUptime: ' . esc_html(!empty($oneuptime['enabled']) ? __('aktiv', 'arcwind-init') : __('av', 'arcwind-init')) . '; ' . esc_html(!empty($oneuptime['status_url']) && !empty($oneuptime['status_page_id']) ? __('statussida konfigurerad', 'arcwind-init') : __('statussida saknas', 'arcwind-init')) . '.</li>';
        echo '<li>Heartbeat: ' . esc_html(!empty($oneuptime['heartbeat_enabled']) ? __('aktiv', 'arcwind-init') : __('av', 'arcwind-init')) . '; ' . esc_html(!empty($oneuptime['heartbeat_url']) ? __('ID sparat', 'arcwind-init') : __('ID saknas', 'arcwind-init')) . '.</li>';
        echo '<li>OneUptime-alerts: ' . esc_html(!empty($oneuptime['alerts_admin']) ? __('visning i admin aktiv', 'arcwind-init') : __('visning i admin av', 'arcwind-init')) . '; ' . esc_html(!empty($oneuptime['alerts_api_key']) ? __('API-nyckel sparad', 'arcwind-init') : __('API-nyckel saknas', 'arcwind-init')) . '.</li>';
        echo '<li>PHP: ' . esc_html(PHP_VERSION) . '; ' . esc_html__('plugin:', 'arcwind-init') . ' ' . esc_html(ArcWind_Init::get_version()) . '.</li>';
        $next = wp_next_scheduled(ArcWind_Init_OneUptime::HOOK);
        if ($next) {
            echo '<li>' . esc_html__('Nästa heartbeat:', 'arcwind-init') . ' ' . esc_html(wp_date('Y-m-d H:i:s', $next)) . '.</li>';
            if ($next < time() - 120) echo '<li><strong>' . esc_html__('WP-Cron verkar försenad.', 'arcwind-init') . '</strong> ' . esc_html__('Kör systemcron varje minut och kontrollera att wp-cron.php nås.', 'arcwind-init') . '</li>';
        } elseif (!empty($oneuptime['enabled']) && !empty($oneuptime['heartbeat_enabled'])) {
            echo '<li><strong>' . esc_html__('Heartbeat är aktiverad men inget cron-jobb är schemalagt.', 'arcwind-init') . '</strong></li>';
        }
        if (isset($health['last_attempt']) && is_int($health['last_attempt'])) echo '<li>' . esc_html__('Senaste försök:', 'arcwind-init') . ' ' . esc_html(wp_date('Y-m-d H:i:s', $health['last_attempt'])) . ' (' . esc_html(!empty($health['status']) && $health['status'] === 'ok' ? __('lyckades', 'arcwind-init') : __('misslyckades', 'arcwind-init')) . ').</li>';
        if (isset($health['last_success']) && is_int($health['last_success']) && $health['last_success'] > 0) echo '<li>' . esc_html__('Senast lyckade heartbeat:', 'arcwind-init') . ' ' . esc_html(wp_date('Y-m-d H:i:s', $health['last_success'])) . '.</li>';
        echo '</ul>';
        $results = get_transient(self::RESULT_PREFIX . get_current_user_id());
        if (is_array($results)) {
            echo '<div class="notice notice-info inline"><ul>';
            foreach ($results as $message) if (is_string($message)) echo '<li>' . esc_html($message) . '</li>';
            echo '</ul></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('arcwind_init_diagnostics');
        echo '<input type="hidden" name="action" value="arcwind_init_diagnostics">';
        echo '<button class="button button-primary" type="submit" name="diagnostic_task" value="test">' . esc_html__('Testa anslutningar', 'arcwind-init') . '</button> ';
        echo '<button class="button" type="submit" name="diagnostic_task" value="clear">' . esc_html__('Rensa cache', 'arcwind-init') . '</button></form>';
    }
}
