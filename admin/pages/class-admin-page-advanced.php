<?php
/**
 * Admin: Advanced settings (performance, scope, variations) + Danger Zone placeholder
 *
 * @package WCVec
 */

namespace WCVec\Admin;

use WCVec\Nonces;
use WCVec\Scheduler;
use WCVec\Options;
use WCVec\Jobs\Job_Purge_Site;

defined('ABSPATH') || exit;

final class Page_Advanced
{
    private string $slug = 'wcvec-advanced';

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_post']);
    }

    public function title(): string { return __('Vector Indexing – Advanced', 'wc-vector-indexing'); }
    public function slug(): string { return $this->slug; }
    public function capability(): string { return 'manage_woocommerce'; }

    public function render(): void
    {
        if (!current_user_can($this->capability())) {
            wp_die(__('You do not have permission to view this page.', 'wc-vector-indexing'));
        }

        $view_data = [
            'nonce_save'          => \WCVec\Nonces::create('advanced_save'),
            // Performance
            'max_concurrent'      => \WCVec\Options::get_max_concurrent_jobs(),
            'batch_upsert_size'   => \WCVec\Options::get_batch_upsert_size(),
            // Scope
            'include_drafts_priv' => \WCVec\Options::include_drafts_private(),
            'variation_strategy'  => \WCVec\Options::get_variation_strategy(),
            'manual_include_vars' => \WCVec\Options::manual_include_variations(),
            // Logs
            'retention_days'      => (int) get_option('wcvec_event_log_retention_days', 7),
            // Compatibility
            'allow_dim_override'  => \WCVec\Options::allow_dimension_override(),
        ];
        $view_data['nonce_purge'] = \WCVec\Nonces::create('advanced_purge');
        $view_data['site_id']     = get_current_blog_id();

        include WC_VEC_DIR . 'admin/views/advanced.php';
    }

    public function handle_post(): void
    {
        if (!current_user_can($this->capability())) return;

        if (isset($_POST['action']) && $_POST['action'] === 'wcvec_advanced_save') {
            if (!\WCVec\Nonces::verify('advanced_save', (string) ($_POST['_nonce'] ?? ''))) {
                wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
            }

            // Performance
            \WCVec\Options::set_max_concurrent_jobs((int) ($_POST['max_concurrent'] ?? 3));
            \WCVec\Options::set_batch_upsert_size((int) ($_POST['batch_upsert_size'] ?? 100));

            // Scope
            \WCVec\Options::set_include_drafts_private(!empty($_POST['include_drafts_priv']));
            \WCVec\Options::set_variation_strategy(sanitize_text_field((string) ($_POST['variation_strategy'] ?? 'separate')));
            \WCVec\Options::set_manual_include_variations(!empty($_POST['manual_include_vars']));

            // Logs
            \WCVec\Options::set_event_log_retention_days((int) ($_POST['retention_days'] ?? 7));

            // Compatibility (guarded)
            \WCVec\Options::set_allow_dimension_override(!empty($_POST['allow_dim_override']));

            // Ensure scheduler respects new concurrency if needed
            \WCVec\Scheduler::ensure_recurring();

            // Danger Zone — purge
            if (isset($_POST['action']) && $_POST['action'] === 'wcvec_advanced_purge') {
                if (!\WCVec\Nonces::verify('advanced_purge', (string) ($_POST['_nonce'] ?? ''))) {
                    wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
                }
                $confirm = !empty($_POST['purge_confirm']);
                $typed   = isset($_POST['purge_type']) ? trim((string) $_POST['purge_type']) : '';
                if (!$confirm || strtoupper($typed) !== 'DELETE') {
                    wp_safe_redirect(add_query_arg(['page'=>'wcvec','tab'=>'advanced','notice'=>'purge_invalid'], admin_url('admin.php')));
                    exit;
                }

                \WCVec\Jobs\Job_Purge_Site::enqueue(0);

                wp_safe_redirect(add_query_arg(['page'=>'wcvec','tab'=>'advanced','notice'=>'purge_queued'], admin_url('admin.php')));
                exit;
            }
        }
    }
}
