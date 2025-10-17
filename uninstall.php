<?php
/**
 * WC Vector Indexing — Uninstall
 *
 * Cleans options, tables, schedules, and (optionally) purges remote vectors.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/**
 * ===== Helpers =====
 */

function wcvec_uninstall_log($msg) {
    // Lightweight debug hook; comment out if too chatty.
    // error_log('[WCVec Uninstall] ' . $msg);
}

function wcvec_uninstall_get_all_wcvec_options($blog_id = null) {
    global $wpdb;
    if (function_exists('switch_to_blog') && $blog_id !== null) switch_to_blog($blog_id);

    // Explicit keys we know about (keep in sync with Options class).
    $keys = [
        // Connections
        'wcvec_api_openai_key',
        'wcvec_api_pinecone_key',
        'wcvec_pinecone_env',
        'wcvec_pinecone_project',
        'wcvec_pinecone_index',
        'wcvec_openai_vectorstore_id',
        'wcvec_embedding_model',
        'wcvec_embedding_dimension',

        // Field selection + preview
        'wcvec_selected_fields',

        // Scheduler
        'wcvec_auto_sync_enabled',
        'wcvec_scheduler_cadence',
        'wcvec_max_concurrent_jobs',
        'wcvec_scan_batch_limit',
        'wcvec_last_scan_gmt',
        'wcvec_acf_hook_enabled',

        // Advanced
        'wcvec_batch_upsert_size',
        'wcvec_include_drafts_private',
        'wcvec_variation_strategy',
        'wcvec_manual_include_variations',
        'wcvec_event_log_retention_days',
        'wcvec_allow_dimension_override',
        'wcvec_rollup_max_variations',
        'wcvec_rollup_values_cap',
        'wcvec_uninstall_remote_purge',

        // About
        'wcvec_about_company_name',
        'wcvec_about_company_blurb',
        'wcvec_about_company_logo_url',
        'wcvec_about_products',
        'wcvec_about_support_url',
        'wcvec_about_contact_email',

        // Misc/schema
        'wcvec_schema_version',
    ];

    // Fallback: also sweep any wcvec_* options we might have missed
    $like = $wpdb->esc_like('wcvec_') . '%';
    $dynamic = $wpdb->get_col(
        $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like)
    );
    $all = array_values(array_unique(array_merge($keys, $dynamic)));

    if (function_exists('restore_current_blog') && $blog_id !== null) restore_current_blog();
    return $all;
}

function wcvec_uninstall_unschedule_all_for_site($blog_id = null) {
    if (function_exists('switch_to_blog') && $blog_id !== null) switch_to_blog($blog_id);

    // Action Scheduler (if present)
    if (function_exists('as_unschedule_all_actions')) {
        foreach ([
            'wcvec/scan_changes',
            'wcvec/index_product',
            'wcvec/delete_product',
            'wcvec/purge_site',
        ] as $hook) {
            @as_unschedule_all_actions($hook, [], 'wcvec');
        }
    }

    // WP-Cron fallback — unschedule all occurrences for our hooks
    foreach ([
        'wcvec_scan_changes_event', // scheduler fallback
        'wcvec/scan_changes',
        'wcvec/index_product',
        'wcvec/delete_product',
        'wcvec/purge_site',
    ] as $hook) {
        $ts = wp_next_scheduled($hook);
        while ($ts) {
            wp_unschedule_event($ts, $hook);
            $ts = wp_next_scheduled($hook);
        }
    }

    if (function_exists('restore_current_blog') && $blog_id !== null) restore_current_blog();
}

function wcvec_uninstall_drop_tables_for_site($blog_id = null) {
    global $wpdb;
    if (function_exists('switch_to_blog') && $blog_id !== null) switch_to_blog($blog_id);

    $table = $wpdb->get_blog_prefix() . 'wcvec_objects';
    $wpdb->query("DROP TABLE IF EXISTS {$table}");

    if (function_exists('restore_current_blog') && $blog_id !== null) restore_current_blog();
}

function wcvec_uninstall_delete_options_for_site($blog_id = null) {
    if (function_exists('switch_to_blog') && $blog_id !== null) switch_to_blog($blog_id);

    $keys = wcvec_uninstall_get_all_wcvec_options();
    foreach ($keys as $k) {
        delete_option($k);
    }

    // Transients and misc
    delete_transient('wcvec_events_last_prune');

    if (function_exists('restore_current_blog') && $blog_id !== null) restore_current_blog();
}

function wcvec_uninstall_delete_logs_for_site($blog_id = null) {
    if (function_exists('switch_to_blog') && $blog_id !== null) switch_to_blog($blog_id);

    $upload = wp_get_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'wcvec';
    if (is_dir($dir)) {
        // Delete JSONL files then remove dir (best-effort)
        $files = glob($dir . '/*');
        if (is_array($files)) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    if (function_exists('restore_current_blog') && $blog_id !== null) restore_current_blog();
}

/**
 * Optional remote purge per site using adapters, best-effort.
 * Only runs if wcvec_uninstall_remote_purge == true for that site.
 */
function wcvec_uninstall_remote_purge_for_site($blog_id = null) {
    if (function_exists('switch_to_blog') && $blog_id !== null) switch_to_blog($blog_id);

    $do_purge = (bool) get_option('wcvec_uninstall_remote_purge', false);
    if (!$do_purge) {
        if (function_exists('restore_current_blog') && $blog_id !== null) restore_current_blog();
        return;
    }

    $plugin_dir = dirname(__FILE__);

    // Load minimal dependencies (best-effort)
    @require_once $plugin_dir . '/includes/class-events.php';
    @require_once $plugin_dir . '/includes/class-secure-options.php';
    @require_once $plugin_dir . '/includes/class-options.php';
    @require_once $plugin_dir . '/includes/class-http.php';
    @require_once $plugin_dir . '/includes/adapters/class-adapter-interface.php';
    @require_once $plugin_dir . '/includes/adapters/class-pinecone-adapter.php';
    @require_once $plugin_dir . '/includes/adapters/class-openai-vectorstore-adapter.php';

    if (class_exists('\WCVec\Adapters\Pinecone_Adapter')) {
        try {
            $pine = new \WCVec\Adapters\Pinecone_Adapter();
            if (method_exists($pine, 'purge_site')) {
                $pine->purge_site(get_current_blog_id());
            }
        } catch (\Throwable $e) {
            wcvec_uninstall_log('Pinecone purge error: ' . $e->getMessage());
        }
    }

    if (class_exists('\WCVec\Adapters\OpenAI_VectorStore_Adapter')) {
        try {
            $oa = new \WCVec\Adapters\OpenAI_VectorStore_Adapter();
            if (method_exists($oa, 'purge_site')) {
                $oa->purge_site(get_current_blog_id());
            }
        } catch (\Throwable $e) {
            wcvec_uninstall_log('OpenAI VS purge error: ' . $e->getMessage());
        }
    }

    if (function_exists('restore_current_blog') && $blog_id !== null) restore_current_blog();
}

/**
 * Per-site uninstall flow.
 */
function wcvec_uninstall_site($blog_id = null) {
    wcvec_uninstall_unschedule_all_for_site($blog_id);
    wcvec_uninstall_remote_purge_for_site($blog_id); // optional
    wcvec_uninstall_drop_tables_for_site($blog_id);
    wcvec_uninstall_delete_options_for_site($blog_id);
    wcvec_uninstall_delete_logs_for_site($blog_id);
}

/**
 * ===== Execute for single site or network =====
 */
if (is_multisite()) {
    // If removed from Network Admin, clean all blogs
    $sites = get_sites(['fields' => 'ids']);
    foreach ($sites as $sid) {
        wcvec_uninstall_site((int) $sid);
    }
} else {
    wcvec_uninstall_site(null);
}
