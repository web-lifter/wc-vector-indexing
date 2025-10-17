<?php
/**
 * Connections Admin Page
 *
 * @package WCVec
 */

namespace WCVec\Admin;

use WCVec\Options;
use WCVec\Secure_Options;
use WCVec\Validators;
use WCVec\Nonces;

defined('ABSPATH') || exit;

class Admin_Page_Connections {

    public function __construct() {
        add_action('admin_init', [$this, 'handle_post']);

        // Existing validators...
        add_action('wp_ajax_wcvec_validate_openai',  [$this, 'ajax_validate_openai']);
        add_action('wp_ajax_wcvec_validate_pinecone',[$this, 'ajax_validate_pinecone']);

        // NEW sample upsert/delete
        add_action('wp_ajax_wcvec_sample_upsert',  [$this, 'ajax_sample_upsert']);
        add_action('wp_ajax_wcvec_sample_delete',  [$this, 'ajax_sample_delete']);
    }

    /**
     * Process POSTed settings from the Connections tab.
     */
    public function handle_post(): void {6
        if (!is_admin()) {
            return;
        }

        // Only handle our page + tab.
        $is_our_page = isset($_GET['page']) && $_GET['page'] === 'wcvec'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_tab_conn = isset($_GET['tab']) && $_GET['tab'] === 'connections'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$is_our_page || !$is_tab_conn) {
            return;
        }

        if (!isset($_POST['wcvec_action']) || $_POST['wcvec_action'] !== 'save_connections') { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to update these settings.', 'wc-vector-indexing'));
        }

        check_admin_referer('wcvec_connections_save', 'wcvec_nonce');

        $errors = [];

        // --- OpenAI ---
        $oai_key_new = isset($_POST['wcvec_oai_key']) ? (string) $_POST['wcvec_oai_key'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $oai_key_new = trim($oai_key_new);
        if ($oai_key_new !== '') {
            Options::set_openai_key_raw($oai_key_new);
        }
        // If empty, keep existing.

        $model = isset($_POST['wcvec_model']) ? sanitize_text_field((string) $_POST['wcvec_model']) : Options::get_model(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!in_array($model, Options::allowed_models(), true)) {
            $errors[] = __('Invalid embedding model selected.', 'wc-vector-indexing');
        } else {
            Options::set_model($model); // auto-updates dimension to default
        }

        $oai_vs_id = isset($_POST['wcvec_oai_vectorstore_id']) ? sanitize_text_field((string) $_POST['wcvec_oai_vectorstore_id']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        Options::set_openai_vectorstore_id($oai_vs_id);

        // --- Pinecone ---
        $pine_key_new = isset($_POST['wcvec_pine_key']) ? (string) $_POST['wcvec_pine_key'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $pine_key_new = trim($pine_key_new);
        if ($pine_key_new !== '') {
            Options::set_pinecone_key_raw($pine_key_new);
        }

        $pine_env     = isset($_POST['wcvec_pine_env']) ? sanitize_text_field((string) $_POST['wcvec_pine_env']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $pine_project = isset($_POST['wcvec_pine_project']) ? sanitize_text_field((string) $_POST['wcvec_pine_project']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $pine_index   = isset($_POST['wcvec_pine_index']) ? sanitize_text_field((string) $_POST['wcvec_pine_index']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        Options::set_pinecone_env($pine_env);
        Options::set_pinecone_project($pine_project);
        Options::set_pinecone_index($pine_index);

        if (empty($errors)) {
            add_settings_error('wcvec', 'wcvec_saved', __('Settings saved.', 'wc-vector-indexing'), 'updated');
        } else {
            foreach ($errors as $msg) {
                add_settings_error('wcvec', 'wcvec_error_' . md5($msg), $msg, 'error');
            }
        }

        // Redirect to avoid resubmission.
        $redirect = add_query_arg(
            [
                'page' => 'wcvec',
                'tab'  => 'connections',
                'settings-updated' => '1',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public function ajax_validate_openai(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-vector-indexing')], 403);
        }
        $nonce = isset($_POST['_nonce']) ? (string) $_POST['_nonce'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!Nonces::verify('validate_openai', $nonce)) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'wc-vector-indexing')], 403);
        }

        $res = Validators::validate_openai();
        if ($res['ok']) {
            wp_send_json_success(['message' => $res['message']]);
        }
        wp_send_json_error(['message' => $res['message']]);
    }

    public function ajax_validate_pinecone(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-vector-indexing')], 403);
        }
        $nonce = isset($_POST['_nonce']) ? (string) $_POST['_nonce'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!Nonces::verify('validate_pinecone', $nonce)) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'wc-vector-indexing')], 403);
        }

        $res = Validators::validate_pinecone();
        if ($res['ok']) {
            wp_send_json_success(['message' => $res['message']]);
        }
        wp_send_json_error(['message' => $res['message']]);
    }

    /**
     * Render the Connections tab view.
     */
    public function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-vector-indexing'));
        }

        // Gather current values for display.
        $data = [
            'oai_key_masked' => Options::get_openai_key_masked(),
            'pine_key_masked' => Options::get_pinecone_key_masked(),

            'model'     => Options::get_model(),
            'dimension' => Options::get_dimension(),
            'allowed_models' => Options::allowed_models(),

            'oai_vs_id' => Options::get_openai_vectorstore_id(),

            'pine_env'     => Options::get_pinecone_env(),
            'pine_project' => Options::get_pinecone_project(),
            'pine_index'   => Options::get_pinecone_index(),

            'sodium_available' => \WCVec\Secure_Options::is_sodium_available(),
        ];

        // Print settings errors (from add_settings_error).
        settings_errors('wcvec');

        // Load the view.
        $view = WC_VEC_DIR . 'admin/views/connections.php';
        if (file_exists($view)) {
            // Make $data available as local vars.
            extract($data, EXTR_SKIP);
            require $view;
        } else {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('Connections view file is missing.', 'wc-vector-indexing');
            echo '</p></div>';
        }
    }

    public function ajax_sample_upsert(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-vector-indexing')], 403);
        }
        $nonce = isset($_POST['_nonce']) ? (string) $_POST['_nonce'] : '';
        if (!\WCVec\Nonces::verify('sample_upsert', $nonce)) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'wc-vector-indexing')], 403);
        }

        $target     = isset($_POST['target']) ? sanitize_text_field((string) $_POST['target']) : '';
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

        if ($product_id <= 0) {
            // Fallback: first published product
            $q = new \WP_Query([
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
            $product_id = (int) ($q->posts[0] ?? 0);
        }

        if ($product_id <= 0) {
            wp_send_json_error(['message' => __('No product found to index.', 'wc-vector-indexing')]);
        }

        $selection = \WCVec\Options::get_selected_fields();
        $payloads  = \WCVec\Indexer::build_payloads($product_id, $selection);
        if (is_wp_error($payloads)) {
            wp_send_json_error(['message' => $payloads->get_error_message()]);
        }

        $adapter = \WCVec\Indexer::get_adapter($target);
        if (is_wp_error($adapter)) {
            wp_send_json_error(['message' => $adapter->get_error_message()]);
        }

        $valid = $adapter->validate();
        if (is_wp_error($valid)) {
            wp_send_json_error(['message' => $valid->get_error_message()]);
        }

        // If there are no payloads (empty content), return success with 0 upserts.
        if (empty($payloads)) {
            wp_send_json_success([
                'upserted' => 0,
                'message'  => __('No chunks to upsert (empty normalized content).', 'wc-vector-indexing'),
            ]);
        }

        $res = $adapter->upsert($payloads);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }

        // Build a small details preview: first & last IDs
        $ids = array_map(static fn($p) => (string) $p['id'], $payloads);
        $first = $ids[0] ?? '';
        $last  = end($ids) ?: '';

        wp_send_json_success([
            'upserted' => (int) ($res['upserted'] ?? 0),
            'product_id' => $product_id,
            'first_id' => $first,
            'last_id'  => $last,
            'message'  => sprintf(
                /* translators: 1: count */
                _n('%d vector upserted.', '%d vectors upserted.', (int) ($res['upserted'] ?? 0), 'wc-vector-indexing'),
                (int) ($res['upserted'] ?? 0)
            ),
        ]);
    }

    public function ajax_sample_delete(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wc-vector-indexing')], 403);
        }
        $nonce = isset($_POST['_nonce']) ? (string) $_POST['_nonce'] : '';
        if (!\WCVec\Nonces::verify('sample_delete', $nonce)) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'wc-vector-indexing')], 403);
        }

        $target     = isset($_POST['target']) ? sanitize_text_field((string) $_POST['target']) : '';
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

        if ($product_id <= 0) {
            wp_send_json_error(['message' => __('Provide a valid Product ID to delete.', 'wc-vector-indexing')]);
        }

        $adapter = \WCVec\Indexer::get_adapter($target);
        if (is_wp_error($adapter)) {
            wp_send_json_error(['message' => $adapter->get_error_message()]);
        }

        $valid = $adapter->validate();
        if (is_wp_error($valid)) {
            wp_send_json_error(['message' => $valid->get_error_message()]);
        }

        $res = $adapter->delete_by_product($product_id, (int) get_current_blog_id());
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }

        wp_send_json_success([
            'deleted'    => isset($res['deleted']) ? (int) $res['deleted'] : null,
            'product_id' => $product_id,
            'message'    => isset($res['deleted'])
                ? sprintf(_n('%d vector deleted.', '%d vectors deleted.', (int) $res['deleted'], 'wc-vector-indexing'), (int) $res['deleted'])
                : __('Delete requested. The vector store did not return a count.', 'wc-vector-indexing'),
        ]);
    }
}
