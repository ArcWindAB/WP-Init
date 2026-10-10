<?php
/**
 * WordPress dashboard summary of the public OneUptime status page.
 */
if (!defined('ABSPATH')) exit;

class ArcWind_Init_OneUptime_Dashboard {
    const CACHE = 'arcwind_init_oneuptime_overview_v1';

    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
    }

    private function options() {
        return wp_parse_args((array) get_option(ArcWind_Init_OneUptime::KEY, []), [
            'enabled' => 0, 'status_url' => '', 'status_page_id' => '',
        ]);
    }

    public function register_widget() {
        $o = $this->options();
        if (!$o['enabled']) return;
        wp_add_dashboard_widget('arcwind_init_oneuptime_status', __('OneUptime – Driftstatus', 'arcwind-init'), [$this, 'render']);
    }

    private function overview($options) {
        $cached = get_transient(self::CACHE);
        if (is_array($cached)) return $cached;
        $url = wp_parse_url($options['status_url']);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) return new WP_Error('invalid_url', 'Ogiltig HTTPS-adress.');
        $host = 'https://' . $url['host'] . (!empty($url['port']) ? ':' . (int) $url['port'] : '');
        $endpoint = $host . '/status-page-api/overview/' . rawurlencode($options['status_page_id']);
        $res = wp_safe_remote_get($endpoint, ['timeout' => 8, 'redirection' => 0]);
        if (is_wp_error($res)) return $res;
        if (wp_remote_retrieve_response_code($res) !== 200) return new WP_Error('api_error', 'Statussidan kunde inte läsas (HTTP ' . wp_remote_retrieve_response_code($res) . ').');
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || !array_key_exists('overallStatus', $data)) return new WP_Error('invalid_response', 'OneUptime skickade ett oväntat svar.');
        $uptime = wp_safe_remote_post($host . '/status-page-api/uptime/' . rawurlencode($options['status_page_id']), [
            'timeout' => 8, 'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{}',
        ]);
        if (!is_wp_error($uptime) && wp_remote_retrieve_response_code($uptime) === 200) {
            $uptime_data = json_decode(wp_remote_retrieve_body($uptime), true);
            if (is_array($uptime_data)) $data['_arcwindUptime'] = $uptime_data;
        }
        set_transient(self::CACHE, $data, MINUTE_IN_SECONDS);
        return $data;
    }

    private function label($value, $fallback = 'Okänd') {
        if (is_string($value) && $value !== '') return $value;
        if (!is_array($value)) return $fallback;
        foreach (['name', 'displayName', 'title'] as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) return $value[$key];
        }
        return $fallback;
    }

    private function status($value) {
        if (!is_array($value)) return ['name' => 'Okänd status', 'color' => '#757575'];
        $color = $value['color']['value'] ?? $value['color'] ?? '';
        if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = !empty($value['isOperationalState']) ? '#237a45' : '#946200';
        }
        return ['name' => $this->label($value, 'Okänd status'), 'color' => $color];
    }

    private function colored_status($value) {
        $status = $this->status($value);
        echo '<span style="display:inline-flex;align-items:center;gap:6px">';
        echo '<span aria-hidden="true" style="width:10px;height:10px;border-radius:50%;background:' . esc_attr($status['color']) . ';display:inline-block"></span>';
        echo '<span>' . esc_html($status['name']) . '</span></span>';
    }

    private function records($data, $key, $title) {
        $records = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
        if (!$records) return;
        echo '<h4 style="margin:16px 0 6px">' . esc_html($title) . '</h4><ul style="margin:0">';
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            echo '<li style="padding:5px 0;border-bottom:1px solid #eee"><strong>' . esc_html($this->label($record)) . '</strong>';
            $state = $record['currentIncidentState'] ?? $record['currentScheduledMaintenanceState'] ?? null;
            if (is_array($state)) echo ' – ' . esc_html($this->label($state));
            echo '</li>';
        }
        echo '</ul>';
    }

    private function normalize_id($id) {
        if (is_array($id)) return (string) ($id['value'] ?? $id['_id'] ?? '');
        return is_scalar($id) ? (string) $id : '';
    }

    private function uptime_record($resource, $data) {
        $id = $this->normalize_id($resource['_id'] ?? $resource['id'] ?? '');
        if (!$id) return null;
        $uptime = $data['_arcwindUptime'] ?? [];
        $records = $uptime['statusPageResourceUptimes'] ?? [];
        foreach (($uptime['groupUptimes'] ?? []) as $group) {
            if (is_array($group)) $records = array_merge($records, $group['statusPageResourceUptimes'] ?? []);
        }
        foreach ($records as $record) {
            if (is_array($record) && $this->normalize_id($record['statusPageResourceId'] ?? '') === $id) return $record;
        }
        return null;
    }

    private function resource_status($resource, $data) {
        $record = $this->uptime_record($resource, $data);
        if ($record && !empty($record['currentStatus'])) return $record['currentStatus'];
        foreach (['currentStatus', 'currentMonitorStatus', 'monitorStatus'] as $key) {
            if (!empty($resource[$key]) && is_array($resource[$key])) return $resource[$key];
        }
        $monitor = $resource['monitor'] ?? null;
        if (is_array($monitor)) {
            foreach (['currentMonitorStatus', 'currentStatus'] as $key) {
                if (!empty($monitor[$key]) && is_array($monitor[$key])) return $monitor[$key];
            }
        }
        $group = $resource['monitorGroup'] ?? null;
        if (is_array($group)) {
            foreach (['currentMonitorStatus', 'currentStatus'] as $key) {
                if (!empty($group[$key]) && is_array($group[$key])) return $group[$key];
            }
        }
        return null;
    }

    private function render_resources($data) {
        $resources = $data['statusPageResources'] ?? [];
        if (!is_array($resources)) $resources = [];
        $groups = $data['resourceGroups'] ?? [];
        if (!is_array($groups)) $groups = [];
        echo '<h4 style="margin:16px 0 6px">Komponenter och tjänster</h4>';
        if (!$resources && !$groups) {
            echo '<p>Inga komponenter publicerade.</p>';
            return;
        }
        $by_group = [];
        foreach ($resources as $resource) {
            if (!is_array($resource)) continue;
            $group_id = $resource['statusPageGroupId']['value'] ?? $resource['statusPageGroupId'] ?? $resource['groupId']['value'] ?? $resource['groupId'] ?? '';
            if (!is_string($group_id)) $group_id = '';
            $by_group[$group_id][] = $resource;
        }
        $rendered_groups = [];
        foreach ($groups as $group) {
            if (!is_array($group)) continue;
            $id = $group['_id']['value'] ?? $group['_id'] ?? '';
            if (!is_string($id)) continue;
            $rendered_groups[$id] = true;
            echo '<h5 style="margin:12px 0 4px">' . esc_html($this->label($group, 'Grupp')) . '</h5>';
            $this->resource_rows($by_group[$id] ?? [], $data);
        }
        if (!empty($by_group[''])) $this->resource_rows($by_group[''], $data);
        foreach ($by_group as $id => $items) {
            if ($id !== '' && !isset($rendered_groups[$id])) $this->resource_rows($items, $data);
        }
    }

    private function resource_rows($resources, $data) {
        if (!$resources) return;
        echo '<ul style="margin:0">';
        foreach ($resources as $resource) {
            $name = $resource['displayName'] ?? $resource['name'] ?? $this->label($resource);
            echo '<li style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eee;padding:7px 0">';
            echo '<span>' . esc_html($name) . '</span>';
            $status = $this->resource_status($resource, $data);
            echo '<span style="text-align:right">';
            if ($status) $this->colored_status($status);
            else echo '–';
            $uptime = $this->uptime_record($resource, $data);
            if ($uptime && isset($uptime['uptimePercent']) && is_numeric($uptime['uptimePercent'])) {
                echo '<small style="display:block;color:#646970">' . esc_html(number_format_i18n((float) $uptime['uptimePercent'], 2)) . '% drifttid</small>';
            }
            echo '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }

    public function render() {
        $o = $this->options();
        if (!$o['status_url'] || !$o['status_page_id']) {
            echo '<p>Konfigurera statussidans URL och ID under <a href="' . esc_url(admin_url('options-general.php?page=arcwind_init&tab=oneuptime')) . '">ArcWind Init → OneUptime</a>.</p>';
            return;
        }
        $data = $this->overview($o);
        if (is_wp_error($data)) {
            echo '<p role="alert">' . esc_html($data->get_error_message()) . '</p>';
            return;
        }
        $page = $data['statusPage'] ?? [];
        $title = $this->label($page, 'OneUptime');
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px"><strong>' . esc_html($title) . '</strong>';
        echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url($o['status_url']) . '">Öppna statussidan ↗</a></div>';
        echo '<div style="padding:12px;background:#f6f7f7;border-radius:4px"><strong>Övergripande status: </strong>';
        if (!empty($data['overallStatus'])) $this->colored_status($data['overallStatus']);
        else echo 'Ingen status tillgänglig';
        echo '</div>';
        $this->render_resources($data);
        $this->records($data, 'activeIncidents', 'Aktiva incidenter');
        $this->records($data, 'activeEpisodes', 'Aktiva episoder');
        $this->records($data, 'scheduledMaintenanceEvents', 'Planerade underhåll');
        $this->records($data, 'activeAnnouncements', 'Meddelanden');
        echo '<p style="margin:12px 0 0;color:#646970">Statusdata uppdateras högst en gång per minut.</p>';
    }
}
