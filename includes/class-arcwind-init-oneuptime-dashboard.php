<?php
/**
 * WordPress dashboard summary of the public OneUptime status page.
 */
if (!defined('ABSPATH')) exit;

class ArcWind_Init_OneUptime_Dashboard {
    const CACHE = 'arcwind_init_oneuptime_overview_v2';
    const LAST_GOOD = 'arcwind_init_oneuptime_last_good_v2';
    const FAILURE_CACHE = 'arcwind_init_oneuptime_overview_failure_v2';
    const HISTORY_CACHE = 'arcwind_init_oneuptime_history_v1';
    const HISTORY_LAST_GOOD = 'arcwind_init_oneuptime_history_good_v1';
    const HISTORY_FAILURE = 'arcwind_init_oneuptime_history_failure_v1';
    const HISTORY_HOOK = 'arcwind_init_oneuptime_refresh_history';
    private $history_data = null;

    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
        add_action(self::HISTORY_HOOK, [$this, 'refresh_history']);
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
        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) return $cached;
        if (get_transient(self::FAILURE_CACHE) !== false) return $this->stale_overview();
        $url = wp_parse_url($options['status_url']);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) return new WP_Error('invalid_url', 'Ogiltig HTTPS-adress.');
        $host = 'https://' . $url['host'] . (!empty($url['port']) ? ':' . (int) $url['port'] : '');
        $endpoint = $host . '/status-page-api/overview/' . rawurlencode($options['status_page_id']);
        $data = ArcWind_Init_OneUptime::request_api($endpoint, 'overview');
        if (is_wp_error($data)) {
            set_transient(self::FAILURE_CACHE, 1, MINUTE_IN_SECONDS);
            return $this->stale_overview();
        }
        if (!array_key_exists('overallStatus', $data)) {
            ArcWind_Init_OneUptime::api_diagnostic('overview', 'missing overallStatus');
            set_transient(self::FAILURE_CACHE, 1, MINUTE_IN_SECONDS);
            return $this->stale_overview();
        }
        foreach (['statusPageResources', 'resourceGroups', 'activeIncidents', 'activeEpisodes', 'scheduledMaintenanceEvents', 'activeAnnouncements'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) ArcWind_Init_OneUptime::api_diagnostic('overview', 'invalid ' . $key);
        }
        if ($data['overallStatus'] !== null && !is_array($data['overallStatus'])) {
            ArcWind_Init_OneUptime::api_diagnostic('overview', 'invalid overallStatus');
        }
        $uptime = ArcWind_Init_OneUptime::request_api($host . '/status-page-api/uptime/' . rawurlencode($options['status_page_id']), 'uptime', 'POST');
        if (!is_wp_error($uptime)) $data['_arcwindUptime'] = $uptime;
        $result = ['data' => $data, 'fetched_at' => time(), 'stale' => false];
        set_transient(self::CACHE, $result, MINUTE_IN_SECONDS);
        set_transient(self::LAST_GOOD, $result, DAY_IN_SECONDS);
        return $result;
    }

    private function stale_overview() {
        $last_good = get_transient(self::LAST_GOOD);
        if (is_array($last_good) && isset($last_good['data'], $last_good['fetched_at']) && is_array($last_good['data']) && is_int($last_good['fetched_at'])) {
            $last_good['stale'] = true;
            return $last_good;
        }
        return new WP_Error('api_unavailable', 'OneUptime är tillfälligt otillgängligt.');
    }

    private function history($options) {
        $cached = get_transient(self::HISTORY_CACHE);
        if (is_array($cached) && isset($cached['windows']) && is_array($cached['windows'])) return $cached;
        if (get_transient(self::HISTORY_FAILURE) === false && !wp_next_scheduled(self::HISTORY_HOOK)) {
            wp_schedule_single_event(time() + 5, self::HISTORY_HOOK);
        }
        $last_good = get_transient(self::HISTORY_LAST_GOOD);
        if (is_array($last_good) && isset($last_good['windows']) && is_array($last_good['windows'])) {
            $last_good['stale'] = true;
            return $last_good;
        }
        return null;
    }

    public function refresh_history() {
        $options = $this->options();
        if (empty($options['enabled']) || !$options['status_url'] || !$options['status_page_id']) return;
        $url = wp_parse_url($options['status_url']);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) return;
        $host = 'https://' . $url['host'] . (!empty($url['port']) ? ':' . (int) $url['port'] : '');
        $endpoint = $host . '/status-page-api/uptime/' . rawurlencode($options['status_page_id']);
        $now = time();
        $windows = [];
        foreach (['24h' => DAY_IN_SECONDS, '7d' => 7 * DAY_IN_SECONDS, '30d' => 30 * DAY_IN_SECONDS] as $name => $seconds) {
            $payload = ArcWind_Init_OneUptime::request_api($endpoint, 'uptime-' . $name, 'POST', 5, [
                'startDate' => gmdate('c', $now - $seconds), 'endDate' => gmdate('c', $now),
            ]);
            if (!is_wp_error($payload)) $windows[$name] = $payload;
        }
        if (!$windows) {
            set_transient(self::HISTORY_FAILURE, 1, MINUTE_IN_SECONDS);
            return;
        }
        $result = ['windows' => $windows, 'fetched_at' => $now, 'stale' => false];
        set_transient(self::HISTORY_CACHE, $result, 10 * MINUTE_IN_SECONDS);
        set_transient(self::HISTORY_LAST_GOOD, $result, DAY_IN_SECONDS);
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

    private function colored_status($value, $stale = false) {
        $status = $this->status($value);
        if ($stale) {
            $status['color'] = '#757575';
            $status['name'] .= ' (ej aktuell)';
        }
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

    private function group_uptime_record($id, $data) {
        $uptime = isset($data['_arcwindUptime']) && is_array($data['_arcwindUptime']) ? $data['_arcwindUptime'] : [];
        foreach (ArcWind_Init_OneUptime::api_records($uptime, 'groupUptimes') as $record) {
            if ($this->normalize_id($record['statusPageGroupId'] ?? '') === $id) return $record;
        }
        return null;
    }

    private function render_history($entity, $is_group = false) {
        if (!is_array($this->history_data) || empty($this->history_data['windows'])) return;
        echo '<div style="display:flex;flex-wrap:wrap;gap:4px 8px;font-size:11px;color:#646970">';
        foreach (['24h' => '24 timmar', '7d' => '7 dagar', '30d' => '30 dagar'] as $key => $label) {
            $payload = $this->history_data['windows'][$key] ?? null;
            $wrapped = is_array($payload) ? ['_arcwindUptime' => $payload] : [];
            $record = $is_group ? $this->group_uptime_record($this->normalize_id($entity['_id'] ?? $entity['id'] ?? ''), $wrapped) : $this->uptime_record($entity, $wrapped);
            $value = is_array($record) ? ($record['uptimePercent'] ?? null) : null;
            echo '<span>' . esc_html($label) . ': ';
            if (is_scalar($value) && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 100) {
                $percent = (float) $value;
                echo '<span role="progressbar" aria-label="Drifttid ' . esc_attr($label) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr((string) $percent) . '" style="display:inline-block;width:46px;height:7px;background:#dcdcde;vertical-align:middle"><span style="display:block;width:' . esc_attr((string) $percent) . '%;height:100%;background:#237a45"></span></span> ';
                echo esc_html(number_format_i18n($percent, 2)) . '%';
            } else echo 'saknas';
            echo '</span>';
        }
        echo '</div>';
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

    private function render_resources($data, $stale = false) {
        $resources = ArcWind_Init_OneUptime::api_records($data, 'statusPageResources');
        $groups = ArcWind_Init_OneUptime::api_records($data, 'resourceGroups');
        echo '<h4 style="margin:16px 0 6px">Komponenter och tjänster</h4>';
        if (!$resources && !$groups) {
            echo '<p>Inga komponenter publicerade.</p>';
            return;
        }
        $by_group = [];
        $seen_resources = [];
        foreach ($resources as $resource) {
            $resource_id = $this->normalize_id($resource['_id'] ?? $resource['id'] ?? '');
            if ($resource_id !== '') {
                if (isset($seen_resources[$resource_id])) continue;
                $seen_resources[$resource_id] = true;
            }
            $group_id = $this->normalize_id($resource['statusPageGroupId'] ?? $resource['groupId'] ?? '');
            $by_group[$group_id][] = $resource;
        }
        $group_map = [];
        foreach ($groups as $group) {
            $id = $this->normalize_id($group['_id'] ?? $group['id'] ?? '');
            if ($id !== '' && !isset($group_map[$id])) $group_map[$id] = $group;
        }
        if (!empty($by_group[''])) $this->resource_rows($by_group[''], $data, $stale);
        $children = [];
        $roots = [];
        foreach ($group_map as $id => $group) {
            $parent = $this->normalize_id($group['parentStatusPageGroupId'] ?? $group['parentGroupId'] ?? '');
            if ($parent !== '' && $parent !== $id && isset($group_map[$parent])) $children[$parent][] = $id;
            else $roots[] = $id;
        }
        $visited = [];
        foreach ($roots as $id) $this->render_group($id, $group_map, $children, $by_group, $data, $stale, $visited, 0);
        foreach ($group_map as $id => $group) {
            if (!isset($visited[$id])) $this->render_group($id, $group_map, $children, $by_group, $data, $stale, $visited, 0);
        }
        foreach ($by_group as $id => $items) {
            if ($id !== '' && !isset($group_map[$id])) $this->resource_rows($items, $data, $stale);
        }
    }

    private function render_group($id, $group_map, $children, $by_group, $data, $stale, &$visited, $depth) {
        if (isset($visited[$id]) || $depth > 20) return;
        $visited[$id] = true;
        $group = $group_map[$id];
        $indent = min($depth, 5) * 16;
        echo '<h5 style="margin:12px 0 4px;padding-left:' . (int) $indent . 'px">' . esc_html($this->label($group, 'Grupp'));
        $uptime = $this->group_uptime_record($id, $data);
        if ($uptime && isset($uptime['currentStatus']) && is_array($uptime['currentStatus'])) {
            echo ' – ';
            $this->colored_status($uptime['currentStatus'], $stale);
        }
        echo '</h5>';
        $this->render_history($group, true);
        $this->resource_rows($by_group[$id] ?? [], $data, $stale, $indent);
        foreach ($children[$id] ?? [] as $child) $this->render_group($child, $group_map, $children, $by_group, $data, $stale, $visited, $depth + 1);
    }

    private function resource_rows($resources, $data, $stale = false, $indent = 0) {
        if (!$resources) return;
        echo '<ul style="margin:0;padding-left:' . (int) $indent . 'px">';
        foreach ($resources as $resource) {
            $name = $this->label($resource['displayName'] ?? null, $this->label($resource['name'] ?? null, $this->label($resource)));
            echo '<li style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:4px 12px;border-bottom:1px solid #eee;padding:7px 0">';
            echo '<span style="min-width:0;overflow-wrap:anywhere">' . esc_html($name) . '</span>';
            $status = $this->resource_status($resource, $data);
            echo '<span style="text-align:right">';
            if ($status) $this->colored_status($status, $stale);
            else echo '–';
            $uptime = $this->uptime_record($resource, $data);
            if ($uptime && isset($uptime['uptimePercent']) && is_scalar($uptime['uptimePercent']) && is_numeric($uptime['uptimePercent'])) {
                echo '<small style="display:block;color:#646970">' . esc_html(number_format_i18n((float) $uptime['uptimePercent'], 2)) . '% drifttid</small>';
            } else echo '<small style="display:block;color:#646970">Drifttid: uppgift saknas</small>';
            $this->render_history($resource);
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
        $result = $this->overview($o);
        if (is_wp_error($result)) {
            echo '<p role="alert">' . esc_html($result->get_error_message()) . '</p>';
            return;
        }
        $data = $result['data'];
        $stale = !empty($result['stale']);
        $this->history_data = $this->history($o);
        if ($stale) echo '<p role="status">OneUptime kan inte nås. Nedan visas senast kända data från ' . esc_html(wp_date('Y-m-d H:i', $result['fetched_at'])) . '; statusen är inte aktuell.</p>';
        if ($this->history_data === null) echo '<p>Historisk drifttid hämtas i bakgrunden.</p>';
        elseif (!empty($this->history_data['stale'])) echo '<p>Historisk drifttid är från en tidigare hämtning.</p>';
        $page = $data['statusPage'] ?? [];
        $title = $this->label($page, 'OneUptime');
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px"><strong>' . esc_html($title) . '</strong>';
        echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url($o['status_url']) . '">Öppna statussidan ↗</a></div>';
        echo '<div style="padding:12px;background:#f6f7f7;border-radius:4px"><strong>' . ($stale ? 'Senast kända status: ' : 'Övergripande status: ') . '</strong>';
        if (!empty($data['overallStatus'])) $this->colored_status($data['overallStatus'], $stale);
        else echo 'Ingen status tillgänglig';
        echo '</div>';
        $this->render_resources($data, $stale);
        $this->records($data, 'activeIncidents', 'Aktiva incidenter');
        $this->records($data, 'activeEpisodes', 'Aktiva episoder');
        $this->records($data, 'scheduledMaintenanceEvents', 'Planerade underhåll');
        $this->records($data, 'activeAnnouncements', 'Meddelanden');
        echo '<p style="margin:12px 0 0;color:#646970">Statusdata uppdateras högst en gång per minut. Senaste hämtning: ' . esc_html(wp_date('Y-m-d H:i', $result['fetched_at'])) . '.</p>';
    }
}
