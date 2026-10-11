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
        $options = wp_parse_args((array) get_option(ArcWind_Init_OneUptime::KEY, []), [
            'enabled' => 0, 'status_url' => '', 'status_page_id' => '',
        ]);
        foreach (['status_url', 'status_page_id'] as $key) {
            $options[$key] = ArcWind_Init_OneUptime::api_text($options[$key]);
        }
        return $options;
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
        $data = ArcWind_Init_OneUptime::request_api($endpoint, 'overview');
        if (is_wp_error($data)) return $data;
        if (!array_key_exists('overallStatus', $data)) {
            ArcWind_Init_OneUptime::api_diagnostic('overview', 'missing overallStatus');
            return new WP_Error('invalid_response', 'OneUptime skickade ett oväntat svar.');
        }
        foreach (['statusPageResources', 'resourceGroups', 'activeIncidents', 'activeEpisodes', 'scheduledMaintenanceEvents', 'activeAnnouncements'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) ArcWind_Init_OneUptime::api_diagnostic('overview', 'invalid ' . $key);
        }
        if ($data['overallStatus'] !== null && !is_array($data['overallStatus'])) {
            ArcWind_Init_OneUptime::api_diagnostic('overview', 'invalid overallStatus');
        }
        $uptime = ArcWind_Init_OneUptime::request_api($host . '/status-page-api/uptime/' . rawurlencode($options['status_page_id']), 'uptime', 'POST');
        if (!is_wp_error($uptime)) $data['_arcwindUptime'] = $uptime;
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
        return ArcWind_Init_OneUptime::api_id($id);
    }

    private function uptime_record($resource, $data) {
        $id = $this->normalize_id($resource['_id'] ?? $resource['id'] ?? '');
        if (!$id) return null;
        $uptime = isset($data['_arcwindUptime']) && is_array($data['_arcwindUptime']) ? $data['_arcwindUptime'] : [];
        $records = ArcWind_Init_OneUptime::api_records($uptime, 'statusPageResourceUptimes');
        foreach (ArcWind_Init_OneUptime::api_records($uptime, 'groupUptimes') as $group) {
            $records = array_merge($records, ArcWind_Init_OneUptime::api_records($group, 'statusPageResourceUptimes'));
        }
        foreach ($records as $record) {
            if (is_array($record) && $this->normalize_id($record['statusPageResourceId'] ?? '') === $id) return $record;
        }
        return null;
    }

    private function resource_status($resource, $data) {
        $record = $this->uptime_record($resource, $data);
        if ($record && !empty($record['currentStatus']) && is_array($record['currentStatus'])) return $record['currentStatus'];
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
        $resources = ArcWind_Init_OneUptime::api_records($data, 'statusPageResources');
        $groups = ArcWind_Init_OneUptime::api_records($data, 'resourceGroups');
        echo '<h4 style="margin:16px 0 6px">Komponenter och tjänster</h4>';
        if (!$resources && !$groups) {
            echo '<p>Inga komponenter publicerade.</p>';
            return;
        }
        $by_group = [];
        foreach ($resources as $resource) {
            $group_id = $this->normalize_id($resource['statusPageGroupId'] ?? $resource['groupId'] ?? '');
            $by_group[$group_id][] = $resource;
        }
        $rendered_groups = [];
        foreach ($groups as $group) {
            $id = $this->normalize_id($group['_id'] ?? $group['id'] ?? '');
            if ($id === '') continue;
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
            $name = $this->label($resource['displayName'] ?? null, $this->label($resource['name'] ?? null, $this->label($resource)));
            echo '<li style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eee;padding:7px 0">';
            echo '<span>' . esc_html($name) . '</span>';
            $status = $this->resource_status($resource, $data);
            echo '<span style="text-align:right">';
            if ($status) $this->colored_status($status);
            else echo '–';
            $uptime = $this->uptime_record($resource, $data);
            if ($uptime && isset($uptime['uptimePercent']) && is_scalar($uptime['uptimePercent']) && is_numeric($uptime['uptimePercent'])) {
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
