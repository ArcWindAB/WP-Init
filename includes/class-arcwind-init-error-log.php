<?php
/** Bounded, secret-free diagnostic events for administrators. */
if (!defined('ABSPATH')) exit;

class ArcWind_Init_Error_Log {
    const OPTION = 'arcwind_init_error_log_v1';
    const MAX_ENTRIES = 100;
    const RETENTION = 30 * DAY_IN_SECONDS;

    private static function labels() {
        return [
            'configuration' => [__('Nödvändiga inställningar saknas eller är ogiltiga.', 'arcwind-init'), __('Kontrollera integrationens inställningar.', 'arcwind-init')],
            'schedule' => [__('Ett bakgrundsjobb kunde inte schemaläggas.', 'arcwind-init'), __('Kontrollera WP-Cron och serverns cron-jobb.', 'arcwind-init')],
            'transport' => [__('Tjänsten kunde inte nås.', 'arcwind-init'), __('Kontrollera DNS, TLS och serverns utgående HTTPS-anslutning.', 'arcwind-init')],
            'timeout' => [__('Anropet till tjänsten tog för lång tid.', 'arcwind-init'), __('Kontrollera tjänstens tillgänglighet och serverns nätverk.', 'arcwind-init')],
            'dns' => [__('Servern kunde inte hitta tjänstens adress.', 'arcwind-init'), __('Kontrollera DNS och tjänstens värdnamn.', 'arcwind-init')],
            'tls' => [__('Den säkra anslutningen till tjänsten misslyckades.', 'arcwind-init'), __('Kontrollera TLS-certifikat och serverns HTTPS-inställningar.', 'arcwind-init')],
            'authentication' => [__('Tjänsten avvisade behörigheten.', 'arcwind-init'), __('Kontrollera API-nyckel och läsbehörighet.', 'arcwind-init')],
            'http' => [__('Tjänsten svarade med ett HTTP-fel.', 'arcwind-init'), __('Kontrollera tjänstens status och försök igen.', 'arcwind-init')],
            'format' => [__('Tjänsten skickade ett oväntat svar.', 'arcwind-init'), __('Kontrollera tjänstens API-version och försök igen.', 'arcwind-init')],
            'stale' => [__('Senast kända uppgifter används.', 'arcwind-init'), __('Kontrollera det senaste anslutningsfelet.', 'arcwind-init')],
            'package' => [__('Uppdateringspaketet kunde inte användas.', 'arcwind-init'), __('Kontrollera GitHub-releasens ZIP-fil och version.', 'arcwind-init')],
            'recovered' => [__('Anslutningen fungerar igen.', 'arcwind-init'), __('Ingen åtgärd behövs.', 'arcwind-init')],
        ];
    }

    public static function transport_code($error) {
        if (!is_wp_error($error)) return 'transport';
        $code = $error->get_error_code();
        if (in_array($code, ['connect_timeout', 'timeout'], true)) return 'timeout';
        // Inspect only fixed patterns. The original message may contain a secret URL.
        $message = $error->get_error_message();
        if (is_string($message)) {
            if (preg_match('/(?:cURL error 28|timed? out|timeout)/i', $message)) return 'timeout';
            if (preg_match('/(?:cURL error 6\b|resolve host|name resolution|DNS)/i', $message)) return 'dns';
            if (preg_match('/(?:cURL error 35|cURL error 60|SSL|TLS|certificate)/i', $message)) return 'tls';
        }
        return 'transport';
    }

    public static function record($source, $action, $severity, $code, $context = []) {
        if (!class_exists(__CLASS__)) return;
        try {
            $labels = self::labels();
            if (!isset($labels[$code]) || !in_array($severity, ['info', 'warning', 'error', 'critical'], true)) return;
            if (!in_array($source, ['PostHog', 'OneUptime', 'Feedback', 'GitHub', 'Diagnostics'], true)) return;
            // Component names are developer-defined identifiers, never remote URLs or IDs.
            if (!in_array($action, ['dashboard-query', 'dashboard-refresh', 'connection-test', 'diagnostic-overview', 'heartbeat', 'overview', 'uptime', 'uptime-7d', 'incidents', 'announcements', 'scheduled-maintenance-events', 'alert-page-overview', 'alert-state', 'alert', 'release-check', 'package'], true)) return;
            $safe = [];
            if (isset($context['http_status']) && is_int($context['http_status']) && $context['http_status'] >= 100 && $context['http_status'] <= 599) {
                $safe['http_status'] = $context['http_status'];
            }
            $now = time();
            $rows = get_option(self::OPTION, []);
            $rows = is_array($rows) ? $rows : [];
            $rows = array_values(array_filter($rows, static function ($row) use ($now) {
                return is_array($row) && isset($row['last']) && is_int($row['last']) && $row['last'] >= $now - self::RETENTION;
            }));
            $fingerprint = hash('sha256', $source . '|' . $action . '|' . $code . '|' . wp_json_encode($safe));
            foreach ($rows as $index => $row) {
                if (($row['id'] ?? '') !== $fingerprint) continue;
                $row['last'] = $now;
                $row['count'] = min(999999, (int) ($row['count'] ?? 1) + 1);
                unset($rows[$index]);
                $rows[] = $row;
                update_option(self::OPTION, array_slice($rows, -self::MAX_ENTRIES), false);
                return;
            }
            $rows[] = ['id' => $fingerprint, 'first' => $now, 'last' => $now, 'count' => 1,
                'source' => $source, 'action' => $action, 'severity' => $severity, 'code' => $code, 'context' => $safe];
            update_option(self::OPTION, array_slice($rows, -self::MAX_ENTRIES), false);
        } catch (Throwable $ignored) {
            // Diagnostics must never interrupt an integration or page render.
        }
    }

    public static function recover($source, $action) {
        try {
            $rows = get_option(self::OPTION, []);
            if (!is_array($rows)) return;
            foreach (array_reverse($rows) as $row) {
                if (!is_array($row) || ($row['source'] ?? '') !== $source || ($row['action'] ?? '') !== $action) continue;
                if (($row['code'] ?? '') !== 'recovered') self::record($source, $action, 'info', 'recovered');
                return;
            }
        } catch (Throwable $ignored) {
        }
    }

    public static function entries() {
        if (!current_user_can('manage_options')) return [];
        try {
            $rows = get_option(self::OPTION, []);
            if (!is_array($rows)) return [];
            $now = time();
            return array_values(array_filter($rows, static function ($row) use ($now) {
                return is_array($row) && isset($row['last'], $row['first'], $row['count'], $row['source'], $row['action'], $row['severity'], $row['code']) &&
                    is_int($row['last']) && is_int($row['first']) && is_int($row['count']) &&
                    is_string($row['source']) && is_string($row['action']) && is_string($row['severity']) && is_string($row['code']) &&
                    $row['last'] >= $now - self::RETENTION;
            }));
        } catch (Throwable $ignored) {
            return [];
        }
    }

    public static function clear() {
        if (!current_user_can('manage_options')) return false;
        try {
            return delete_option(self::OPTION);
        } catch (Throwable $ignored) {
            return false;
        }
    }

    public static function description($code) {
        $labels = self::labels();
        return $labels[$code][0] ?? __('Okänt diagnostikfel.', 'arcwind-init');
    }

    public static function remedy($code) {
        $labels = self::labels();
        return $labels[$code][1] ?? __('Kontrollera integrationens inställningar.', 'arcwind-init');
    }
}
