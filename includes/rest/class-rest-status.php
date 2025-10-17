<?php
/**
 * REST: /wcvec/v1/status
 *
 * @package WCVec
 */

namespace WCVec\REST;

use WP_REST_Request;
use WCVec\Secure_Options;
use WCVec\Options;

defined('ABSPATH') || exit;

class Rest_Status {

    public static function register(): void
    {
        register_rest_route(
            'wcvec/v1',
            '/status',
            [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'get_status'],
                'permission_callback' => [__CLASS__, 'permissions'],
            ]
        );
    }

    public static function permissions(): bool
    {
        // Restrict to admins/store managers (Woo capability).
        return current_user_can('manage_woocommerce');
    }

    public static function get_status( WP_REST_Request $request )
    {
        $plugin_version = defined('WC_VEC_VERSION') ? WC_VEC_VERSION : '0.0.0';
        $wp_version     = get_bloginfo('version');
        $php_version    = PHP_VERSION;

        // WooCommerce version (best-effort).
        if (defined('WC_VERSION')) {
            $wc_version = WC_VERSION;
        } elseif (function_exists('WC') && WC()) {
            $wc_version = WC()->version;
        } else {
            $wc_version = '';
        }

        return [
            'plugin_version' => $plugin_version,
            'wp_version'     => $wp_version,
            'php_version'    => $php_version,
            'woocommerce_version' => $wc_version,
            'checks' => [
                'sodium_available'   => Secure_Options::is_sodium_available(),
                'openai_configured'  => Options::is_openai_configured(),
                'pinecone_configured'=> Options::is_pinecone_configured(),
            ],
        ];
    }
}
