<?php

if (!defined('ABSPATH')) exit;

class ArcWind_Init_Tracker {
    public static function capture($event, $properties = [], $distinct_id = null, $blocking = true) {
        $opts = (array) get_option(ArcWind_Init::OPTION_KEY, []);

        $public_key = is_string($opts['public_api_key'] ?? null) ? $opts['public_api_key'] : '';
        $region     = ($opts['region'] ?? 'eu') === 'us' ? 'us' : 'eu';
        $host       = is_string($opts['api_host'] ?? null) && $opts['api_host'] !== ''
            ? rtrim($opts['api_host'], '/')
            : (array_key_exists('api_host', $opts) ? 'https://' . $region . '.i.posthog.com'
                : (is_string($opts['host'] ?? null) ? rtrim($opts['host'], '/') : 'https://' . $region . '.i.posthog.com'));

        if (empty($public_key) || empty($host) || !is_string($event) || $event === '' || wp_parse_url($host, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        if (!$distinct_id) {
            if (is_user_logged_in()) {
                $user = wp_get_current_user();
                $distinct_id = (string) $user->ID;
            } else {
                $distinct_id = 'wordpress:server';
            }
        }

        $base_properties = [
            'source'       => 'wordpress',
            'source_type'  => 'server',
            'site_url'     => home_url('/'),
            'plugin'       => 'arcwind-init',
            'plugin_ver'   => ArcWind_Init::get_version(),
            'is_admin'     => is_admin(),
            'page_type'    => is_admin() ? 'admin' : 'frontend',
            '$current_url' => is_admin() ? admin_url() : home_url(add_query_arg([])),
        ];

        $payload = [
            'api_key'     => $public_key,
            'event'       => (string) $event,
            'distinct_id' => (string) $distinct_id,
            'properties'  => array_merge($base_properties, is_array($properties) ? $properties : []),
        ];

        try {
            $response = wp_safe_remote_post($host . '/capture/', [
            'method'  => 'POST',
            'timeout' => $blocking ? 3 : 1,
            'blocking' => $blocking,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            ]);
        } catch (Throwable $error) {
            return false;
        }

        if (is_wp_error($response) || !is_array($response)) return false;
        if (!$blocking) return true;
        $code = wp_remote_retrieve_response_code($response);
        return is_int($code) && $code >= 200 && $code < 300;
    }
}
