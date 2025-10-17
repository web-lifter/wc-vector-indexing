<?php
/**
 * Scheduler: ensures recurring scans are scheduled, with WP-Cron fallback.
 * Actual scanning logic will be implemented in Phase 6.2.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

final class Scheduler
{
    public const ACTION_SCAN = 'wcvec/scan_changes';
    public const GROUP       = 'wcvec';
    public const CRON_HOOK   = 'wcvec_scan_changes_event'; // WP-Cron fallback hook

    public function __construct()
    {
        // Ensure schedules exist when options change or plugin loads.
        add_action('plugins_loaded', [__CLASS__, 'ensure_recurring']);

        // Register handlers (both runners call the same method).
        add_action(self::ACTION_SCAN, [__CLASS__, 'run_scan']);
        add_action(self::CRON_HOOK,   [__CLASS__, 'run_scan']);

        // Provide 5min/15min intervals for WP-Cron fallback.
        add_filter('cron_schedules', [__CLASS__, 'register_cron_intervals']);
    }

    /**
     * Ensure the recurring scan task is scheduled (or unscheduled) according to options.
     */
    public static function ensure_recurring(): void
    {
        $enabled = Options::auto_sync_enabled();
        $cadence = Options::get_scheduler_cadence();

        // Unschedule everything first if disabled.
        if (!$enabled) {
            self::unschedule_all();
            return;
        }

        // Action Scheduler preferred
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            // If not already scheduled (pending or recurring), schedule it.
            if (!as_has_scheduled_action(self::ACTION_SCAN, [], self::GROUP)) {
                $interval = self::cadence_to_seconds($cadence);
                $start = time() + 60; // start in ~1 minute
                as_schedule_recurring_action($start, $interval, self::ACTION_SCAN, [], self::GROUP);
            }
            return;
        }

        // Fallback: WP-Cron — schedule named event with our custom intervals.
        $hook = self::CRON_HOOK;
        if (!wp_next_scheduled($hook)) {
            $recurrence = self::cadence_to_cron_name($cadence);
            if (!has_filter('cron_schedules')) {
                // no-op; WP has defaults hourly/twicedaily/daily
            }
            wp_schedule_event(time() + 60, $recurrence, $hook);
        }
    }

    /**
     * Unschedule any existing recurring actions/events.
     */
    public static function unschedule_all(): void
    {
        // Action Scheduler unschedule
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION_SCAN, [], self::GROUP);
        }
        // WP-Cron unschedule
        $ts = wp_next_scheduled(self::CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
            $ts = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /**
     * Trigger a scan ASAP (enqueue a one-off).
     */
    public static function run_now(): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_SCAN, [], self::GROUP);
            return;
        }
        wp_schedule_single_event(time() + 5, self::CRON_HOOK);
    }

    /**
     * Requeue all error products (placeholder — filled in P6.2).
     */
    public static function requeue_errors(): void
    {
        // Implemented in P6.2: find product_ids with error rows and enqueue force reindex.
    }

    /**
     * The scan handler: decide what to enqueue based on changes, errors, and initial sync.
     */
    public static function run_scan(): void
    {
        // If autosync is paused, just stamp last scan and leave.
        if (!Options::auto_sync_enabled()) {
            Options::set_last_scan_gmt(gmdate('c'));
            return;
        }

        $limit     = Options::get_scan_batch_limit();
        $max_conc  = Options::get_max_concurrent_jobs();

        // Compute current in-progress count (Action Scheduler only)
        $in_progress = self::count_actions(Job_Index_Product::ACTION, 'in-progress');
        $quota = max(0, $max_conc - $in_progress);
        if ($quota <= 0) {
            // Still update last scan (we ran) and bail
            Options::set_last_scan_gmt(gmdate('c'));
            do_action('wcvec/scan_enqueued', [], [
                'quota' => $quota, 'in_progress' => $in_progress, 'limit' => $limit,
                'errors_considered' => 0, 'modified_considered' => 0, 'initial_considered' => 0,
                'enqueued' => 0,
            ]);
            return;
        }

        // Since timestamp (GMT) — default to last 48h if never set
        $last_iso = Options::get_last_scan_gmt();
        $since_ts = $last_iso ? (int) strtotime($last_iso) : (time() - 2 * DAY_IN_SECONDS);
        $since_gmt = gmdate('Y-m-d H:i:s', $since_ts);

        // Gather candidate lists (ordered by importance: errors → modified → initial)
        $err_ids = Storage::get_product_ids_with_errors($limit);
        $mod_ids = Storage::get_product_ids_modified_since($since_gmt, $limit);
        $ini_ids = Storage::get_product_ids_needing_initial_sync($limit);

        // Combine with stable priority and de-duplicate
        $combined = [];
        $seen = [];
        foreach ([$err_ids, $mod_ids, $ini_ids] as $list) {
            foreach ($list as $pid) {
                $pid = (int) $pid;
                if ($pid > 0 && !isset($seen[$pid])) {
                    $seen[$pid] = true;
                    $combined[] = $pid;
                }
            }
        }

        // Obey quota and scan limit
        $to_take = min($limit, $quota);
        $enq_ids = array_slice($combined, 0, $to_take);

        foreach ($enq_ids as $pid) {
            Job_Index_Product::enqueue((int) $pid, false, 0);
        }

        Options::set_last_scan_gmt(gmdate('c'));

        do_action('wcvec/scan_enqueued', $enq_ids, [
            'quota' => $quota,
            'in_progress' => $in_progress,
            'limit' => $limit,
            'errors_considered' => count($err_ids),
            'modified_considered' => count($mod_ids),
            'initial_considered' => count($ini_ids),
            'enqueued' => count($enq_ids),
        ]);
    }

    /**
     * Count actions by status for a given hook/group if Action Scheduler is available.
     * Returns 0 gracefully when AS is not installed (WP-Cron fallback).
     */
    private static function count_actions(string $hook, string $status): int
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return 0;
        }
        // Small cap to avoid heavy loading; we're only deriving a quota.
        $actions = as_get_scheduled_actions([
            'hook'      => $hook,
            'group'     => self::GROUP,
            'status'    => $status,
            'per_page'  => 250,
            'offset'    => 0,
            'orderby'   => 'date',
            'order'     => 'ASC',
        ], 'ids');

        return is_array($actions) ? count($actions) : 0;
    }

    /**
     * Map cadence enum to seconds.
     */
    private static function cadence_to_seconds(string $cadence): int
    {
        switch ($cadence) {
            case '5min':      return 5 * 60;
            case '15min':     return 15 * 60;
            case 'hourly':    return 60 * 60;
            case 'twicedaily':return 12 * 60 * 60;
            case 'daily':     return 24 * 60 * 60;
            default:          return 15 * 60;
        }
    }

    /**
     * Map cadence to WP-Cron schedule name.
     */
    private static function cadence_to_cron_name(string $cadence): string
    {
        switch ($cadence) {
            case '5min':   return 'every_5_minutes';
            case '15min':  return 'every_15_minutes';
            case 'hourly': return 'hourly';
            case 'twicedaily': return 'twicedaily';
            case 'daily':  return 'daily';
            default:       return 'every_15_minutes';
        }
    }

    /**
     * Add custom intervals for WP-Cron fallback.
     */
    public static function register_cron_intervals(array $schedules): array
    {
        if (!isset($schedules['every_5_minutes'])) {
            $schedules['every_5_minutes'] = [
                'interval' => 5 * 60,
                'display'  => __('Every 5 Minutes (WCVec)', 'wc-vector-indexing'),
            ];
        }
        if (!isset($schedules['every_15_minutes'])) {
            $schedules['every_15_minutes'] = [
                'interval' => 15 * 60,
                'display'  => __('Every 15 Minutes (WCVec)', 'wc-vector-indexing'),
            ];
        }
        return $schedules;
    }
    
    public static function requeue_errors(): void
    {
        $limit = 1000;
        $ids = Storage::get_product_ids_with_errors($limit);
        foreach ($ids as $pid) {
            Job_Index_Product::enqueue((int) $pid, true, 0); // force reindex
        }
    }

    public static function get_metrics(): array
    {
        $last = Options::get_last_scan_gmt() ?: null;

        $next = null;
        if (function_exists('as_next_scheduled_action')) {
            $ts = as_next_scheduled_action(self::ACTION_SCAN, [], self::GROUP);
            $next = $ts ? gmdate('c', $ts) : null;
        } else {
            $ts = wp_next_scheduled(self::CRON_HOOK);
            $next = $ts ? gmdate('c', $ts) : null;
        }

        // Action Scheduler queue stats (if available)
        $pending = $inprog = $failed = $completed24 = $failed24 = null;
        if (function_exists('as_get_scheduled_actions')) {
            $pending   = self::count_actions(Job_Index_Product::ACTION, 'pending');
            $inprog    = self::count_actions(Job_Index_Product::ACTION, 'in-progress');
            $failed    = self::count_actions(Job_Index_Product::ACTION, 'failed');

            // Past 24h completions/failures (best-effort)
            $after = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
            $completed24 = count(as_get_scheduled_actions([
                'hook'   => Job_Index_Product::ACTION,
                'group'  => self::GROUP,
                'status' => 'complete',
                'date'   => $after, // Action Scheduler accepts 'date' with "after" semantics
                'per_page' => 250,
            ], 'ids'));
            $failed24 = count(as_get_scheduled_actions([
                'hook'   => Job_Index_Product::ACTION,
                'group'  => self::GROUP,
                'status' => 'failed',
                'date'   => $after,
                'per_page' => 250,
            ], 'ids'));
        }

        // Backlog estimate using storage helpers
        $limit = Options::get_scan_batch_limit();
        $since_iso = $last ?: gmdate('c', time() - 2 * DAY_IN_SECONDS);
        $since_gmt = gmdate('Y-m-d H:i:s', strtotime($since_iso));

        $err  = count(Storage::get_product_ids_with_errors($limit));
        $mod  = count(Storage::get_product_ids_modified_since($since_gmt, $limit));
        $init = count(Storage::get_product_ids_needing_initial_sync($limit));
        $backlog_est = min($limit, $err + $mod + $init);

        return [
            'auto_sync'        => Options::auto_sync_enabled(),
            'cadence'          => Options::get_scheduler_cadence(),
            'max_concurrent'   => Options::get_max_concurrent_jobs(),
            'scan_batch_limit' => Options::get_scan_batch_limit(),
            'acf_hook'         => Options::acf_hook_enabled(),

            'last_scan'        => $last,
            'next_scan'        => $next,

            'queue' => [
                'pending'     => $pending,
                'in_progress' => $inprog,
                'failed'      => $failed,
                'completed_24h' => $completed24,
                'failed_24h'    => $failed24,
            ],

            'backlog_estimate' => $backlog_est,
        ];
    }
}
