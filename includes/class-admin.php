<?php
namespace WCVec;

use WCVec\Nonces;

defined('ABSPATH') || exit;

class Admin
{
    /** @var string Slug for our top-level page (as a WooCommerce submenu). */
    private $menu_slug = 'wcvec';

    /** @var array */
    private $tabs = [
        'connections' => 'Connections',
        'fields'      => 'Fields',
        'sync'        => 'Sync',
        'logs'        => 'Logs',
        'advanced'    => 'Advanced',
        'about'       => 'About',
    ];

    /** @var \WCVec\Admin\Admin_Page_Connections */
    private $connections_page;

    /** @var \WCVec\Admin\Admin_Page_About */
    private $about_page;

    /** @var \WCVec\Admin\Admin_Page_Fields */
    private $fields_page;

    /** @var \WCVec\Admin\Page_Logs */
    private $logs_page;

    public function __construct()
    {
        require_once WC_VEC_DIR . 'admin/pages/class-admin-page-connections.php';
        $this->connections_page = new \WCVec\Admin\Admin_Page_Connections();

        require_once WC_VEC_DIR . 'admin/pages/class-admin-page-about.php';
        $this->about_page = new \WCVec\Admin\Admin_Page_About();

        require_once WC_VEC_DIR . 'admin/pages/class-admin-page-fields.php';
        $this->fields_page = new \WCVec\Admin\Admin_Page_Fields();

        require_once WC_VEC_DIR . 'admin/pages/class-admin-page-logs.php';
        $this->pages['logs'] = new \WCVec\Admin\Page_Logs();

        require_once WC_VEC_DIR . 'admin/pages/class-admin-page-sync.php';
        $this->pages['sync'] = new \WCVec\Admin\Page_Sync();

        require_once WC_VEC_DIR . 'admin/class-products-actions.php';
        $this->products_actions = new \WCVec\Admin\Products_Actions();

        require_once WC_VEC_DIR . 'admin/pages/class-admin-page-advanced.php';
        $this->pages['advanced'] = new \WCVec\Admin\Page_Advanced();

        add_action('admin_menu', [$this, 'register_menu'], 60);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void
    {
        // Parent is WooCommerce main menu if present; otherwise use 'options-general.php' fallback.
        $parent_slug = class_exists('\WooCommerce') ? 'woocommerce' : 'options-general.php';

        add_submenu_page(
            $parent_slug,
            esc_html__('Vector Indexing', 'wc-vector-indexing'),
            esc_html__('Vector Indexing', 'wc-vector-indexing'),
            'manage_woocommerce',
            $this->menu_slug,
            [$this, 'render_page'],
            56 // position under Woo settings-ish
        );
    }

    public function enqueue_assets($hook): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== $this->menu_slug) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        wp_enqueue_style('wcvec-admin', WC_VEC_URL . 'assets/admin.css', [], WC_VEC_VERSION);

        // Needed by About (sortable) and Fields (autocomplete).
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('jquery-ui-autocomplete');

        wp_enqueue_script('wcvec-admin', WC_VEC_URL . 'assets/admin.js', ['jquery', 'jquery-ui-sortable', 'jquery-ui-autocomplete'], WC_VEC_VERSION, true);

        // inside enqueue_assets(), extend the wp_localize_script payload:
        wp_localize_script('wcvec-admin', 'WCVecAdmin', [
            'ajaxUrl'                 => admin_url('admin-ajax.php'),
            'nonceValidateOpenAI'     => Nonces::create('validate_openai'),
            'nonceValidatePinecone'   => Nonces::create('validate_pinecone'),
            // NEW for P4.4
            'nonceSampleUpsert'       => Nonces::create('sample_upsert'),
            'nonceSampleDelete'       => Nonces::create('sample_delete'),
            'i18n' => [
                'validating' => __('Validating…', 'wc-vector-indexing'),
                'searching'  => __('Searching…', 'wc-vector-indexing'),
                'previewing' => __('Generating preview…', 'wc-vector-indexing'),
                'working'    => __('Working…', 'wc-vector-indexing'),
            ],
        ]);

    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-vector-indexing'));
        }

        $active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connections'; // phpcs:ignore WordPress.Security.NonceVerification

        if (!array_key_exists($active, $this->tabs)) {
            $active = 'connections';
        }

        echo '<div class="wrap wcvec-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Vector Indexing', 'wc-vector-indexing') . '</h1>';
        $this->render_tabs($active);
        echo '<div class="wcvec-tab-content">';
        $this->render_tab_content($active);
        echo '</div></div>';
    }

    private function render_tabs(string $active): void
    {
        echo '<h2 class="nav-tab-wrapper wcvec-tabs">';
        foreach ($this->tabs as $slug => $label) {
            $url = add_query_arg(
                ['page' => $this->menu_slug, 'tab' => $slug],
                admin_url('admin.php')
            );
            $class = 'nav-tab' . ($slug === $active ? ' nav-tab-active' : '');
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url($url),
                esc_attr($class),
                esc_html__($label, 'wc-vector-indexing')
            );
        }
        echo '</h2>';
    }

    private function render_tab_content(string $tab): void
    {
        switch ($tab) {
            case 'connections':
                $this->connections_page->render();
                break;
            case 'about':
                $this->about_page->render();
                break;
            case 'fields':
                $this->fields_page->render();
                break;
            case 'sync':
            case 'logs':
            case 'advanced':
            default:
                $this->render_coming_soon($tab);
                break;
        }
    }

    private function render_coming_soon(string $tab): void
    {
        $label = $this->tabs[$tab] ?? ucfirst($tab);
        echo '<div class="card">';
        printf(
            '<h2>%s</h2><p>%s</p>',
            esc_html__($label, 'wc-vector-indexing'),
            esc_html__('Coming soon in later phases.', 'wc-vector-indexing')
        );
        echo '</div>';
    }
}
