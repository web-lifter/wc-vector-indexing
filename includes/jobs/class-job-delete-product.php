<?php
/**
 * Job: purge a product from all enabled vector stores
 *
 * @package WCVec
 */

namespace WCVec\Jobs;

use WCVec\Options;
use WCVec\Indexer;
use WCVec\Storage;
use WP_Error;

defined('ABSPATH') || exit;

final class Job_Delete_Product
{
    public const ACTION = 'wcvec/delete_product';
    public const GROUP  = 'wcvec';

    public static function enqueue(int $product_id, int $delay_seconds = 0): void
    {
        $args = [ 'product_id' => (int) $product_id, 'attempt' => 1 ];

        if (function_exists('as_has_scheduled_action')) {
            if (as_has_scheduled_action(self::ACTION, $args, self::GROUP)) {
                return;
            }
        } else {
            if (wp_next_scheduled(self::ACTION, $args)) {
                return;
            }
        }

        $ts = time() + max(0, (int) $delay_seconds);

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($ts, self::ACTION, $args, self::GROUP);
        } else {
            wp_schedule_single_event($ts, self::ACTION, $args);
        }
    }

    public static function handle(int $product_id, int $attempt = 1): void
    {
        $site_id = (int) get_current_blog_id();
        $targets = Options::get_targets_enabled();

        $errors = [];
        $deleted_total = 0;

        foreach ($targets as $target) {
            $adapter = Indexer::get_adapter($target);
            if (is_wp_error($adapter)) {
                $errors[] = $adapter;
                continue;
            }

            $v = $adapter->validate();
            if (is_wp_error($v)) {
                $errors[] = $v;
                continue;
            }

            $res = $adapter->delete_by_product($product_id, $site_id);
            if (is_wp_error($res)) {
                $errors[] = $res;
            } else {
                $deleted_total += (int) ($res['deleted'] ?? 0);
            }

            // Local cleanup for this target regardless of remote count response
            Storage::delete_all_for_product_target($product_id, $target);
        }

        if (!empty($errors)) {
            $transient = false;
            foreach ($errors as $e) {
                if (self::is_transient_error($e)) { $transient = true; break; }
            }
            if ($transient && $attempt < 3) {
                self::enqueue($product_id, (int) ceil(pow(3, $attempt - 1) * 60));
            }
            do_action('wcvec/delete_product_error', $product_id, $errors, $attempt);
            return;
        }

        do_action('wcvec/delete_product_success', $product_id, $deleted_total, $attempt);
    }

    private static function is_transient_error(WP_Error $e): bool
    {
        $code = (string) $e->get_error_code();
        $msg  = (string) $e->get_error_message();
        if (strpos($code, 'http_error') !== false) return true;
        if (strpos($msg, '429') !== false || stripos($msg, 'Too Many Requests') !== false) return true;
        if (stripos($msg, 'timeout') !== false) return true;
        return false;
    }
}

add_action(Job_Delete_Product::ACTION, [Job_Delete_Product::class, 'handle'], 10, 2);
