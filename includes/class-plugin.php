<?php
namespace WCVec;

defined('ABSPATH') || exit;

final class Plugin
{
    public const DB_VERSION = 1;

    /** @var Plugin|null */
    private static $instance = null;

    /** @var Admin|null */
    private $admin = null;

    private function __construct()
    {
        add_action('init', [$this, 'load_textdomain']);

        register_activation_hook(WC_VEC_FILE, [__CLASS__, 'on_activate']);
        register_deactivation_hook(WC_VEC_FILE, [__CLASS__, 'on_deactivate']);

        // Ensure schema is current
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade_schema']);

        // REST route
        require_once WC_VEC_DIR . 'includes/rest/class-rest-status.php';
        add_action('rest_api_init', ['WCVec\\REST\\Rest_Status', 'register']);

        require_once WC_VEC_DIR . 'includes/adapters/class-adapter-interface.php';
        require_once WC_VEC_DIR . 'includes/class-indexer.php';
        require_once WC_VEC_DIR . 'includes/class-storage.php';

        require_once WC_VEC_DIR . 'includes/adapters/class-adapter-interface.php';
        require_once WC_VEC_DIR . 'includes/adapters/class-pinecone-adapter.php';
        require_once WC_VEC_DIR . 'includes/adapters/class-openai-vectorstore-adapter.php';

        require_once WC_VEC_DIR . 'includes/jobs/class-job-index-product.php';
        require_once WC_VEC_DIR . 'includes/jobs/class-job-delete-product.php';
        require_once WC_VEC_DIR . 'includes/class-lifecycle.php';

        require_once WC_VEC_DIR . 'includes/class-scheduler.php';
        $this->scheduler = new \WCVec\Scheduler();

        require_once WC_VEC_DIR . 'includes/jobs/class-job-purge-site.php';
        add_action(\WCVec\Jobs\Job_Purge_Site::ACTION, ['\WCVec\Jobs\Job_Purge_Site', 'handle'], 10, 1);

        require_once WC_VEC_DIR . 'includes/class-events.php';

        if (is_admin()) {
            require_once WC_VEC_DIR . 'admin/pages/class-admin-page-advanced.php';
        }

        if (is_admin()) {
            require_once WC_VEC_DIR . 'includes/class-admin.php';
            $this->admin = new Admin();
            $this->lifecycle = new \WCVec\Lifecycle();
        }

        // WP-CLI (already added in P3.3)
        if (defined('WP_CLI') && WP_CLI) {
            require_once WC_VEC_DIR . 'includes/cli/class-cli.php';
            \WP_CLI::add_command('wcvec', 'WCVec\\CLI');
        }
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'wc-vector-indexing',
            false,
            dirname(plugin_basename(WC_VEC_FILE)) . '/languages'
        );
    }

    public static function on_activate(): void
    {
        self::create_tables();
        update_option('wcvec_schema_version', self::DB_VERSION);

        // Ensure a recurring scan is scheduled on activation if enabled.
        \WCVec\Scheduler::ensure_recurring();
    }

    public static function on_deactivate(): void
    {
        // No-op for now
    }

    private static function create_tables(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wcvec_objects';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            target varchar(16) NOT NULL,
            chunk_index int(11) unsigned NOT NULL,
            vector_id varchar(191) NOT NULL,
            product_sha char(64) NOT NULL,
            chunk_sha char(64) NOT NULL,
            model varchar(64) NOT NULL,
            dimension int(11) unsigned NOT NULL,
            remote_id varchar(191) NULL,
            status varchar(16) NOT NULL,
            error_code varchar(64) NULL,
            error_msg text NULL,
            last_synced_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_target_vector (target, vector_id),
            UNIQUE KEY uniq_target_product_chunk (target, product_id, chunk_index),
            KEY idx_product_target (product_id, target),
            KEY idx_product_sha (product_sha),
            KEY idx_status (status)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /** Optional: ensure table exists after updates */
    private static function maybe_upgrade_schema(): void
    {
        $stored = (int) get_option('wcvec_schema_version', 0);
        if ($stored < self::DB_VERSION) {
            self::create_tables();
            update_option('wcvec_schema_version', self::DB_VERSION);
        }
    }
}
