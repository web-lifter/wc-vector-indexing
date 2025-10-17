<?php
namespace WCVec\Jobs;

use WCVec\Events;
use WCVec\Options;
use WCVec\Storage;
use WCVec\Adapters\Pinecone_Adapter;
use WCVec\Adapters\OpenAI_VectorStore_Adapter;
use WP_Error;

defined('ABSPATH') || exit;

final class Job_Purge_Site
{
    public const ACTION = 'wcvec/purge_site';
    public const GROUP  = 'wcvec';

    /** Deduped enqueue. */
    public static function enqueue(int $delay_seconds = 0): void
    {
        $args = ['site_id' => (int) get_current_blog_id()];
        if (function_exists('as_has_scheduled_action')) {
            if (as_has_scheduled_action(self::ACTION, $args, self::GROUP)) return;
        } else {
            if (wp_next_scheduled(self::ACTION, $args)) return;
        }

        $ts = time() + max(0, (int) $delay_seconds);
        Events::log('purge_site', 'info', 'Site purge scheduled', ['details'=>$args]);

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($ts, self::ACTION, $args, self::GROUP);
        } else {
            wp_schedule_single_event($ts, self::ACTION, $args);
        }
    }

    /** Handler. */
    public static function handle(int $site_id): void
    {
        Events::log('purge_site', 'info', 'Starting site purge', ['details'=>['site_id'=>$site_id]]);

        $adapters = apply_filters('wcvec_adapters_for_purge', [
            'pinecone' => new Pinecone_Adapter(),
            'openai'   => new OpenAI_VectorStore_Adapter(),
        ]);

        $errors = [];
        $deleted_total = 0;

        foreach ($adapters as $key => $adapter) {
            if (!is_object($adapter)) continue;
            $res = $adapter->purge_site($site_id);
            if (is_wp_error($res)) {
                $errors[] = $res;
                continue;
            }
            $deleted_total += (int) ($res['deleted'] ?? 0);
        }

        // Local cleanup
        $local_deleted = Storage::delete_all_for_site($site_id);
        $deleted_total += (int) $local_deleted;

        // Remove event logs directory (best-effort)
        $upload = wp_get_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'wcvec';
        if (is_dir($dir)) {
            // Delete only JSONL logs; keep dir if remove fails
            foreach (glob($dir . '/logs-*.jsonl') ?: [] as $file) {
                @unlink($file);
            }
        }

        if (!empty($errors)) {
            Events::log('purge_site', 'error', 'Site purge completed with errors', [
                'details' => array_map(fn($e)=>['code'=>$e->get_error_code(),'msg'=>$e->get_error_message()], $errors),
                'count'   => $deleted_total,
            ]);
        } else {
            Events::log('purge_site', 'success', 'Site purge completed', [
                'count' => $deleted_total,
            ]);
        }
    }
}
