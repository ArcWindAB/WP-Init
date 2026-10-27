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
        } elseif ($task === 'clear_log') {
            $confirmed = isset($_POST['confirm_clear_log']) && $_POST['confirm_clear_log'] === 'yes';
            $result = $confirmed && class_exists('ArcWind_Init_Error_Log') && ArcWind_Init_Error_Log::clear()
                ? [__('Felloggen har rensats.', 'arcwind-init')]
                : [__('Felloggen kunde inte rensas.', 'arcwind-init')];
        } else {
            $result = [__('Ogiltig åtgärd.', 'arcwind-init')];
        }
        set_transient(self::RESULT_PREFIX . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('options-general.php?page=arcwind_init&tab=diagnostics'));
        exit;
    }

    public function test_connections() {
        if (!current_user_can('manage_options')) return [];
        $result = [];
        $settings = (array) get_option(ArcWind_Init::OPTION_KEY, []);
        $region = ($settings['region'] ?? 'eu') === 'us' ? 'us' : 'eu';
        $host = is_string($settings['ui_host'] ?? null) && $settings['ui_host'] !== ''
            ? rtrim($settings['ui_host'], '/') : 'https://' . $region . '.posthog.com';
        $project = is_scalar($settings['project_id'] ?? null) ? (string) $settings['project_id'] : '';
        $key = is_string($settings['personal_api_key'] ?? null) ? $settings['personal_api_key'] : '';
        if (!$host || !$project || !$key || wp_parse_url($host, PHP_URL_SCHEME) !== 'https') {
            $result[] = __('PostHog: ofullständig eller ogiltig konfiguration.', 'arcwind-init');
            if (class_exists('ArcWind_Init_Error_Log')) ArcWind_Init_Error_Log::record('PostHog', 'connection-test', 'warning', 'configuration');
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
                if (class_exists('ArcWind_Init_Error_Log')) {
                    if ($ok) ArcWind_Init_Error_Log::recover('PostHog', 'connection-test');
                    elseif (is_wp_error($response)) ArcWind_Init_Error_Log::record('PostHog', 'connection-test', 'error', ArcWind_Init_Error_Log::transport_code($response));
                    else {
                        $status = is_array($response) ? wp_remote_retrieve_response_code($response) : 0;
                        $code = $status === 200 ? 'format' : (in_array($status, [401, 403], true) ? 'authentication' : 'http');
                        ArcWind_Init_Error_Log::record('PostHog', 'connection-test', 'error', $code, ['http_status' => $status]);
                    }
                }
            } catch (Throwable $error) {
                $result[] = __('PostHog: anslutningen misslyckades.', 'arcwind-init');
                if (class_exists('ArcWind_Init_Error_Log')) ArcWind_Init_Error_Log::record('PostHog', 'connection-test', 'error', 'transport');
            }
        }

        $uuid = $settings['feedback_uuid'] ?? '';
        $result[] = is_string($uuid) && preg_match('/^[A-Za-z0-9]+$/', $uuid) ? __('Feedback: giltig UUID-konfiguration.', 'arcwind-init') : __('Feedback: UUID saknas eller är ogiltig.', 'arcwind-init');
        if (!empty($settings['feedback_enabled']) && (!is_string($uuid) || !preg_match('/^[A-Za-z0-9]+$/', $uuid)) && class_exists('ArcWind_Init_Error_Log')) {
            ArcWind_Init_Error_Log::record('Feedback', 'connection-test', 'warning', 'configuration');
        }

        $oneuptime = ArcWind_Init_OneUptime::options();
        $status_url = ArcWind_Init_OneUptime::api_text($oneuptime['status_url'] ?? '');
        $page_id = ArcWind_Init_OneUptime::api_text($oneuptime['status_page_id'] ?? '');
        $parts = $status_url ? wp_parse_url($status_url) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !$page_id) {
            $result[] = __('OneUptime: status-URL eller sid-ID saknas.', 'arcwind-init');
            if (class_exists('ArcWind_Init_Error_Log')) ArcWind_Init_Error_Log::record('OneUptime', 'connection-test', 'warning', 'configuration');
        } else {
            $base = 'https://' . $parts['host'] . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '');
            $data = ArcWind_Init_OneUptime::request_api($base . '/status-page-api/overview/' . rawurlencode($page_id), 'diagnostic-overview');
            $result[] = !is_wp_error($data) && array_key_exists('overallStatus', $data) ? __('OneUptime: status-API fungerar.', 'arcwind-init') : __('OneUptime: status-API kunde inte läsas.', 'arcwind-init');
            if (class_exists('ArcWind_Init_Error_Log')) {
                if (!is_wp_error($data) && array_key_exists('overallStatus', $data)) ArcWind_Init_Error_Log::recover('OneUptime', 'connection-test');
                elseif (!is_wp_error($data)) ArcWind_Init_Error_Log::record('OneUptime', 'connection-test', 'error', 'format');
            }
        }
        return $result;
    }

    public function render() {
        if (!current_user_can('manage_options')) return;
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
        echo '<li>PHP: ' . esc_html(PHP_VERSION) . '; ' . esc_html__('WordPress:', 'arcwind-init') . ' ' . esc_html(get_bloginfo('version')) . '; ' . esc_html__('plugin:', 'arcwind-init') . ' ' . esc_html(ArcWind_Init::get_version()) . '.</li>';
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
        $this->render_log();
    }

    private function render_log() {
        echo '<section id="arcwind-error-log"><h2>' . esc_html__('Fellogg', 'arcwind-init') . '</h2>';
        echo '<p>' . esc_html__('Loggen sparar högst 100 händelser i 30 dagar. Upprepade fel räknas samman. Tider visas i webbplatsens tidszon.', 'arcwind-init') . '</p>';
        $source = isset($_GET['log_source']) && is_string($_GET['log_source']) ? sanitize_key(wp_unslash($_GET['log_source'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
        $severity = isset($_GET['log_severity']) && is_string($_GET['log_severity']) ? sanitize_key(wp_unslash($_GET['log_severity'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
        echo '<form method="get"><input type="hidden" name="page" value="arcwind_init"><input type="hidden" name="tab" value="diagnostics">';
        echo '<label for="arcwind-log-source">' . esc_html__('Integration', 'arcwind-init') . '</label> <select id="arcwind-log-source" name="log_source"><option value="">' . esc_html__('Alla', 'arcwind-init') . '</option>';
        foreach (['posthog' => 'PostHog', 'oneuptime' => 'OneUptime', 'feedback' => 'Feedback', 'github' => 'GitHub', 'diagnostics' => 'Diagnostics'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($source, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <label for="arcwind-log-severity">' . esc_html__('Allvarlighetsgrad', 'arcwind-init') . '</label> <select id="arcwind-log-severity" name="log_severity"><option value="">' . esc_html__('Alla', 'arcwind-init') . '</option>';
        foreach (['info' => __('Information', 'arcwind-init'), 'warning' => __('Varning', 'arcwind-init'), 'error' => __('Fel', 'arcwind-init'), 'critical' => __('Kritiskt', 'arcwind-init')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($severity, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <button class="button" type="submit">' . esc_html__('Filtrera', 'arcwind-init') . '</button></form>';
        $rows = class_exists('ArcWind_Init_Error_Log') ? array_reverse(ArcWind_Init_Error_Log::entries()) : [];
        $shown = 0;
        foreach ($rows as $row) {
            if ($source !== '' && $source !== sanitize_key($row['source'] ?? '')) continue;
            if ($severity !== '' && $severity !== ($row['severity'] ?? '')) continue;
            ++$shown;
            $date = wp_date('Y-m-d H:i:s', (int) $row['last']);
            echo '<article class="notice notice-' . esc_attr(($row['severity'] ?? '') === 'info' ? 'success' : 'warning') . ' inline" style="margin:12px 0;padding:8px 12px">';
            $severity_labels = ['info' => __('Information', 'arcwind-init'), 'warning' => __('Varning', 'arcwind-init'), 'error' => __('Fel', 'arcwind-init'), 'critical' => __('Kritiskt', 'arcwind-init')];
            echo '<strong>' . esc_html($row['source']) . '</strong> · <time datetime="' . esc_attr(gmdate('c', (int) $row['last'])) . '">' . esc_html($date) . '</time> · ' . esc_html($severity_labels[$row['severity']] ?? $row['severity']) . '<p>' . esc_html(ArcWind_Init_Error_Log::description($row['code'])) . ' ' . esc_html(ArcWind_Init_Error_Log::remedy($row['code'])) . '</p>';
            echo '<details><summary>' . esc_html__('Teknisk information', 'arcwind-init') . '</summary><p><code>' . esc_html($row['action'] . ' / ' . $row['code'] . (isset($row['context']['http_status']) ? ' / HTTP ' . $row['context']['http_status'] : '')) . '</code></p><p>' . esc_html__('Första förekomst:', 'arcwind-init') . ' ' . esc_html(wp_date('Y-m-d H:i:s', (int) $row['first'])) . ' · ' . esc_html__('Antal:', 'arcwind-init') . ' ' . esc_html((string) $row['count']) . '</p></details></article>';
        }
        if (!$shown) echo '<p>' . esc_html($source === '' && $severity === '' ? __('Inga fel har registrerats under de senaste 30 dagarna.', 'arcwind-init') : __('Inga loggposter matchar urvalet.', 'arcwind-init')) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr(wp_json_encode(__('Vill du rensa felloggen?', 'arcwind-init'))) . ')">';
        wp_nonce_field('arcwind_init_diagnostics');
        echo '<input type="hidden" name="action" value="arcwind_init_diagnostics"><input type="hidden" name="confirm_clear_log" value="yes"><button class="button" type="submit" name="diagnostic_task" value="clear_log">' . esc_html__('Rensa fellogg', 'arcwind-init') . '</button></form></section>';
    }
}
