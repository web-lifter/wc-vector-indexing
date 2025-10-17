<?php
/**
 * Admin: Products list bulk & row actions for vector indexing.
 *
 * @package WCVec
 */

namespace WCVec\Admin;

use WCVec\Options;
use WCVec\Jobs\Job_Index_Product;
use WCVec\Jobs\Job_Delete_Product;

defined('ABSPATH') || exit;

final class Products_Actions
{
    public function __construct()
    {
        // Bulk actions
        add_filter('bulk_actions-edit-product', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_actions'], 10, 3);

        // Row actions (per product row)
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);

        // Row action handler
        add_action('admin_post_wcvec_row_action', [$this, 'handle_row_action']);

        // Notices
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    /** Add “Index to Vector Stores” and “Remove from Vector Stores” to bulk actions. */
    public function register_bulk_actions(array $actions): array
    {
        $actions['wcvec_bulk_index']  = __('Index to Vector Stores', 'wc-vector-indexing');
        $actions['wcvec_bulk_remove'] = __('Remove from Vector Stores', 'wc-vector-indexing');
        return $actions;
    }

    /**
     * Handle the bulk actions and enqueue background jobs.
     *
     * @param string $redirect_url
     * @param string $action
     * @param array  $post_ids
     * @return string
     */
    public function handle_bulk_actions(string $redirect_url, string $action, array $post_ids): string
    {
        if (!current_user_can('manage_woocommerce')) {
            return $redirect_url;
        }

        $post_ids = array_values(array_unique(array_map('intval', $post_ids)));
        if (empty($post_ids)) {
            return $redirect_url;
        }

        $include_vars = Options::manual_include_variations();
        $total = 0;

        switch ($action) {
            case 'wcvec_bulk_index':
                foreach ($post_ids as $pid) {
                    $total += $this->enqueue_index_with_variations($pid, $include_vars);
                }
                $redirect_url = add_query_arg([
                    'wcvec_notice' => 'bulk_indexed',
                    'wcvec_count'  => $total,
                ], $redirect_url);
                break;

            case 'wcvec_bulk_remove':
                foreach ($post_ids as $pid) {
                    $total += $this->enqueue_remove_with_variations($pid, $include_vars);
                }
                $redirect_url = add_query_arg([
                    'wcvec_notice' => 'bulk_removed',
                    'wcvec_count'  => $total,
                ], $redirect_url);
                break;
        }

        return $redirect_url;
    }

    /** Add “Index now” / “Remove from indexes” to each product row. */
    public function row_actions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== 'product') {
            return $actions;
        }
        if (!current_user_can('manage_woocommerce')) {
            return $actions;
        }

        $index_url  = wp_nonce_url(
            admin_url('admin-post.php?action=wcvec_row_action&sub=index&post=' . (int) $post->ID),
            'wcvec_row_' . (int) $post->ID
        );
        $remove_url = wp_nonce_url(
            admin_url('admin-post.php?action=wcvec_row_action&sub=remove&post=' . (int) $post->ID),
            'wcvec_row_' . (int) $post->ID
        );

        $actions['wcvec_index_now'] = '<a href="' . esc_url($index_url) . '">' .
            esc_html__('Index now', 'wc-vector-indexing') . '</a>';

        $actions['wcvec_remove'] = '<a href="' . esc_url($remove_url) . '">' .
            esc_html__('Remove from indexes', 'wc-vector-indexing') . '</a>';

        return $actions;
    }

    /** Handle row action clicks via admin-post endpoint. */
    public function handle_row_action(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permission denied.', 'wc-vector-indexing'));
        }

        $pid = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $sub = isset($_GET['sub']) ? sanitize_text_field((string) $_GET['sub']) : '';
        if ($pid <= 0 || !in_array($sub, ['index', 'remove'], true)) {
            wp_safe_redirect(admin_url('edit.php?post_type=product'));
            exit;
        }

        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'wcvec_row_' . $pid)) {
            wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
        }

        $include_vars = Options::manual_include_variations();
        $count = 0;

        if ($sub === 'index') {
            $count = $this->enqueue_index_with_variations($pid, $include_vars);
            $query = ['wcvec_notice' => 'row_indexed', 'wcvec_count' => $count];
        } else {
            $count = $this->enqueue_remove_with_variations($pid, $include_vars);
            $query = ['wcvec_notice' => 'row_removed', 'wcvec_count' => $count];
        }

        $redirect = add_query_arg($query, admin_url('edit.php?post_type=product'));
        wp_safe_redirect($redirect);
        exit;
    }

    /** Admin notices after redirects. */
    public function admin_notices(): void
    {
        if (!isset($_GET['wcvec_notice'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $count = isset($_GET['wcvec_count']) ? (int) $_GET['wcvec_count'] : 0;
        $msg   = '';
        $class = 'updated';

        switch (sanitize_text_field((string) $_GET['wcvec_notice'])) {
            case 'bulk_indexed':
                $msg = sprintf(_n(
                    'Queued indexing for %d item.',
                    'Queued indexing for %d items.',
                    $count,
                    'wc-vector-indexing'
                ), $count);
                $class = 'notice notice-success';
                break;

            case 'bulk_removed':
                $msg = sprintf(_n(
                    'Queued removal from vector stores for %d item.',
                    'Queued removal from vector stores for %d items.',
                    $count,
                    'wc-vector-indexing'
                ), $count);
                $class = 'notice notice-warning';
                break;

            case 'row_indexed':
                $msg = sprintf(__('Queued indexing for product (and %d related items).', 'wc-vector-indexing'), max(0, $count - 1));
                $class = 'notice notice-success';
                break;

            case 'row_removed':
                $msg = sprintf(__('Queued removal for product (and %d related items).', 'wc-vector-indexing'), max(0, $count - 1));
                $class = 'notice notice-warning';
                break;
        }

        if ($msg) {
            echo '<div class="' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    /** Enqueue index job for a product and (optionally) its variations; return count of jobs scheduled. */
    private function enqueue_index_with_variations(int $product_id, bool $include_variations): int
    {
        $count = 0;

        // Validate product exists & is product/variation
        $post = get_post($product_id);
        if (!$post || ($post->post_type !== 'product' && $post->post_type !== 'product_variation')) {
            return 0;
        }

        Job_Index_Product::enqueue($product_id, false, 0);
        $count++;

        // If parent product & include variations
        if ($include_variations && $post->post_type === 'product') {
            foreach ($this->get_variation_ids($product_id) as $vid) {
                Job_Index_Product::enqueue((int) $vid, false, 0);
                $count++;
            }
        }

        return $count;
    }

    /** Enqueue delete job for a product and (optionally) its variations; return count of jobs scheduled. */
    private function enqueue_remove_with_variations(int $product_id, bool $include_variations): int
    {
        $count = 0;

        $post = get_post($product_id);
        if (!$post || ($post->post_type !== 'product' && $post->post_type !== 'product_variation')) {
            return 0;
        }

        Job_Delete_Product::enqueue($product_id, 0);
        $count++;

        if ($include_variations && $post->post_type === 'product') {
            foreach ($this->get_variation_ids($product_id) as $vid) {
                Job_Delete_Product::enqueue((int) $vid, 0);
                $count++;
            }
        }

        return $count;
    }

    /** Fetch direct child variation IDs for a variable product. */
    private function get_variation_ids(int $parent_product_id): array
    {
        $ids = get_posts([
            'post_type'      => 'product_variation',
            'post_status'    => ['publish','private'],
            'numberposts'    => -1,
            'fields'         => 'ids',
            'post_parent'    => $parent_product_id,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'suppress_filters'       => true,
        ]);
        return array_map('intval', (array) $ids);
    }
}
