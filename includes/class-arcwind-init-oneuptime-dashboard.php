<?php
/**
 * WordPress dashboard summary of the public OneUptime status page.
 */
if (!defined('ABSPATH')) exit;

class ArcWind_Init_OneUptime_Dashboard {
    const CACHE = 'arcwind_init_oneuptime_overview_v2';
    const LAST_GOOD = 'arcwind_init_oneuptime_last_good_v2';
    const FAILURE_CACHE = 'arcwind_init_oneuptime_overview_failure_v2';
    const OVERVIEW_HOOK = 'arcwind_init_oneuptime_refresh_overview';
    const HISTORY_CACHE = 'arcwind_init_oneuptime_history_v1';
    const HISTORY_LAST_GOOD = 'arcwind_init_oneuptime_history_good_v1';
    const HISTORY_FAILURE = 'arcwind_init_oneuptime_history_failure_v1';
    const HISTORY_HOOK = 'arcwind_init_oneuptime_refresh_history';
    private $history_data = null;

    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
        add_action(self::OVERVIEW_HOOK, [$this, 'refresh_overview']);
        add_action(self::HISTORY_HOOK, [$this, 'refresh_history']);
    }

    private function options() {
        return ArcWind_Init_OneUptime::options();
    }

    public function register_widget() {
        $o = $this->options();
        if (!$o['enabled'] || !$o['status_page_id'] || !$o['status_url']) return;
        $title = __('Driftstatus', 'arcwind-init');
        if ($o['status_url'] && $o['status_page_id']) {
            $result = $this->overview($o);
            if (!is_wp_error($result) && isset($result['data']) && is_array($result['data'])) {
                $title = $this->label($result['data']['statusPage'] ?? null, $title);
            }
        }
        wp_add_dashboard_widget('arcwind_init_oneuptime_status', esc_html($title), [$this, 'render']);
    }

    private function overview($options) {
        $cached = get_transient(self::CACHE);
        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) return $cached;
        if (get_transient(self::FAILURE_CACHE) !== false) return $this->stale_overview();
        if (!wp_next_scheduled(self::OVERVIEW_HOOK)) {
            wp_schedule_single_event(time(), self::OVERVIEW_HOOK);
            if (function_exists('spawn_cron') && (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON)) spawn_cron(time());
        }
        return $this->stale_overview();
    }

    public function refresh_overview() {
        $options = $this->options();
        if (!$options['enabled'] || !$options['status_page_id'] || !$options['status_url']) return;
        $url = wp_parse_url($options['status_url']);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) return new WP_Error('invalid_url', 'Ogiltig HTTPS-adress.');
        $host = 'https://' . $url['host'] . (!empty($url['port']) ? ':' . (int) $url['port'] : '');
        $endpoint = $host . '/status-page-api/overview/' . rawurlencode($options['status_page_id']);
        $data = ArcWind_Init_OneUptime::request_api($endpoint, 'overview', 'GET', 5);
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
        $uptime = ArcWind_Init_OneUptime::request_api($host . '/status-page-api/uptime/' . rawurlencode($options['status_page_id']), 'uptime', 'POST', 5);
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
        return new WP_Error('api_unavailable', __('OneUptime är tillfälligt otillgängligt.', 'arcwind-init'));
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
        $payload = ArcWind_Init_OneUptime::request_api($endpoint, 'uptime-7d', 'POST', 5, [
            'startDate' => gmdate('c', $now - 7 * DAY_IN_SECONDS), 'endDate' => gmdate('c', $now),
        ]);
        if (is_wp_error($payload)) {
            set_transient(self::HISTORY_FAILURE, 1, MINUTE_IN_SECONDS);
            return;
        }
        $result = ['windows' => ['7d' => $payload], 'fetched_at' => $now, 'stale' => false];
        set_transient(self::HISTORY_CACHE, $result, 10 * MINUTE_IN_SECONDS);
        set_transient(self::HISTORY_LAST_GOOD, $result, DAY_IN_SECONDS);
    }

    private function label($value, $fallback = null) {
        if ($fallback === null) $fallback = __('Okänd', 'arcwind-init');
        if (is_string($value) && $value !== '') return $value;
        if (!is_array($value)) return $fallback;
        foreach (['name', 'displayName', 'title'] as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) return $value[$key];
        }
        return $fallback;
    }

    private function status($value) {
        if (!is_array($value)) return ['name' => __('Okänd status', 'arcwind-init'), 'color' => '#757575'];
        $color = $value['color']['value'] ?? $value['color'] ?? '';
        if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = !empty($value['isOperationalState']) ? '#237a45' : '#946200';
        }
        return ['name' => $this->label($value, __('Okänd status', 'arcwind-init')), 'color' => $color];
    }

    private function colored_status($value, $stale = false) {
        $status = $this->status($value);
        if ($stale) {
            $status['color'] = '#757575';
            $status['name'] .= ' (' . __('ej aktuell', 'arcwind-init') . ')';
        }
        echo '<span style="display:inline-flex;align-items:center;gap:6px">';
        echo '<span aria-hidden="true" style="width:10px;height:10px;border-radius:50%;background:' . esc_attr($status['color']) . ';display:inline-block"></span>';
        echo '<span>' . esc_html($status['name']) . '</span></span>';
    }

    private function records($data, $key, $title) {
        $records = ArcWind_Init_OneUptime::api_records($data, $key);
        if (!$records) return;
        echo '<details style="border-top:1px solid #dcdcde;padding:8px 0"><summary style="cursor:pointer;font-weight:600">' . esc_html($title) . ' (' . count($records) . ')</summary><ul style="margin:8px 0 0 18px">';
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            echo '<li style="padding:5px 0;border-bottom:1px solid #eee"><strong>' . esc_html($this->label($record)) . '</strong>';
            $state = $record['currentIncidentState'] ?? $record['currentScheduledMaintenanceState'] ?? null;
            if (is_array($state)) echo ' – ' . esc_html($this->label($state));
            echo '</li>';
        }
        echo '</ul></details>';
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
        $payload = $this->history_data['windows']['7d'] ?? null;
        $wrapped = is_array($payload) ? ['_arcwindUptime' => $payload] : [];
        $record = $is_group ? $this->group_uptime_record($this->normalize_id($entity['_id'] ?? $entity['id'] ?? ''), $wrapped) : $this->uptime_record($entity, $wrapped);
        $value = is_array($record) ? ($record['uptimePercent'] ?? null) : null;
        echo '<small style="display:block;color:#646970">' . esc_html__('Senaste 7 dagarna:', 'arcwind-init') . ' ';
        if (is_scalar($value) && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 100) {
            echo esc_html(number_format_i18n((float) $value, 2)) . '% ' . esc_html__('drifttid', 'arcwind-init');
        } else echo esc_html__('uppgift saknas', 'arcwind-init');
        echo '</small>';
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
        echo '<details open style="border-top:1px solid #dcdcde;padding:8px 0"><summary style="cursor:pointer;font-weight:600">' . esc_html__('Komponenter och tjänster', 'arcwind-init') . '</summary>';
        if (!$resources && !$groups) {
            echo '<p style="margin:8px 0 0 18px">' . esc_html__('Inga komponenter publicerade.', 'arcwind-init') . '</p></details>';
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
        echo '</details>';
    }

    private function render_group($id, $group_map, $children, $by_group, $data, $stale, &$visited, $depth) {
        if (isset($visited[$id]) || $depth > 20) return;
        $visited[$id] = true;
        $group = $group_map[$id];
        echo '<details style="margin:6px 0 6px 16px;border-left:2px solid #dcdcde;padding-left:8px"><summary style="cursor:pointer;font-weight:600">' . esc_html($this->label($group, __('Grupp', 'arcwind-init')));
        $uptime = $this->group_uptime_record($id, $data);
        if ($uptime && isset($uptime['currentStatus']) && is_array($uptime['currentStatus'])) {
            echo ' – ';
            $this->colored_status($uptime['currentStatus'], $stale);
        }
        echo '</summary>';
        $this->render_history($group, true);
        $this->resource_rows($by_group[$id] ?? [], $data, $stale);
        foreach ($children[$id] ?? [] as $child) $this->render_group($child, $group_map, $children, $by_group, $data, $stale, $visited, $depth + 1);
        echo '</details>';
    }

    private function resource_rows($resources, $data, $stale = false) {
        if (!$resources) return;
        echo '<div style="margin-left:16px">';
        foreach ($resources as $resource) {
            $name = $this->label($resource['displayName'] ?? null, $this->label($resource['name'] ?? null, $this->label($resource)));
            echo '<div style="border-bottom:1px solid #eee;padding:7px 0"><div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:4px 12px">';
            echo '<span style="min-width:0;overflow-wrap:anywhere">' . esc_html($name) . '</span>';
            $status = $this->resource_status($resource, $data);
            echo '<span>';
            if ($status) $this->colored_status($status, $stale);
            else echo '–';
            echo '</span></div>';
            $this->render_history($resource);
            echo '</div>';
        }
        echo '</div>';
    }

    public function render() {
        $o = $this->options();
        if (!$o['status_url'] || !$o['status_page_id']) {
            echo '<p>' . esc_html__('Konfigurera statussidans adress och ID under', 'arcwind-init') . ' <a href="' . esc_url(admin_url('options-general.php?page=arcwind_init&tab=oneuptime')) . '">' . esc_html__('statusinställningarna', 'arcwind-init') . '</a>.</p>';
            return;
        }
        $result = $this->overview($o);
        if (is_wp_error($result)) {
            echo '<p role="alert">' . esc_html__('Statusdata är tillfälligt otillgängliga.', 'arcwind-init') . '</p>';
            return;
        }
        $data = $result['data'];
        $stale = !empty($result['stale']);
        $this->history_data = $this->history($o);
        if ($stale) echo '<p role="status">' . esc_html__('Statussidan kan inte nås. Senast kända data från', 'arcwind-init') . ' ' . esc_html(wp_date('Y-m-d H:i', $result['fetched_at'])) . ' ' . esc_html__('visas nedan; statusen är inte aktuell.', 'arcwind-init') . '</p>';
        if ($this->history_data === null) echo '<p>' . esc_html__('Historisk drifttid hämtas i bakgrunden.', 'arcwind-init') . '</p>';
        elseif (!empty($this->history_data['stale'])) echo '<p>' . esc_html__('Historisk drifttid är från en tidigare hämtning.', 'arcwind-init') . '</p>';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px"><strong>' . esc_html($stale ? __('Senast kända status', 'arcwind-init') : __('Aktuell status', 'arcwind-init')) . '</strong>';
        if (!empty($data['overallStatus'])) $this->colored_status($data['overallStatus'], $stale);
        else echo esc_html__('Ingen status tillgänglig', 'arcwind-init');
        echo '</div>';
        $this->render_resources($data, $stale);
        $this->records($data, 'activeIncidents', __('Aktiva incidenter', 'arcwind-init'));
        $this->records($data, 'activeEpisodes', __('Aktiva episoder', 'arcwind-init'));
        $this->records($data, 'scheduledMaintenanceEvents', __('Planerade underhåll', 'arcwind-init'));
        $this->records($data, 'activeAnnouncements', __('Meddelanden', 'arcwind-init'));
        echo '<p style="margin:12px 0 0;color:#646970;font-size:12px">' . esc_html__('Uppdaterad', 'arcwind-init') . ' ' . esc_html(wp_date('Y-m-d H:i', $result['fetched_at'])) . ' · <a target="_blank" rel="noopener noreferrer" href="' . esc_url($o['status_url']) . '">' . esc_html__('Öppna statussidan ↗', 'arcwind-init') . '</a></p>';
    }
}
