<?php
/**
 * About Admin Page
 *
 * @package WCVec
 */

namespace WCVec\Admin;

use WCVec\Options;
use WCVec\Nonces;

defined('ABSPATH') || exit;

class Admin_Page_About {

    public function __construct() {
        add_action('admin_init', [$this, 'handle_post']);
    }

    /**
     * Handle About form submissions.
     */
    public function handle_post(): void {
        if (!is_admin()) {
            return;
        }

        $is_our_page = isset($_GET['page']) && $_GET['page'] === 'wcvec'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_tab_about = isset($_GET['tab']) && $_GET['tab'] === 'about'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$is_our_page || !$is_tab_about) {
            return;
        }

        if (!isset($_POST['wcvec_action']) || $_POST['wcvec_action'] !== 'save_about') { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to update these settings.', 'wc-vector-indexing'));
        }

        check_admin_referer('wcvec_about_save', 'wcvec_nonce');

        // Company basics
        $company = isset($_POST['wcvec_about_company_name']) ? (string) $_POST['wcvec_about_company_name'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $blurb   = isset($_POST['wcvec_about_company_blurb']) ? (string) $_POST['wcvec_about_company_blurb'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $logo    = isset($_POST['wcvec_about_company_logo_url']) ? (string) $_POST['wcvec_about_company_logo_url'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $support = isset($_POST['wcvec_about_support_url']) ? (string) $_POST['wcvec_about_support_url'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $email   = isset($_POST['wcvec_about_contact_email']) ? (string) $_POST['wcvec_about_contact_email'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        Options::set_about_company_name($company);
        Options::set_about_blurb($blurb);           // sanitize on render
        Options::set_about_logo_url($logo);
        Options::set_about_support_url($support);
        Options::set_about_contact_email($email);

        // Products repeater
        $products = isset($_POST['wcvec_about_products']) && is_array($_POST['wcvec_about_products'])
            ? $_POST['wcvec_about_products']
            : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        Options::set_about_products($products); // deep sanitation handled inside

        add_settings_error('wcvec', 'wcvec_about_saved', __('About settings saved.', 'wc-vector-indexing'), 'updated');

        // Redirect to avoid resubmission.
        $redirect = add_query_arg(
            ['page' => 'wcvec', 'tab' => 'about', 'settings-updated' => '1'],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Render the About tab.
     */
    public function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-vector-indexing'));
        }

        // Current values
        $data = [
            'company_name' => Options::get_about_company_name(),
            'blurb'        => Options::get_about_blurb(),
            'logo_url'     => Options::get_about_logo_url(),
            'support_url'  => Options::get_about_support_url(),
            'contact_email'=> Options::get_about_contact_email(),
            'products'     => Options::get_about_products(),
        ];

        settings_errors('wcvec');

        $view = WC_VEC_DIR . 'admin/views/about.php';
        if (file_exists($view)) {
            extract($data, EXTR_SKIP);
            require $view;
        } else {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('About view file is missing.', 'wc-vector-indexing');
            echo '</p></div>';
        }
    }
}
