<?php
/**
 * Admin: Logs page (reads wp_wcvec_objects)
 *
 * @package WCVec
 */

namespace WCVec\Admin;

use WCVec\Nonces;
use WCVec\Storage;
use WCVec\Jobs\Job_Index_Product;
use WCVec\Jobs\Job_Delete_Product;

defined('ABSPATH') || exit;

final class Page_Logs {

    private string $slug = 'wcvec-logs';

    public function __construct() {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function title(): string {
        return __('Vector Indexing – Logs', 'wc-vector-indexing');
    }

    public function slug(): string {
        return $this->slug;
    }

    public function capability(): string {
        return 'manage_woocommerce';
    }

    public function render(): void {
        if (!current_user_can($this->capability())) {
            wp_die(__('You do not have permission to view this page.', 'wc-vector-indexing'));
        }

        $filters = $this->read_filters();
        $results = $this->query_rows($filters);

        $export_url = add_query_arg([
            'action' => 'wcvec_logs_export',
            '_nonce' => Nonces::create('logs_export'),
            // preserve filters
            'product_id' => $filters['product_id'] ?: '',
            'target'     => $filters['target']     ?: '',
            'status'     => $filters['status']     ?: '',
        ], admin_url('admin-post.php'));

        $view_data = [
            'filters'    => $filters,
            'rows'       => $results['rows'],
            'total'      => $results['total'],
            'per_page'   => $filters['per_page'],
            'page'       => $filters['page'],
            'export_url' => $export_url,
            'nonce_reindex' => Nonces::create('logs_reindex'),
            'nonce_purge'   => Nonces::create('logs_purge'),
        ];

        include WC_VEC_DIR . 'admin/views/logs.php';
    }

    /** Handle CSV export + quick actions. */
    public function handle_actions(): void {
        if (!current_user_can($this->capability())) {
            return;
        }

        // CSV export
        if (isset($_GET['action']) && $_GET['action'] === 'wcvec_logs_export') {
            if (!\WCVec\Nonces::verify('logs_export', (string) ($_GET['_nonce'] ?? ''))) {
                wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
            }
            $filters = $this->read_filters(true);
            $this->stream_csv($filters);
        }

        // Reindex (force)
        if (isset($_POST['action']) && $_POST['action'] === 'wcvec_logs_reindex') {
            if (!\WCVec\Nonces::verify('logs_reindex', (string) ($_POST['_nonce'] ?? ''))) {
                wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
            }
            $pid = (int) ($_POST['product_id'] ?? 0);
            if ($pid > 0) {
                Job_Index_Product::enqueue($pid, true, 0);
                wp_safe_redirect(add_query_arg(['page'=>'wcvec','tab'=>'logs','notice'=>'reindex'], admin_url('admin.php')));
                exit;
            }
        }

        // Purge (deletes from vector stores + local rows)
        if (isset($_POST['action']) && $_POST['action'] === 'wcvec_logs_purge') {
            if (!\WCVec\Nonces::verify('logs_purge', (string) ($_POST['_nonce'] ?? ''))) {
                wp_die(__('Invalid nonce.', 'wc-vector-indexing'));
            }
            $pid = (int) ($_POST['product_id'] ?? 0);
            if ($pid > 0) {
                Job_Delete_Product::enqueue($pid, 0);
                wp_safe_redirect(add_query_arg(['page'=>'wcvec','tab'=>'logs','notice'=>'purge'], admin_url('admin.php')));
                exit;
            }
        }
    }

    /** Parse filter inputs (GET); $for_export allows larger limits. */
    private function read_filters(bool $for_export = false): array {
        $pid      = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
        $target   = isset($_GET['target']) ? sanitize_text_field((string) $_GET['target']) : '';
        $status   = isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '';
        $per_page = isset($_GET['per_page']) ? max(5, min(200, (int) $_GET['per_page'])) : 25;
        $page     = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        if ($for_export) {
            $per_page = 5000; // generous export cap
            $page     = 1;
        }

        return [
            'product_id' => $pid,
            'target'     => $target,
            'status'     => $status,
            'per_page'   => $per_page,
            'page'       => $page,
        ];
    }

    /** Query rows with filters + pagination. */
    private function query_rows(array $f): array {
        global $wpdb;
        $table = Storage::table();

        $where = ['1=1'];
        $args  = [];

        if ($f['product_id'] > 0) {
            $where[] = 'product_id = %d';
            $args[]  = $f['product_id'];
        }
        if ($f['target'] !== '' && in_array($f['target'], ['pinecone','openai'], true)) {
            $where[] = 'target = %s';
            $args[]  = $f['target'];
        }
        if ($f['status'] !== '' && in_array($f['status'], ['synced','pending','error','deleted'], true)) {
            $where[] = 'status = %s';
            $args[]  = $f['status'];
        }

        $where_sql = implode(' AND ', $where);

        // Count
        $sql_count = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($sql_count, $args));

        // Rows
        $offset = ($f['page'] - 1) * $f['per_page'];
        $sql = "SELECT id, site_id, product_id, target, chunk_index, vector_id, product_sha, chunk_sha,
                       model, dimension, status, error_code, SUBSTRING(error_msg,1,300) AS error_msg,
                       last_synced_at, created_at, updated_at
                FROM {$table}
                WHERE {$where_sql}
                ORDER BY updated_at DESC, id DESC
                LIMIT %d OFFSET %d";

        $args_rows = array_merge($args, [ $f['per_page'], $offset ]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args_rows), ARRAY_A) ?: [];

        return ['rows'=>$rows, 'total'=>$total];
    }

    /** Stream CSV (filtered). */
    private function stream_csv(array $f): void {
        if (!current_user_can($this->capability())) {
            wp_die(__('Permission denied.', 'wc-vector-indexing'));
        }

        $results = $this->query_rows($f);
        $rows    = $results['rows'];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wcvec_logs_' . gmdate('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','site_id','product_id','target','chunk_index','vector_id','product_sha','chunk_sha','model','dimension','status','error_code','error_msg','last_synced_at','created_at','updated_at']);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['site_id'], $r['product_id'], $r['target'], $r['chunk_index'], $r['vector_id'],
                $r['product_sha'], $r['chunk_sha'], $r['model'], $r['dimension'], $r['status'],
                $r['error_code'], $r['error_msg'], $r['last_synced_at'], $r['created_at'], $r['updated_at']
            ]);
        }
        fclose($out);
        exit;
    }

    private function read_event_filters(): array
    {
        $pid    = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
        $target = isset($_GET['target']) ? sanitize_text_field((string) $_GET['target']) : '';
        $action = isset($_GET['action_f']) ? sanitize_text_field((string) $_GET['action_f']) : '';
        $outcome= isset($_GET['outcome']) ? sanitize_text_field((string) $_GET['outcome']) : '';
        $per    = isset($_GET['per_page']) ? max(10, min(200, (int) $_GET['per_page'])) : 50;
        $page   = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        return [
            'product_id' => $pid,
            'target'     => $target,
            'action'     => $action,
            'outcome'    => $outcome,
            'per_page'   => $per,
            'page'       => $page,
        ];
    }

}
