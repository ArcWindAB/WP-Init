<?php
/** GitHub Releases updater for ArcWind Init. */
if (!defined('ABSPATH')) exit;

class ArcWind_Init_GitHub_Updater {
    const GITHUB_REPO = 'ArcWindAB/WP-Init';
    const CACHE_KEY   = 'arcwind_init_github_release';
    const CACHE_TTL   = 3600;
    const FAILURE_TTL = 900;

    private $plugin_file;
    private $plugin_basename;
    private $plugin_slug;
    private $current_version;

    public function __construct($plugin_file) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);

        $this->plugin_slug = dirname($this->plugin_basename);
        if ($this->plugin_slug === '.' || $this->plugin_slug === '/') {
            $this->plugin_slug = basename($this->plugin_basename, '.php');
        }

        $plugin_data = get_file_data($plugin_file, [
            'Version' => 'Version',
        ], 'plugin');

        $this->current_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

        add_filter('update_plugins_github.com', [$this, 'filter_update'], 10, 4);
        add_filter('plugins_api', [$this, 'filter_plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_directory_name'], 10, 4);
    }

    public function filter_update($update, $plugin_data, $plugin_file, $locales) {
        if ($plugin_file !== $this->plugin_basename) {
            return $update;
        }

        $release = $this->get_latest_release();
        $version = !empty($release['version']) && is_string($release['version']) ? $release['version'] : $this->current_version;
        $package = !empty($release['package']) && is_string($release['package']) ? $release['package'] : '';
        if ($package === '' || version_compare($version, $this->current_version, '<=')) $version = $this->current_version;

        return [
            'id'          => 'https://github.com/' . self::GITHUB_REPO,
            'slug'        => $this->plugin_slug,
            'version'     => $version,
            'url'         => 'https://github.com/' . self::GITHUB_REPO,
            'package'     => $version === $this->current_version ? '' : $package,
            'tested'      => '',
            'requires_php'=> '',
        ];
    }

    public function filter_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        $info = new stdClass();
        $info->name          = 'ArcWind Init';
        $info->slug          = $this->plugin_slug;
        $info->version       = !empty($release['version']) ? $release['version'] : $this->current_version;
        $info->author        = '<a href="https://arcwind.se">ArcWind AB</a>';
        $info->homepage      = 'https://github.com/' . self::GITHUB_REPO;
        $info->requires      = '';
        $info->tested        = '';
        $info->requires_php  = '';
        $info->download_link = !empty($release['package']) ? $release['package'] : '';
        $changelog = !empty($release['changelog'])
            ? '<p>' . wp_kses_post($release['changelog']) . '</p>'
            : '<p>Versionsnyheter finns under <a href="https://github.com/' . self::GITHUB_REPO . '/releases">GitHub Releases</a>.</p>';
        $info->sections      = [
            'description' => '<p>ArcWind Init samlar PostHog, feedback och OneUptime i WordPress. Varje del kan konfigureras separat under <strong>Inställningar → ArcWind Init</strong>.</p>' .
                '<ul>' .
                '<li><strong>PostHog:</strong> Spåra sidvisningar och händelser på webbplatsen och i admin. Välj EU/US och eventuell reverse proxy. En separat statistikwidget visar aktiva användare och sidvisningar för de senaste sju dagarna när projekt-ID och personlig API-nyckel finns.</li>' .
                '<li><strong>Feedback:</strong> Visa en länk till ert feedbackformulär på WordPress dashboard när ett feedback-UUID har angetts.</li>' .
                '<li><strong>OneUptime / Driftstatus:</strong> Visa incidenter, meddelanden och planerade underhåll på webbplatsen eller i admin. Statuswidgeten visar statussidans namn och drifttid för de senaste sju dagarna. Valfria alerts visas i admin för monitorerna på statussidan; heartbeat skickas via WP-Cron till en Incoming Request-monitor.</li>' .
                '</ul>' .
                '<p>Statistik-, feedback- och statuskort visas först när respektive underlag är konfigurerat. Alerts kräver en OneUptime API-nyckel och visas bara för administratörer.</p>',
            'installation' => '<ol>' .
                '<li>Installera ZIP-filen <code>arcwind-init.zip</code> från en GitHub Release och aktivera tillägget.</li>' .
                '<li>Öppna <strong>Inställningar → ArcWind Init</strong>. Fyll i bara de integrationer du vill använda.</li>' .
                '<li>För PostHog: ange projekt-token för spårning. Lägg till projekt-ID och personlig API-nyckel om du vill visa dashboardstatistik.</li>' .
                '<li>För OneUptime: ange värd och statussidans ID. Skapa en Incoming Request-monitor om du vill skicka heartbeat.</li>' .
                '<li>Kontrollera status och testa anslutningarna på fliken <strong>Diagnostik</strong>.</li>' .
                '</ol><p>Tillägget söker uppdateringar bland publicerade GitHub Releases. WordPress automatiska uppdateringar kan aktiveras på sidan Installerade tillägg.</p>',
            'faq' => '<h3>Behöver jag konfigurera alla tre delar?</h3><p>Nej. Statistik- och feedbackkort visas när deras egna uppgifter finns; OneUptimes statuskort kräver värd och status-ID.</p>' .
                '<h3>Vilken PostHog-adress används?</h3><p><code>api_host</code> används för webbspårning och serverhändelser. <code>ui_host</code>, eller vald regions PostHog-adress, används för privata statistikfrågor.</p>' .
                '<h3>Behöver OneUptime en API-nyckel?</h3><p>Den publika statussidan och heartbeat kräver ingen API-nyckel. Adminvisning av alerts kräver en nyckel med läsbehörighet för Alert och Alert State.</p>' .
                '<h3>Vad händer om en extern tjänst är nere?</h3><p>WordPress kan fortfarande laddas. Statuskortet kan visa senast kända data med märkningen ”ej aktuell”, och statistiken kan visa tomma värden.</p>' .
                '<h3>Var finns mer dokumentation?</h3><p>Se <a href="https://github.com/' . self::GITHUB_REPO . '#readme">projektets README på GitHub</a>.</p>',
            'changelog' => $changelog,
        ];

        return $info;
    }

    public function fix_directory_name($source, $remote_source, $upgrader, $hook_extra) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }

        if (!is_string($source) || !is_file(trailingslashit($source) . 'arcwind-init.php')) {
            return new WP_Error('invalid_package', 'Uppdateringspaketet saknar pluginfilen.');
        }
        $header = get_file_data(trailingslashit($source) . 'arcwind-init.php', ['Version' => 'Version'], 'plugin');
        if (empty($header['Version']) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $header['Version'])) {
            return new WP_Error('invalid_package', 'Uppdateringspaketet har ogiltig version.');
        }
        // A manual ZIP install may intentionally restore an older version. Match the
        // cached release only for WordPress update jobs, never for manual recovery.
        $release = get_transient(self::CACHE_KEY);
        if (($hook_extra['action'] ?? '') === 'update' && is_array($release) &&
            !empty($release['version']) && $header['Version'] !== $release['version']) {
            return new WP_Error('invalid_package', 'Uppdateringspaketets version stämmer inte med releasen.');
        }
        $desired = trailingslashit(WP_PLUGIN_DIR . '/' . $this->plugin_slug);

        if (wp_normalize_path($source) === wp_normalize_path($desired)) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return new WP_Error('filesystem_unavailable', 'WordPress filsystem är inte tillgängligt.');
        }

        if ($wp_filesystem->move($source, $desired, true)) {
            return $desired;
        }

        return new WP_Error('invalid_package', 'Uppdateringspaketet kunde inte installeras.');
    }

    private function get_latest_release() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) return is_array($cached) ? $cached : [];

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

        try {
            $response = wp_safe_remote_get($url, [
                'timeout' => 5,
                'redirection' => 0,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'ArcWind-Init/' . $this->current_version,
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]);
        } catch (Throwable $error) {
            $response = new WP_Error('request_exception', 'GitHub request failed');
        }

        if (is_wp_error($response) || !is_array($response)) {
            set_transient(self::CACHE_KEY, [], self::FAILURE_TTL);
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200 || !is_string($body) || $body === '') {
            set_transient(self::CACHE_KEY, [], self::FAILURE_TTL);
            return [];
        }

        $json = json_decode($body, true);
        $release = self::parse_release($json);
        if (!$release) {
            set_transient(self::CACHE_KEY, [], self::FAILURE_TTL);
            return [];
        }

        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    public static function parse_release($json) {
        if (!is_array($json) || !empty($json['draft']) || !empty($json['prerelease'])) return [];
        $tag_name = isset($json['tag_name']) && is_string($json['tag_name']) ? trim($json['tag_name']) : '';
        $version = ltrim($tag_name, 'vV');
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) return [];

        $package = '';
        if (isset($json['assets']) && is_array($json['assets'])) {
            foreach ($json['assets'] as $asset) {
                if (!is_array($asset) || ($asset['name'] ?? null) !== 'arcwind-init.zip') continue;
                $url = $asset['browser_download_url'] ?? null;
                if (self::valid_package_url($url)) $package = $url;
                break;
            }
        }
        if ($package === '') return [];
        return [
            'version'   => $version,
            'package'   => $package,
            'changelog' => isset($json['body']) && is_string($json['body']) ? nl2br(esc_html($json['body'])) : '',
            'tag_name'  => $tag_name,
        ];
    }

    public static function valid_package_url($url) {
        if (!is_string($url)) return false;
        $parts = wp_parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'github.com' &&
            !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']) &&
            (bool) preg_match('~^/ArcWindAB/WP-Init/releases/download/[^/]+/arcwind-init\.zip$~', $parts['path'] ?? '');
    }
}
