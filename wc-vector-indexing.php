<?php
/**
 * Plugin Name: WooCommerce Vector Indexing
 * Description: Index WooCommerce products into Pinecone and OpenAI Vector Store with OpenAI embeddings.
 * Plugin URI:  https://weblifter.com.au/wc-vector-indexing
 * Author:      Web Lifter
 * Author URI:  https://weblifter.com.au
 * Version:     0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.2
 * Text Domain: wc-vector-indexing
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

if (!defined('WC_VEC_VERSION')) {
    define('WC_VEC_VERSION', '0.1.0');
}
if (!defined('WC_VEC_FILE')) {
    define('WC_VEC_FILE', __FILE__);
}
if (!defined('WC_VEC_DIR')) {
    define('WC_VEC_DIR', plugin_dir_path(__FILE__));
}
if (!defined('WC_VEC_URL')) {
    define('WC_VEC_URL', plugin_dir_url(__FILE__));
}

require_once __DIR__ . '/includes/class-plugin.php';

// Optional: Composer autoload if present (recommended)
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

add_action('plugins_loaded', static function () {
    // Safety: WooCommerce capability relies on WC being present later; we just boot our plugin.
    \WCVec\Plugin::instance();
});

/**
 * Add “Settings” link on Plugins screen row.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links) {
    $url = admin_url('admin.php?page=wcvec&tab=connections');
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'wc-vector-indexing') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
