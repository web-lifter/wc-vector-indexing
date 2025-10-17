<?php
/**
 * Storage: CRUD helpers for wp_wcvec_objects
 *
 * @package WCVec
 */

namespace WCVec;

use wpdb;

defined('ABSPATH') || exit;

final class Storage
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wcvec_objects';
    }

    /** Current UTC timestamp (Y-m-d H:i:s) */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Fetch all chunk rows for a product/target, keyed by chunk_index.
     *
     * @return array<int,array>  [chunk_index => row]
     */
    public static function get_chunks(int $product_id, string $target): array
    {
        global $wpdb;
        $table = self::table();
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE product_id=%d AND target=%s ORDER BY chunk_index ASC",
            $product_id,
            $target
        );
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $idx = (int) $r['chunk_index'];
            $out[$idx] = $r;
        }
        return $out;
    }

    /**
     * Insert or update a single chunk row.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotency.
     *
     * @param array $row Required keys:
     *  site_id, product_id, target, chunk_index, vector_id, product_sha, chunk_sha,
     *  model, dimension, status, last_synced_at [,remote_id, error_code, error_msg]
     */
    public static function replace_chunk(array $row): int
    {
        global $wpdb;
        $table = self::table();

        $now = self::now();

        $data = [
            'site_id'        => (int) ($row['site_id'] ?? get_current_blog_id()),
            'product_id'     => (int) $row['product_id'],
            'target'         => (string) $row['target'],
            'chunk_index'    => (int) $row['chunk_index'],
            'vector_id'      => (string) $row['vector_id'],
            'product_sha'    => (string) $row['product_sha'],
            'chunk_sha'      => (string) $row['chunk_sha'],
            'model'          => (string) $row['model'],
            'dimension'      => (int) $row['dimension'],
            'remote_id'      => isset($row['remote_id']) ? (string) $row['remote_id'] : null,
            'status'         => (string) $row['status'],
            'error_code'     => isset($row['error_code']) ? (string) $row['error_code'] : null,
            'error_msg'      => isset($row['error_msg']) ? (string) $row['error_msg'] : null,
            'last_synced_at' => (string) ($row['last_synced_at'] ?? $now),
            'created_at'     => (string) ($row['created_at'] ?? $now),
            'updated_at'     => (string) $now,
        ];

        // Build INSERT ... ON DUPLICATE KEY UPDATE
        $cols = array_keys($data);
        $placeholders = [];
        $values = [];
        foreach ($cols as $c) {
            $placeholders[] = '%s'; // set per-type below
            $v = $data[$c];
            if (in_array($c, ['site_id','product_id','chunk_index','dimension'], true)) {
                $placeholders[count($placeholders)-1] = '%d';
                $values[] = (int) $v;
            } else {
                $values[] = $v;
            }
        }
        $insert_cols = '`' . implode('`,`', $cols) . '`';
        $insert_vals = implode(',', $placeholders);

        // Update set — exclude immutable columns
        $update_cols = [
            'product_sha','chunk_sha','model','dimension','remote_id',
            'status','error_code','error_msg','last_synced_at','updated_at'
        ];
        $update_sets = [];
        foreach ($update_cols as $uc) {
            $ph = in_array($uc, ['dimension'], true) ? '%d' : '%s';
            $update_sets[] = "`{$uc}` = {$ph}";
            $values[] = in_array($uc, ['dimension'], true) ? (int) $data[$uc] : $data[$uc];
        }
        $sql = "INSERT INTO {$table} ({$insert_cols}) VALUES ({$insert_vals})
                ON DUPLICATE KEY UPDATE " . implode(', ', $update_sets);

        $prepared = $wpdb->prepare($sql, $values);
        $wpdb->query($prepared);
        return (int) $wpdb->rows_affected; // 1 insert, or 2 (insert+delete) not applicable with ODKU
    }

    /**
     * Delete specific chunk indexes for a product/target.
     *
     * @return int rows affected
     */
    public static function delete_chunks_by_indexes(int $product_id, string $target, array $indexes): int
    {
        global $wpdb;
        $table = self::table();
        $indexes = array_values(array_unique(array_map('intval', $indexes)));
        if (empty($indexes)) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($indexes), '%d'));
        $values = array_merge([$product_id, $target], $indexes);
        $sql = $wpdb->prepare(
            "DELETE FROM {$table}
             WHERE product_id=%d AND target=%s AND chunk_index IN ($in)",
            $values
        );
        $wpdb->query($sql);
        return (int) $wpdb->rows_affected;
    }

    /**
     * Delete all chunks for a product (all targets).
     */
    public static function delete_all_for_product(int $product_id): int
    {
        global $wpdb;
        $table = self::table();
        $sql = $wpdb->prepare("DELETE FROM {$table} WHERE product_id=%d", $product_id);
        $wpdb->query($sql);
        return (int) $wpdb->rows_affected;
    }

    /**
     * Delete all chunks for a product/target.
     */
    public static function delete_all_for_product_target(int $product_id, string $target): int
    {
        global $wpdb;
        $table = self::table();
        $sql = $wpdb->prepare(
            "DELETE FROM {$table} WHERE product_id=%d AND target=%s",
            $product_id,
            $target
        );
        $wpdb->query($sql);
        return (int) $wpdb->rows_affected;
    }

    /**
     * Mark specific chunk indexes as error with code/message.
     */
    public static function mark_error(int $product_id, string $target, array $indexes, string $code, string $msg): int
    {
        global $wpdb;
        $table = self::table();
        $indexes = array_values(array_unique(array_map('intval', $indexes)));
        if (empty($indexes)) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($indexes), '%d'));
        $now = self::now();
        $values = array_merge([$code, $msg, $now, $product_id, $target], $indexes);

        $sql = $wpdb->prepare(
            "UPDATE {$table}
             SET status='error', error_code=%s, error_msg=%s, updated_at=%s
             WHERE product_id=%d AND target=%s AND chunk_index IN ($in)",
            $values
        );
        $wpdb->query($sql);
        return (int) $wpdb->rows_affected;
    }

    /**
     * Touch all rows for product/target (update last_synced_at + updated_at).
     */
    public static function touch_all(int $product_id, string $target): int
    {
        global $wpdb;
        $table = self::table();
        $now = self::now();
        $sql = $wpdb->prepare(
            "UPDATE {$table}
             SET last_synced_at=%s, updated_at=%s
             WHERE product_id=%d AND target=%s",
            $now, $now, $product_id, $target
        );
        $wpdb->query($sql);
        return (int) $wpdb->rows_affected;
    }

     /**
     * Return product IDs that are published and have NO rows in wcvec_objects yet.
     * Ordered by most recently modified.
     *
     * @param int $limit
     * @return int[]
     */
    public static function get_product_ids_needing_initial_sync(int $limit = 200): array
    {
        global $wpdb;
        $limit = max(1, (int) $limit);

        $posts = $wpdb->posts;
        $table = self::table();

        $sql = "
            SELECT p.ID, p.post_type, p.post_parent
            FROM {$posts} p
            WHERE p.post_type IN ('product','product_variation')
              AND p.post_status = 'publish'
              AND NOT EXISTS (SELECT 1 FROM {$table} o WHERE o.product_id = p.ID)
            ORDER BY p.post_modified_gmt DESC
            LIMIT %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A) ?: [];
        return self::dedupe_products_and_parents($rows);
    }

    /**
     * Return product IDs modified since a given GMT Y-m-d H:i:s,
     * where our recorded sync (updated_at/last_synced_at) is older or missing.
     * Includes both parent (if variation) and the variation itself for freshness.
     *
     * @param string $since_gmt 'Y-m-d H:i:s' in UTC
     * @param int $limit
     * @return int[]
     */
    public static function get_product_ids_modified_since(string $since_gmt, int $limit = 200): array
    {
        global $wpdb;
        $limit = max(1, (int) $limit);

        $posts = $wpdb->posts;
        $table = self::table();

        // Subquery: last local sync per product.
        $sql = "
            SELECT p.ID, p.post_type, p.post_parent
            FROM {$posts} p
            LEFT JOIN (
                SELECT product_id, MAX(GREATEST(updated_at, last_synced_at)) AS last_sync
                FROM {$table}
                GROUP BY product_id
            ) o ON o.product_id = p.ID
            WHERE p.post_type IN ('product','product_variation')
              AND p.post_status = 'publish'
              AND p.post_modified_gmt >= %s
              AND (o.last_sync IS NULL OR o.last_sync < p.post_modified_gmt)
            ORDER BY p.post_modified_gmt DESC
            LIMIT %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $since_gmt, $limit), ARRAY_A) ?: [];
        return self::dedupe_products_and_parents($rows);
    }

    /** Delete all local rows for a given site_id (or current site by default). */
    public static function delete_all_for_site(?int $site_id = null): int
    {
        global $wpdb;
        $table = self::table();
        $site  = $site_id ?? get_current_blog_id();
        $sql   = $wpdb->prepare("DELETE FROM {$table} WHERE site_id = %d", $site);
        $wpdb->query($sql);
        return (int) $wpdb->rows_affected;
    }

    /**
     * Return product IDs that currently have error rows.
     *
     * @param int $limit
     * @return int[]
     */
    public static function get_product_ids_with_errors(int $limit = 200): array
    {
        global $wpdb;
        $table = self::table();
        $limit = max(1, (int) $limit);
        $sql = "SELECT DISTINCT product_id FROM {$table} WHERE status='error' ORDER BY product_id DESC LIMIT %d";
        $ids = $wpdb->get_col($wpdb->prepare($sql, $limit)) ?: [];
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Helper: From a list of rows (ID, post_type, post_parent), include both
     * the product and its parent for variations, then de-duplicate preserving order.
     *
     * @param array<int,array{id:int,post_type:string,post_parent:int}> $rows
     * @return int[]
     */
    private static function dedupe_products_and_parents(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $r) {
            $id  = (int) $r['ID'];
            $typ = (string) $r['post_type'];
            $par = (int) $r['post_parent'];

            // Always include the product itself
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $out[] = $id;
            }

            // If it's a variation with a parent product, include parent too
            if ($typ === 'product_variation' && $par > 0 && !isset($seen[$par])) {
                $seen[$par] = true;
                $out[] = $par;
            }
        }
        return $out;
    }

    private static function status_clause_and_args(): array
    {
        $statuses = Options::include_drafts_private()
            ? ['publish', 'draft', 'private']
            : ['publish'];

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $clause = "AND p.post_status IN ($placeholders)";
        return [$clause, $statuses];
    }

    public static function get_product_ids_needing_initial_sync(int $limit = 200): array
    {
        global $wpdb;
        $limit = max(1, (int) $limit);
        $posts = $wpdb->posts;
        $table = self::table();
        [$status_sql, $status_args] = self::status_clause_and_args();

        $sql = "
            SELECT p.ID, p.post_type, p.post_parent
            FROM {$posts} p
            WHERE p.post_type IN ('product','product_variation')
              {$status_sql}
              AND NOT EXISTS (SELECT 1 FROM {$table} o WHERE o.product_id = p.ID)
            ORDER BY p.post_modified_gmt DESC
            LIMIT %d
        ";

        $args = array_merge($status_args, [$limit]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [];
        return self::dedupe_products_and_parents($rows);
    }
}
