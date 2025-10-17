<?php
/**
 * JSONL-based event logger for WCVec
 *
 * Writes to: wp-content/uploads/wcvec/logs-YYYYMMDD.jsonl
 * Retention: wcvec_event_log_retention_days (default 7)
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

final class Events
{
    /** Log an event. $action and $outcome are short identifiers, $message is human-friendly. */
    public static function log(string $action, string $outcome, string $message, array $context = []): void
    {
        // Context normalization
        $row = [
            'ts'         => gmdate('c'),
            'site_id'    => (int) get_current_blog_id(),
            'product_id' => isset($context['product_id']) ? (int) $context['product_id'] : null,
            'target'     => isset($context['target']) ? (string) $context['target'] : null,
            'action'     => (string) $action,   // e.g. job, upsert, delete, validate_openai, validate_pinecone, scan
            'outcome'    => (string) $outcome,  // success|error|info
            'message'    => (string) $message,
            'duration_ms'=> isset($context['duration_ms']) ? (int) $context['duration_ms'] : null,
            'count'      => isset($context['count']) ? (int) $context['count'] : null,
            'request_id' => isset($context['request_id']) ? (string) $context['request_id'] : null,
            'details'    => isset($context['details']) ? $context['details'] : null, // any JSON-serializable value
        ];

        $dir  = self::dir();
        if (!wp_mkdir_p($dir)) {
            return; // best-effort
        }

        $file = self::file_for_day(gmdate('Ymd'));
        $line = wp_json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        // Write (append) — suppress warnings if FS is not writable
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        self::maybe_prune();
    }

    /** Read recent events with basic filters and pagination. */
    public static function read_recent(array $filters = [], int $limit = 50, int $page = 1): array
    {
        $limit = max(1, min(500, (int) $limit));
        $page  = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $days = self::retention_days();
        $dates = [];
        for ($i=0; $i<$days; $i++) {
            $dates[] = gmdate('Ymd', time() - $i * DAY_IN_SECONDS);
        }

        $rows = [];
        $scanned = 0;

        foreach ($dates as $ymd) {
            $file = self::file_for_day($ymd);
            if (!file_exists($file)) continue;

            // Read whole file (daily rotation keeps these reasonable)
            $contents = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$contents) continue;

            // Process newest first
            for ($i = count($contents) - 1; $i >= 0; $i--) {
                $line = $contents[$i];
                $obj = json_decode($line, true);
                if (!is_array($obj)) continue;

                if (!self::filter_match($obj, $filters)) continue;

                if ($scanned++ < $offset) {
                    continue; // skip until we reach the page start
                }

                $rows[] = $obj;
                if (count($rows) >= $limit) {
                    // We don’t compute reliable totals; indicate maybe-more by scanning sentinel
                    return ['rows' => $rows, 'has_more' => ($i > 0) || (next($dates) !== false)];
                }
            }
        }

        return ['rows' => $rows, 'has_more' => false];
    }

    /** Stream CSV of recent events (filtered). */
    public static function stream_csv(array $filters = []): void
    {
        $filename = 'wcvec_events_' . gmdate('Ymd_His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ts','site_id','product_id','target','action','outcome','message','duration_ms','count','request_id','details']);

        // For CSV, pull up to a generous cap
        $page = 1;
        $cap  = 5000;
        $written = 0;
        do {
            $batch = self::read_recent($filters, 200, $page++);
            foreach ($batch['rows'] as $r) {
                fputcsv($out, [
                    $r['ts'] ?? '',
                    $r['site_id'] ?? '',
                    $r['product_id'] ?? '',
                    $r['target'] ?? '',
                    $r['action'] ?? '',
                    $r['outcome'] ?? '',
                    $r['message'] ?? '',
                    $r['duration_ms'] ?? '',
                    $r['count'] ?? '',
                    $r['request_id'] ?? '',
                    is_array($r['details']) || is_object($r['details'] ?? null) ? wp_json_encode($r['details']) : (string) ($r['details'] ?? ''),
                ]);
                if (++$written >= $cap) break 2;
            }
        } while (!empty($batch['has_more']));

        fclose($out);
        exit;
    }

    /** ===== internals ===== */

    private static function dir(): string
    {
        $upload = wp_get_upload_dir();
        return trailingslashit($upload['basedir']) . 'wcvec';
    }

    private static function file_for_day(string $ymd): string
    {
        return trailingslashit(self::dir()) . 'logs-' . $ymd . '.jsonl';
    }

    private static function filter_match(array $row, array $filters): bool
    {
        if (!empty($filters['product_id']) && (int) $row['product_id'] !== (int) $filters['product_id']) return false;
        if (!empty($filters['target']) && (string) $row['target'] !== (string) $filters['target']) return false;
        if (!empty($filters['action']) && (string) $row['action'] !== (string) $filters['action']) return false;
        if (!empty($filters['outcome']) && (string) $row['outcome'] !== (string) $filters['outcome']) return false;
        return true;
    }

    private static function retention_days(): int
    {
        $n = (int) get_option('wcvec_event_log_retention_days', 7);
        if ($n < 1) $n = 1;
        if ($n > 90) $n = 90;
        return $n;
    }

    /** Prune old JSONL files once a day. */
    private static function maybe_prune(): void
    {
        $k = 'wcvec_events_last_prune';
        $last = (int) get_transient($k);
        if ($last && time() - $last < DAY_IN_SECONDS) {
            return;
        }
        set_transient($k, time(), DAY_IN_SECONDS);

        $dir = self::dir();
        if (!is_dir($dir)) return;

        $keep = [];
        for ($i=0; $i<self::retention_days(); $i++) {
            $keep[] = 'logs-' . gmdate('Ymd', time() - $i * DAY_IN_SECONDS) . '.jsonl';
        }

        $files = glob($dir . '/logs-*.jsonl') ?: [];
        foreach ($files as $f) {
            if (!in_array(basename($f), $keep, true)) {
                @unlink($f);
            }
        }
    }
}
