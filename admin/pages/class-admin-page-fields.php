<?php
/**
 * Fields Admin Page
 *
 * @package WCVec
 */

namespace WCVec\Admin;

use WCVec\Options;
use WCVec\Field_Discovery;
use WCVec\Field_Normalizer;
use WCVec\Nonces;

defined('ABSPATH') || exit;

class Admin_Page_Fields {

    public function __construct() {
        add_action('admin_init', [$this, 'handle_post']);

        // NEW: AJAX endpoints
        add_action('wp_ajax_wcvec_search_products',  [$this, 'ajax_search_products']);
        add_action('wp_ajax_wcvec_fields_preview',   [$this, 'ajax_fields_preview']);
    }

    public function handle_post(): void
    {
        if (!is_admin()) { return; }

        $is_our_page = isset($_GET['page']) && $_GET['page'] === 'wcvec'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_tab = isset($_GET['tab']) && $_GET['tab'] === 'fields'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$is_our_page || !$is_tab) { return; }

        if (!isset($_POST['wcvec_action']) || $_POST['wcvec_action'] !== 'save_fields') { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to update these settings.', 'wc-vector-indexing'));
        }

        check_admin_referer('wcvec_fields_save', 'wcvec_nonce');

        // Harvest core / tax / attributes / seo
        $core = isset($_POST['wcvec_core']) ? (array) $_POST['wcvec_core'] : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $tax  = isset($_POST['wcvec_tax']) ? (array) $_POST['wcvec_tax'] : [];   // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $atts = isset($_POST['wcvec_attributes']) ? (array) $_POST['wcvec_attributes'] : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $seo  = isset($_POST['wcvec_seo']) ? (array) $_POST['wcvec_seo'] : [];   // phpcs:ignore WordPress.Security.NonceVerification.Missing

        // Meta repeater (two parallel arrays)
        $meta_keys  = isset($_POST['wcvec_meta_key']) ? (array) $_POST['wcvec_meta_key'] : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $meta_modes = isset($_POST['wcvec_meta_mode']) ? (array) $_POST['wcvec_meta_mode'] : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $meta_map = [];
        foreach ($meta_keys as $i => $k) {
            $k = sanitize_text_field((string) $k);
            if ($k === '') { continue; }
            $mode = isset($meta_modes[$i]) && $meta_modes[$i] === 'json' ? 'json' : 'text';
            $meta_map[$k] = $mode;
        }

        // ACF fields — associative set of rows keyed by field_key
        $acf_fields = isset($_POST['wcvec_acf']) && is_array($_POST['wcvec_acf']) ? $_POST['wcvec_acf'] : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $acf_selected = [];
        foreach ($acf_fields as $field_key => $row) {
            if (empty($row['selected'])) { continue; }
            $acf_selected[] = [
                'group_key' => isset($row['group_key']) ? sanitize_text_field((string) $row['group_key']) : '',
                'field_key' => sanitize_text_field((string) $field_key),
                'name'      => isset($row['name']) ? sanitize_text_field((string) $row['name']) : '',
                'label'     => isset($row['label']) ? sanitize_text_field((string) $row['label']) : '',
                'type'      => isset($row['type']) ? sanitize_text_field((string) $row['type']) : 'text',
                'mode'      => (isset($row['mode']) && $row['mode'] === 'json') ? 'json' : 'text',
            ];
        }

        $flags = [
            'show_private_meta' => !empty($_POST['wcvec_show_private_meta']), // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ];

        $map = [
            'core'       => $core,
            'tax'        => $tax,
            'attributes' => $atts,
            'seo'        => $seo,
            'meta'       => $meta_map,
            'acf'        => $acf_selected,
            'flags'      => $flags,
        ];

        Options::set_selected_fields($map);

        add_settings_error('wcvec', 'wcvec_fields_saved', __('Field selection saved.', 'wc-vector-indexing'), 'updated');

        // Redirect to avoid resubmission
        $redirect = add_query_arg(['page' => 'wcvec', 'tab' => 'fields', 'settings-updated' => '1'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-vector-indexing'));
        }

        $selection = Options::get_selected_fields();
        $catalog = Field_Discovery::get_catalog(['include_private_meta' => !empty($selection['flags']['show_private_meta'])]);

        settings_errors('wcvec');

        $view = WC_VEC_DIR . 'admin/views/fields.php';
        if (file_exists($view)) {
            $data = [
                'selection' => $selection,
                'catalog'   => $catalog,
            ];
            extract($data, EXTR_SKIP);
            require $view;
        } else {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('Fields view file is missing.', 'wc-vector-indexing');
            echo '</p></div>';
        }
    }

    /**
     * Search products by title or SKU (admin-ajax).
     * POST: q, page?, limit?
     */
    public function ajax_search_products(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-vector-indexing')], 403);
        }
        $nonce = isset($_POST['_nonce']) ? (string) $_POST['_nonce'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!Nonces::verify('search_products', $nonce)) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'wc-vector-indexing')], 403);
        }

        $q     = isset($_POST['q']) ? sanitize_text_field((string) $_POST['q']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $limit = isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : 20;        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $page  = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;          // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ($q === '') {
            wp_send_json_success(['results' => []]);
        }

        $results = [];
        $seen    = [];

        // 1) Exact SKU first
        $sku_query = new \WP_Query([
            'post_type'      => 'product',
            'posts_per_page' => $limit,
            'meta_query'     => [
                [
                    'key'     => '_sku',
                    'value'   => $q,
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        if ($sku_query->have_posts()) {
            foreach ($sku_query->posts as $pid) {
                $seen[$pid] = true;
                $results[] = self::format_product_suggestion((int) $pid);
            }
        }

        // 2) Title/content search
        $search_query = new \WP_Query([
            'post_type'      => 'product',
            'posts_per_page' => $limit,
            'paged'          => $page,
            's'              => $q,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        if ($search_query->have_posts()) {
            foreach ($search_query->posts as $pid) {
                if (isset($seen[$pid])) { continue; }
                $seen[$pid] = true;
                $results[] = self::format_product_suggestion((int) $pid);
                if (count($results) >= $limit) { break; }
            }
        }

        // 3) Fuzzy SKU (LIKE) if still thin
        if (count($results) < $limit) {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($q) . '%';
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_sku' AND pm.meta_value LIKE %s
                 AND p.post_type = 'product' AND p.post_status IN ('publish','private')
                 LIMIT %d",
                 $like, $limit
            ));
            foreach ($ids as $pid) {
                $pid = (int) $pid;
                if (isset($seen[$pid])) { continue; }
                $seen[$pid] = true;
                $results[] = self::format_product_suggestion($pid);
                if (count($results) >= $limit) { break; }
            }
        }

        wp_send_json_success(['results' => $results]);
    }

    private static function format_product_suggestion(int $product_id): array
    {
        $title = get_the_title($product_id);
        $sku   = function_exists('wc_get_product') ? (string) wc_get_product($product_id)->get_sku() : '';
        $text  = $title . ($sku !== '' ? " ($sku)" : '');
        return ['id' => $product_id, 'text' => $text];
    }

    /**
     * Build preview text (admin-ajax).
     * POST: product_id, selection (JSON)
     */
    public function ajax_fields_preview(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-vector-indexing')], 403);
        }
        $nonce = isset($_POST['_nonce']) ? (string) $_POST['_nonce'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!Nonces::verify('fields_preview', $nonce)) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'wc-vector-indexing')], 403);
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $sel_json   = isset($_POST['selection']) ? (string) wp_unslash($_POST['selection']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ($product_id <= 0) {
            wp_send_json_error(['message' => __('Select a product to preview.', 'wc-vector-indexing')]);
        }

        $selection = json_decode($sel_json, true);
        if (!is_array($selection)) {
            wp_send_json_error(['message' => __('Invalid selection payload.', 'wc-vector-indexing')]);
        }

        $res = Field_Normalizer::build_preview($product_id, $selection);
        if (empty($res['ok'])) {
            wp_send_json_error(['message' => $res['message'] ?? __('Preview failed.', 'wc-vector-indexing')]);
        }

        wp_send_json_success([
            'text'     => (string) $res['text'],
            'sections' => $res['sections'] ?? [],
        ]);
    }
}
