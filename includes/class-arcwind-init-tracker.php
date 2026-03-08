<?php

if (!defined('ABSPATH')) exit;

class ArcWind_Init_Tracker {
    public static function capture($event, $properties = [], $distinct_id = null) {
        $opts = get_option(ArcWind_Init::OPTION_KEY);

        $public_key = $opts['public_api_key'] ?? '';
        $host       = rtrim($opts['host'] ?? 'https://tel.arc.vin/_p', '/');

        if (empty($public_key) || empty($host) || empty($event)) {
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

        $response = wp_remote_post($host . '/capture/', [
            'method'  => 'POST',
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300;
    }
}