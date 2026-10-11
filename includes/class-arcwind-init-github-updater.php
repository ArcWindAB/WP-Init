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
        if (empty($release)) {
            return $result;
        }

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
        $info->sections      = [
            'description' => 'ArcWind Init för PostHog, dashboard-widget och feedback-widget.',
            'changelog'   => !empty($release['changelog']) ? wp_kses_post($release['changelog']) : 'Ingen changelog tillgänglig.',
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
