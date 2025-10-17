<?php
/**
 * Job: index (sync) a single product
 *
 * @package WCVec
 */

namespace WCVec\Jobs;

use WCVec\Indexer;
use WCVec\Options;
use WP_Error;

defined('ABSPATH') || exit;

final class Job_Index_Product
{
    public const ACTION = 'wcvec/index_product';
    public const GROUP  = 'wcvec';

    /**
     * Schedule (deduped) — now logs an event and supports explicit attempt.
     *
     * @param int  $product_id
     * @param bool $force
     * @param int  $delay_seconds
     * @param int  $attempt          // NEW: for retries; defaults to 1
     */
    public static function enqueue(int $product_id, bool $force = false, int $delay_seconds = 0, int $attempt = 1): void
    {
        $args = [
            'product_id' => (int) $product_id,
            'force'      => (bool) $force,
            'attempt'    => (int) $attempt,
        ];

        // Dedup (best-effort with exact args)
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

        // Event: enqueue
        \WCVec\Events::log('job_enqueue', 'info', 'Index product scheduled', [
            'product_id'  => $product_id,
            'details'     => [
                'force'    => (bool) $force,
                'attempt'  => (int) $attempt,
                'delay_s'  => (int) $delay_seconds,
            ],
        ]);

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($ts, self::ACTION, $args, self::GROUP);
        } else {
            wp_schedule_single_event($ts, self::ACTION, $args);
        }
    }

    /**
     * Handler invoked by Action Scheduler / WP-Cron.
     *
     * @param int  $product_id
     * @param bool $force
     * @param int  $attempt
     */
    public static function handle(int $product_id, bool $force = false, int $attempt = 1): void
    {
        $selection = \WCVec\Options::get_selected_fields();

        // Allow tests/plugins to inject adapters (e.g., fake adapters for unit tests).
        $adapters = apply_filters('wcvec_adapters_for_sync', null, $product_id);

        $started = microtime(true);
        $res = Indexer::sync_product(
            $product_id,
            $selection,
            is_array($adapters) ? $adapters : null,
            $force
        );
        $duration_ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($res)) {
            // Log error outcome
            \WCVec\Events::log('job', 'error', $res->get_error_message(), [
                'product_id'  => $product_id,
                'duration_ms' => $duration_ms,
                'details'     => [
                    'code'    => $res->get_error_code(),
                    'attempt' => (int) $attempt,
                    'force'   => (bool) $force,
                ],
            ]);

            // Retry transient errors with exponential backoff (1m, 3m)
            if (self::is_transient_error($res) && $attempt < 3) {
                $delay = (int) ceil(pow(3, $attempt - 1) * 60); // 1m, 3m
                self::enqueue($product_id, $force, $delay, $attempt + 1);
            }

            do_action('wcvec/index_product_error', $product_id, $res, $attempt);
            return;
        }

        // Aggregate counts for logging
        $upserted = 0;
        $deleted  = 0;
        foreach ((array) $res as $summary) {
            $upserted += (int) ($summary['upserted'] ?? 0);
            $deleted  += (int) ($summary['deleted'] ?? 0);
        }

        // Log success outcome
        \WCVec\Events::log('job', 'success', "Indexed (upserted={$upserted}, deleted={$deleted})", [
            'product_id'  => $product_id,
            'duration_ms' => $duration_ms,
            'details'     => $res, // summarizes per-target results (no secrets)
        ]);

        do_action('wcvec/index_product_success', $product_id, $res, $attempt);
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

// Wire handler for either Action Scheduler or WP-Cron.
add_action(Job_Index_Product::ACTION, [Job_Index_Product::class, 'handle'], 10, 3);
