<?php
/**
 * Lifecycle hooks → job enqueuing
 *
 * @package WCVec
 */

namespace WCVec;

use WCVec\Jobs\Job_Index_Product;
use WCVec\Jobs\Job_Delete_Product;

defined('ABSPATH') || exit;

final class Lifecycle
{
    public function __construct()
    {
        // Product create/update
        add_action('save_post_product', [$this, 'on_save_product'], 20, 3);
        add_action('save_post_product_variation', [$this, 'on_save_variation'], 20, 3);

        // Publish → non-publish transitions → purge
        add_action('transition_post_status', [$this, 'on_transition_status'], 10, 3);

        // Deletions / trash
        add_action('before_delete_post', [$this, 'on_before_delete'], 10, 1);
        add_action('trashed_post', [$this, 'on_trashed_post'], 10, 1);

        add_action('acf/save_post', [$this, 'on_acf_save_post'], 20, 1);
    }

    public function on_save_product(int $post_ID, \WP_Post $post, bool $update): void
    {
        if ($this->skip($post)) return;
        Job_Index_Product::enqueue($post_ID, false, 0);
    }

    public function on_transition_status(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($post->post_type !== 'product' && $post->post_type !== 'product_variation') return;

        // If leaving publish -> purge; entering publish -> index
        if ($old_status === 'publish' && $new_status !== 'publish') {
            Job_Delete_Product::enqueue((int) $post->ID, 0);
        } elseif ($new_status === 'publish' && $old_status !== 'publish') {
            Job_Index_Product::enqueue((int) $post->ID, false, 0);
        }
    }

    public function on_before_delete(int $post_ID): void
    {
        $post = get_post($post_ID);
        if (!$post) return;
        if ($post->post_type !== 'product' && $post->post_type !== 'product_variation') return;

        Job_Delete_Product::enqueue($post_ID, 0);
    }

    public function on_trashed_post(int $post_ID): void
    {
        $post = get_post($post_ID);
        if (!$post) return;
        if ($post->post_type !== 'product' && $post->post_type !== 'product_variation') return;

        Job_Delete_Product::enqueue($post_ID, 0);
    }

    /**
     * Handle ACF saves for products & variations.
     * Dedup is handled by Job_Index_Product::enqueue().
     *
     * @param mixed $post_id Numeric post ID, or strings like 'options', 'user_123', etc.
     */
    public function on_acf_save_post($post_id): void
    {
        if (!Options::acf_hook_enabled()) {
            return;
        }

        // ACF can call this for non-post contexts (options page, user meta, etc.)
        if (!is_numeric($post_id)) {
            return;
        }

        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Reuse our skip rules (autosave/revision/auto-draft)
        if ($this->skip($post)) {
            return;
        }

        if ($post->post_type === 'product') {
            Job_Index_Product::enqueue($post_id, false, 0);
            return;
        }

        if ($post->post_type === 'product_variation') {
            // Index the variation itself…
            Job_Index_Product::enqueue($post_id, false, 0);

            // …and also refresh the parent product (slight delay to buffer bursts)
            $parent_id = (int) $post->post_parent;
            if ($parent_id > 0) {
                Job_Index_Product::enqueue($parent_id, false, 30);
            }
        }
    }

    private function skip(\WP_Post $post): bool
    {
        if ($post->post_type !== 'product' && $post->post_type !== 'product_variation') return true;
        if (wp_is_post_autosave($post)) return true;
        if (wp_is_post_revision($post)) return true;
        if ($post->post_status === 'auto-draft') return true;

        // New: respect include_drafts_private flag
        if (!Options::include_drafts_private()) {
            if ($post->post_status !== 'publish') return true;
        }
        return false;
    }

    public function on_save_variation(int $post_ID, \WP_Post $post, bool $update): void
    {
        if ($this->skip($post)) return;

        $strategy = Options::get_variation_strategy();

        if ($strategy === 'separate') {
            // Index the variation itself
            Job_Index_Product::enqueue($post_ID, false, 0);
            // Also index parent to refresh any collapsed fields other parts may rely on
            $parent_id = (int) $post->post_parent;
            if ($parent_id > 0) Job_Index_Product::enqueue($parent_id, false, 30);
            return;
        }

        // collapse | parent_only → do not index the variation itself
        $parent_id = (int) $post->post_parent;
        if ($parent_id > 0) {
            Job_Index_Product::enqueue($parent_id, false, 0);
        }
    }
}
