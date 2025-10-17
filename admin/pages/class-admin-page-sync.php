<?php
/**
 * Admin: Sync page (scheduler controls + metrics)
 *
 * @package WCVec
 */

namespace WCVec\Admin;

use WCVec\Options;
use WCVec\Scheduler;
use WCVec\Nonces;

defined('ABSPATH') || exit;

final class Page_Sync
{
    private string $slug = 'wcvec-sync';

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_post']);
    }

    public function title(): string { return __('Vector Indexing – Sync', 'wc-vector-indexing'); }
    public function slug(): string { return $this->slug; }
    public function capability(): string { return 'manage_woocommerce'; }

    public function render(): void
    {
        if (!current_user_can($this->capability())) {
            wp_die(__('You do not have permission to view this page.', 'wc-vector-indexing'));
        }

        $metrics = \WCVec\Scheduler::get_metrics();

        $view_data = [
            'nonce_save'    => \WCVec\Nonces::create('sync_save'),
            'nonce_scan'    => \WCVec\Nonces::create('sync_scan'),
            'nonce_requeue' => \WCVec\Nonces::create('sync_requeue'),
            'metrics'       => $metrics,
            'cadences'      => [
                '5min' => __('Every 5 minutes', 'wc-vector-indexing'),
                '15min'=> __('Every 15 minutes', 'wc-vector-indexing'),
                'hourly' => __('Hourly', 'wc-vector-indexing'),
                'twicedaily' => __('Twice daily', 'wc-vector-indexing'),
                'daily' => __('Daily', 'wc-vector-indexing'),
            ],
        ];

        include WC_VEC_DIR . 'admin/views/sync.php';
    }

    public function handle_post(): void
    {
        if (!current_user_can($this->capability())) return;

        // Save settings
        if (isset($_POST['action']) && $_POST['action'] === 'wcvec_sync_save') {
            if (!\WCVec\Nonces::verify('sync_save', (string) ($_POST['_nonce'] ?? ''))) {
                wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
            }
            $auto   = !empty($_POST['auto_sync']);
            $cad    = isset($_POST['cadence']) ? sanitize_text_field((string) $_POST['cadence']) : '15min';
            $conc   = isset($_POST['max_concurrent']) ? (int) $_POST['max_concurrent'] : 3;
            $batch  = isset($_POST['scan_batch_limit']) ? (int) $_POST['scan_batch_limit'] : 200;
            $acf    = !empty($_POST['acf_hook']);

            Options::set_auto_sync_enabled($auto);
            Options::set_scheduler_cadence($cad);
            Options::set_max_concurrent_jobs($conc);
            Options::set_scan_batch_limit($batch);
            Options::set_acf_hook_enabled($acf);

            // Ensure schedule matches new settings
            \WCVec\Scheduler::ensure_recurring();

            wp_safe_redirect(add_query_arg(['page'=>'wcvec','tab'=>'sync','notice'=>'saved'], admin_url('admin.php')));
            exit;
        }

        // Run scan now
        if (isset($_POST['action']) && $_POST['action'] === 'wcvec_sync_run_scan') {
            if (!\WCVec\Nonces::verify('sync_scan', (string) ($_POST['_nonce'] ?? ''))) {
                wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
            }
            \WCVec\Scheduler::run_now();
            wp_safe_redirect(add_query_arg(['page'=>'wcvec','tab'=>'sync','notice'=>'scan'], admin_url('admin.php')));
            exit;
        }

        // Requeue all errors
        if (isset($_POST['action']) && $_POST['action'] === 'wcvec_sync_requeue_errors') {
            if (!\WCVec\Nonces::verify('sync_requeue', (string) ($_POST['_nonce'] ?? ''))) {
                wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
            }
            \WCVec\Scheduler::requeue_errors();
            wp_safe_redirect(add_query_arg(['page'=>'wcvec','tab'=>'sync','notice'=>'requeue'], admin_url('admin.php')));
            exit;
        }
    }
}
