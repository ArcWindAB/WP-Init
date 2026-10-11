<?php
/** Isolated WordPress test fixture: reject outbound third-party requests. */
if (!defined('ABSPATH')) exit;

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $host = wp_parse_url($url, PHP_URL_HOST);
    if ($host === '127.0.0.1' || $host === 'localhost') return $pre;
    return new WP_Error('offline_fixture', 'External service unavailable');
}, 1, 3);
