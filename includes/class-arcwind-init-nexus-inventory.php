<?php
/**
 * Optional technical inventory reporting. Deployment secrets never enter options.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ArcWind_Init_Nexus_Inventory {
    const HOOK = 'arcwind_init_nexus_inventory';
    const RETRY_HOOK = 'arcwind_init_nexus_inventory_retry';
    const STATE = 'arcwind_init_nexus_inventory_state';
    const LOCK = 'arcwind_init_nexus_inventory_lock';

    private $config_path;

    public function __construct( $config_path = null ) {
        $this->config_path = $config_path;
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::HOOK, [ $this, 'run' ] );
        add_action( self::RETRY_HOOK, [ $this, 'run' ] );
        add_action( 'deactivated_plugin', [ $this, 'plugin_deactivated' ], 10, 1 );
        foreach ( [ 'upgrader_process_complete', 'activated_plugin', 'switch_theme', 'wp_version_check', 'set_site_transient_update_plugins', 'set_site_transient_update_themes' ] as $hook ) {
            add_action( $hook, [ $this, 'changed' ], 10, 0 );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::HOOK );
        wp_clear_scheduled_hook( self::RETRY_HOOK );
    }

    public static function uninstall() {
        self::deactivate();
        delete_option( self::STATE );
        delete_option( self::LOCK );
    }

    public function schedule() {
        if ( ! $this->configuration() ) {
            self::deactivate();
            return;
        }
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + wp_rand( 60, 900 ), 'daily', self::HOOK );
        }
    }

    public function plugin_deactivated( $plugin ) {
        if ( plugin_basename( dirname( __DIR__ ) . '/arcwind-init.php' ) !== $plugin ) {
            $this->changed();
        }
    }

    public function changed() {
        if ( $this->configuration() && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
            wp_schedule_single_event( time() + 60, self::RETRY_HOOK );
        }
    }

    private function configuration() {
        try {
            return $this->read_configuration();
        } catch ( Throwable $error ) {
            return null;
        }
    }

    private function read_configuration() {
        $path = $this->config_path;
        if ( null === $path && defined( 'ARCWIND_INIT_NEXUS_CONFIG_FILE' ) ) {
            $path = ARCWIND_INIT_NEXUS_CONFIG_FILE;
        }
        if ( ! is_string( $path ) || ! is_readable( $path ) || ! is_file( $path ) || filesize( $path ) > 8192 ) {
            return null;
        }
        $real = realpath( $path );
        $root = realpath( ABSPATH );
        if ( ! $real || ! $root || str_starts_with( $real, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
            return null;
        }
        // This is a trusted deployment file, never an HTTP request or a WordPress option.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents( $real );
        $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
        $keys = [ 'schema', 'enabled', 'nexus_origin', 'installation_id', 'deployment_id', 'site_url', 'credential' ];
        if ( ! is_array( $data ) || count( $data ) !== count( $keys ) || array_diff( $keys, array_keys( $data ) ) || 1 !== ( $data['schema'] ?? null ) || true !== ( $data['enabled'] ?? null ) ) {
            return null;
        }
        foreach ( [ 'installation_id', 'deployment_id' ] as $field ) {
            if ( ! is_string( $data[ $field ] ) || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $data[ $field ] ) ) {
                return null;
            }
        }
        if ( ! is_string( $data['credential'] ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $data['credential'] ) || ! $this->https_reference( $data['nexus_origin'], true ) || ! $this->https_reference( $data['site_url'], false ) || $data['site_url'] !== home_url( '/' ) ) {
            return null;
        }
        return $data;
    }

    private function https_reference( $value, $origin ) {
        if ( ! is_string( $value ) || strlen( $value ) > 2048 || ! filter_var( $value, FILTER_VALIDATE_URL ) || preg_match( '/[\s\x00-\x1f\x7f]/', $value ) ) {
            return false;
        }
        $parts = wp_parse_url( $value );
        if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return false;
        }
        return ! $origin || in_array( $parts['path'] ?? '', [ '', '/' ], true );
    }

    private function version( $value ) {
        return is_string( $value ) && strlen( $value ) <= 100 && preg_match( '/\A[a-zA-Z0-9._+\/-]+\z/D', $value ) ? $value : null;
    }

    private function component( $id, $kind, $version, $active = null, $available = null, $checked = null ) {
        $available = $this->version( $available );
        $checked = is_int( $checked ) && $checked > 0 && $checked <= time() ? gmdate( 'Y-m-d\TH:i:s\Z', $checked ) : null;
        return [ 'id' => $id, 'kind' => $kind, 'version' => $this->version( $version ), 'active' => $active, 'available_version' => $checked ? $available : null, 'update_checked_at' => $checked ];
    }

    private function component_id( $kind, $value ) {
        if ( is_string( $value ) && strlen( $kind . '/' . $value ) <= 160 && preg_match( '/\A[a-z0-9][a-z0-9._\/-]*\z/D', $value ) ) {
            return $kind . '/' . $value;
        }
        return $kind . '/sha256/' . hash( 'sha256', (string) $value );
    }

    private function inventory() {
        global $wp_version, $wpdb;
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $core = get_site_transient( 'update_core' );
        $core_version = null;
        foreach ( is_object( $core ) && is_array( $core->updates ?? null ) ? $core->updates : [] as $update ) {
            if ( is_object( $update ) && 'upgrade' === ( $update->response ?? null ) ) {
                $core_version = $update->current ?? null;
                break;
            }
        }
        $components = [
            $this->component( 'wordpress', 'application', $wp_version, null, $core_version, is_object( $core ) ? ( $core->last_checked ?? null ) : null ),
            $this->component( 'php', 'runtime', PHP_VERSION ),
            $this->component( 'database', 'database', $wpdb->db_version() ),
        ];
        $plugins = get_site_transient( 'update_plugins' );
        foreach ( get_plugins() as $path => $plugin ) {
            $update = is_object( $plugins ) && is_array( $plugins->response ?? null ) ? ( $plugins->response[ $path ] ?? null ) : null;
            $components[] = $this->component( $this->component_id( 'plugin', $path ), 'plugin', $plugin['Version'] ?? null,
                is_plugin_active( $path ) || is_plugin_active_for_network( $path ), is_object( $update ) ? ( $update->new_version ?? null ) : null,
                is_object( $plugins ) ? ( $plugins->last_checked ?? null ) : null );
        }
        $themes = get_site_transient( 'update_themes' );
        foreach ( wp_get_themes( [ 'errors' => null ] ) as $slug => $theme ) {
            $update = is_object( $themes ) && is_array( $themes->response ?? null ) ? ( $themes->response[ $slug ] ?? null ) : null;
            $components[] = $this->component( $this->component_id( 'theme', $slug ), 'theme', $theme->get( 'Version' ),
                $slug === get_stylesheet() || $slug === get_template(), is_array( $update ) ? ( $update['new_version'] ?? null ) : null,
                is_object( $themes ) ? ( $themes->last_checked ?? null ) : null );
        }
        if ( count( $components ) > 500 || count( array_unique( array_column( $components, 'id' ) ) ) !== count( $components ) ) {
            throw new RuntimeException( 'Inventory component limits exceeded.' );
        }
        usort( $components, static function ( $left, $right ) { return strcmp( $left['id'], $right['id'] ); } );
        return $components;
    }

    private function release_lock( $claim ) {
        global $wpdb;
        // Compare the exact claim so an expired owner cannot remove its successor's lock.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, maybe_serialize( $claim ) ) );
        wp_cache_delete( self::LOCK, 'options' );
    }

    public function run() {
        $config = $this->configuration();
        if ( ! $config ) {
            self::deactivate();
            return;
        }
        $claim = [ 'id' => wp_generate_uuid4(), 'expires' => time() + 120 ];
        $old = get_option( self::LOCK, null );
        if ( is_array( $old ) && is_int( $old['expires'] ?? null ) && $old['expires'] < time() ) {
            $this->release_lock( $old );
        }
        if ( ! add_option( self::LOCK, $claim, '', false ) ) {
            return;
        }
        try {
            $this->send( $config );
        } catch ( Throwable $error ) {
            $state = get_option( self::STATE, [] );
            $state = is_array( $state ) ? $state : [];
            $state['status'] = 'inventory_unavailable';
            update_option( self::STATE, $state, false );
        } finally {
            $this->release_lock( $claim );
        }
    }

    private function send( array $config ) {
        $identity = hash( 'sha256', wp_json_encode( $config ) );
        $state = get_option( self::STATE, [] );
        $state = is_array( $state ) ? $state : [];
        if ( ( $state['identity'] ?? null ) !== $identity ) {
            $state = [ 'identity' => $identity ];
        }
        if ( 'enrollment_review' === ( $state['status'] ?? null ) ) {
            return;
        }
        $attempts = (int) ( $state['attempts'] ?? 0 );
        if ( empty( $state['body'] ) || $attempts >= 3 ) {
            $data = get_file_data( dirname( __DIR__ ) . '/arcwind-init.php', [ 'Version' => 'Version' ], 'plugin' );
            $sequence = max( (int) ( $state['sequence'] ?? 0 ) + 1, (int) floor( microtime( true ) * 1000000 ) );
            $report = [ 'schema' => 'nexus.inventory.v1', 'installation_id' => $config['installation_id'], 'deployment_id' => $config['deployment_id'],
                'site_url' => $config['site_url'], 'report_id' => wp_generate_uuid4(), 'sequence' => $sequence,
                'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'reporter' => 'wp-init/' . ( $this->version( $data['Version'] ?? null ) ?? 'unknown' ), 'components' => $this->inventory() ];
            $body = wp_json_encode( $report );
            if ( ! is_string( $body ) || strlen( $body ) > 262144 ) {
                throw new RuntimeException( 'Inventory request exceeds limits.' );
            }
            $state = [ 'identity' => $identity, 'body' => $body, 'sequence' => $sequence, 'attempts' => 0, 'last_success' => $state['last_success'] ?? null ];
        }
        // Commit the exact retry body before any network operation. It contains no secret.
        $state['attempts']++;
        $state['status'] = 'sending';
        $state['attempted_at'] = time();
        if ( ! update_option( self::STATE, $state, false ) ) {
            throw new RuntimeException( 'Inventory retry storage unavailable.' );
        }
        $response = wp_safe_remote_post( rtrim( $config['nexus_origin'], '/' ) . '/api/v1/inventory/' . $config['installation_id'] . '/reports', [
            'timeout' => 5, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true, 'limit_response_size' => 8192,
            'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $config['credential'] ], 'body' => $state['body'],
        ] );
        $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $result = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
        if ( 200 === $code && is_array( $result ) && in_array( $result['state'] ?? null, [ 'accepted', 'duplicate' ], true ) ) {
            unset( $state['body'] );
            $state['status'] = 'accepted';
            $state['attempts'] = 0;
            $state['last_success'] = time();
            wp_clear_scheduled_hook( self::RETRY_HOOK );
        } elseif ( 0 === $code || 429 === $code || $code >= 500 || 200 === $code ) {
            $state['status'] = $state['attempts'] < 3 ? 'retry_pending' : 'retry_exhausted';
            if ( $state['attempts'] < 3 && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
                wp_schedule_single_event( time() + ( 1 === $state['attempts'] ? 60 : 180 ), self::RETRY_HOOK );
            }
        } else {
            // Permanent failures require enrollment review. Do not retry automatically.
            $state['status'] = 'enrollment_review';
            $state['attempts'] = 3;
            wp_clear_scheduled_hook( self::RETRY_HOOK );
        }
        update_option( self::STATE, $state, false );
    }
}
