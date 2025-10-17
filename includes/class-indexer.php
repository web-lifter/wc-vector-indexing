<?php
/**
 * Indexer
 *
 * Builds per-product chunk payloads (id, embedding, metadata) ready for adapters.
 *
 * @package WCVec
 */

namespace WCVec;

use WP_Error;
use WCVec\Adapters\VectorStoreInterface;

defined('ABSPATH') || exit;

require_once WC_VEC_DIR . 'includes/class-variation-rollup.php';

final class Indexer
{
    /**
     * Build payloads for a product from current (or provided) selection.
     *
     * @param int        $product_id
     * @param array|null $selection  Optional selection map; defaults to saved selection.
     * @return array<int, array{id:string,values:array,metadata:array}>|WP_Error
     */
    public static function build_payloads(int $product_id, ?array $selection = null)
    {
        if ($product_id <= 0) {
            return new WP_Error('wcvec_invalid_product', __('Invalid product ID.', 'wc-vector-indexing'));
        }

        if (!function_exists('wc_get_product') || !wc_get_product($product_id)) {
            return new WP_Error('wcvec_product_not_found', __('Product not found.', 'wc-vector-indexing'));
        }

        // Selection map (fields + flags + chunking)
        $selection = is_array($selection) ? $selection : Options::get_selected_fields();
        $chunking  = [
            'size'    => (int) ($selection['chunking']['size']    ?? 800),
            'overlap' => (int) ($selection['chunking']['overlap'] ?? 100),
        ];

        // 1) Normalize product fields → single concatenated text
        $preview = Field_Normalizer::build_preview($product_id, $selection);
        if (empty($preview['ok'])) {
            $msg = isset($preview['message']) ? (string) $preview['message'] : __('Failed to build product text.', 'wc-vector-indexing');
            return new WP_Error('wcvec_preview_failed', $msg);
        }
        $text = (string) ($preview['text'] ?? '');

        // 2) Compute product fingerprint (binds to text + selection + chunking + model + dim)
        $model = Options::get_model();
        $dim   = (int) Options::get_dimension();

        $product_sha = Fingerprint::sha_product($text, $selection, $chunking, $model, $dim);

        // 3) Chunk the text
        $chunks = Chunker::chunk_text($text, $chunking['size'], $chunking['overlap'], 4.0);
        // If no chunks (empty content), we still proceed with zero payloads so caller can decide behavior.
        if (!is_array($chunks)) {
            $chunks = [];
        }

        $site_id = (int) get_current_blog_id();

        // 4) Prepare inputs for embeddings
        $inputs = array_map(static fn($c) => (string) $c['text'], $chunks);

        // Validate embeddings settings
        $valid = Embeddings::validate_settings();
        if (is_wp_error($valid)) {
            return $valid;
        }

        // 5) Call embeddings (order preserved)
        $vectors = !empty($inputs) ? Embeddings::embed_texts($inputs) : [];
        if (is_wp_error($vectors)) {
            return $vectors;
        }
        if (count($vectors) !== count($chunks)) {
            return new WP_Error('wcvec_embed_mismatch', __('Embeddings count did not match chunk count.', 'wc-vector-indexing'));
        }

        // 6) Build metadata common fields
        $prod   = wc_get_product($product_id);
        $sku    = (string) $prod->get_sku();
        $url    = (string) get_permalink($product_id);
        $nowIso = gmdate('c'); // UTC ISO8601

        $field_list = array_values(array_unique(array_merge(
            $selection['core']       ?? [],
            $selection['tax']        ?? [],
            $selection['attributes'] ?? [],
            $selection['seo']        ?? [],
            array_keys($selection['meta'] ?? [])
        )));

        // 7) Assemble payload array
        $payloads = [];
        foreach ($chunks as $i => $c) {
            $payloads[] = [
                'id'     => self::vector_id($site_id, $product_id, (int) $c['index']),
                'values' => array_map('floatval', $vectors[$i]),
                'metadata' => [
                    'site_id'     => $site_id,
                    'product_id'  => (int) $product_id,
                    'sku'         => $sku,
                    'url'         => $url,
                    'updated_at'  => $nowIso,
                    'fingerprint' => 'sha256:' . $product_sha, // product-level SHA
                    'fields'      => $field_list,
                ],
            ];
        }

        return $payloads; // may be [] if no content
    }

    /** Example join point: after you build $normalized_text for the product. */
    private static function finalize_normalized_text(int $product_id, string $normalized_text, array $selection): string
    {
        $strategy = Options::get_variation_strategy();

        // Only append for parent "product" posts (not for product_variation)
        $post = get_post($product_id);
        if ($post && $post->post_type === 'product' && $strategy === 'collapse') {
            $normalized_text .= Variation_Rollup::build($product_id, $selection);
        }

        /**
         * Filter: allow extensions to adjust the final normalized text.
         * @param string $normalized_text
         * @param int    $product_id
         */
        return apply_filters('wcvec/final_normalized_text', $normalized_text, $product_id);
    }

    /**
     * Return a stable vector ID that both adapters can use.
     *
     * @param int $site_id
     * @param int $product_id
     * @param int $chunk_index
     */
    public static function vector_id(int $site_id, int $product_id, int $chunk_index): string
    {
        return sprintf('site-%d:product-%d:chunk-%d', $site_id, $product_id, $chunk_index);
    }

    /**
     * Adapter factory (used later by admin actions/jobs).
     *
     * @param 'pinecone'|'openai' $target
     * @return VectorStoreInterface|WP_Error
     */
    public static function get_adapter(string $target)
    {
        switch ($target) {
            case 'pinecone':
                $class = '\\WCVec\\Adapters\\Pinecone_Adapter';
                if (class_exists($class)) {
                    return new $class();
                }
                return new WP_Error('wcvec_adapter_unavailable', __('Pinecone adapter not available yet.', 'wc-vector-indexing'));

            case 'openai':
                $class = '\\WCVec\\Adapters\\OpenAI_VectorStore_Adapter';
                if (class_exists($class)) {
                    return new $class();
                }
                return new WP_Error('wcvec_adapter_unavailable', __('OpenAI Vector Store adapter not available yet.', 'wc-vector-indexing'));
        }

        return new WP_Error('wcvec_unknown_target', __('Unknown vector store target.', 'wc-vector-indexing'));
    }

        /**
     * Build a full package for a product: payloads + SHAs + indexes.
     *
     * @return array{payloads:array, product_sha:string, chunk_shas:array<int,string>, chunk_indexes:array<int,int>}|WP_Error
     */
    public static function build_payloads_full(int $product_id, ?array $selection = null)
    {
        if ($product_id <= 0) {
            return new WP_Error('wcvec_invalid_product', __('Invalid product ID.', 'wc-vector-indexing'));
        }
        if (!function_exists('wc_get_product') || !wc_get_product($product_id)) {
            return new WP_Error('wcvec_product_not_found', __('Product not found.', 'wc-vector-indexing'));
        }

        $selection = is_array($selection) ? $selection : Options::get_selected_fields();
        $chunking  = [
            'size'    => (int) ($selection['chunking']['size']    ?? 800),
            'overlap' => (int) ($selection['chunking']['overlap'] ?? 100),
        ];

        $preview = Field_Normalizer::build_preview($product_id, $selection);
        if (empty($preview['ok'])) {
            $msg = isset($preview['message']) ? (string) $preview['message'] : __('Failed to build product text.', 'wc-vector-indexing');
            return new WP_Error('wcvec_preview_failed', $msg);
        }
        $text  = (string) ($preview['text'] ?? '');
        $model = Options::get_model();
        $dim   = (int) Options::get_dimension();

        $product_sha = Fingerprint::sha_product($text, $selection, $chunking, $model, $dim);

        $chunks = Chunker::chunk_text($text, $chunking['size'], $chunking['overlap'], 4.0);
        $site_id = (int) get_current_blog_id();

        $inputs        = [];
        $chunk_indexes = [];
        $chunk_shas    = [];

        foreach ($chunks as $c) {
            $idx = (int) $c['index'];
            $chunk_indexes[] = $idx;
            $chunk_shas[$idx] = Fingerprint::sha_chunk((string) $c['text'], $product_sha, $idx);
            $inputs[] = (string) $c['text'];
        }

        // Embed
        $valid = Embeddings::validate_settings();
        if (is_wp_error($valid)) {
            return $valid;
        }
        $vectors = !empty($inputs) ? Embeddings::embed_texts($inputs) : [];
        if (is_wp_error($vectors)) { return $vectors; }
        if (count($vectors) !== count($chunks)) {
            return new WP_Error('wcvec_embed_mismatch', __('Embeddings count did not match chunk count.', 'wc-vector-indexing'));
        }

        // Metadata shared
        $prod   = wc_get_product($product_id);
        $sku    = (string) $prod->get_sku();
        $url    = (string) get_permalink($product_id);
        $nowIso = gmdate('c'); // UTC
        $field_list = array_values(array_unique(array_merge(
            $selection['core']       ?? [],
            $selection['tax']        ?? [],
            $selection['attributes'] ?? [],
            $selection['seo']        ?? [],
            array_keys($selection['meta'] ?? [])
        )));

        $payloads = [];
        foreach ($chunks as $i => $c) {
            $idx = (int) $c['index'];
            $payloads[] = [
                'id'     => self::vector_id($site_id, $product_id, $idx),
                'values' => array_map('floatval', $vectors[$i]),
                'metadata' => [
                    'site_id'     => $site_id,
                    'product_id'  => (int) $product_id,
                    'sku'         => $sku,
                    'url'         => $url,
                    'updated_at'  => $nowIso,
                    'fingerprint' => 'sha256:' . $product_sha, // product-level SHA on each chunk
                    'fields'      => $field_list,
                ],
                '_chunk_index' => $idx, // convenience for delta calculations
            ];
        }

        return [
            'payloads'      => $payloads,
            'product_sha'   => $product_sha,
            'chunk_shas'    => $chunk_shas,
            'chunk_indexes' => $chunk_indexes,
        ];
    }

    /**
     * Sync a product to enabled targets with change detection.
     *
     * @param int   $product_id
     * @param array|null $selection  Field selection map; defaults to saved selection.
     * @param array<string,VectorStoreInterface>|null $adapters  (test hook) map target=>adapter
     * @param bool $force  Force full rebuild for each target
     * @return array<int,array{target:string,upserted:int,deleted:int,skipped:int,chunks_total:int,product_sha:string}>|WP_Error
     */
    public static function sync_product(int $product_id, ?array $selection = null, ?array $adapters = null, bool $force = false)
    {
        $selection = is_array($selection) ? $selection : Options::get_selected_fields();
        $pkg = self::build_payloads_full($product_id, $selection);
        if (is_wp_error($pkg)) {
            return $pkg;
        }

        $payloads      = $pkg['payloads'];
        $product_sha   = $pkg['product_sha'];
        $chunk_shas    = $pkg['chunk_shas'];
        $chunk_indexes = $pkg['chunk_indexes'];

        $targets = Options::get_targets_enabled();
        $model   = Options::get_model();
        $dim     = (int) Options::get_dimension();
        $site_id = (int) get_current_blog_id();

        $batch = \WCVec\Options::get_batch_upsert_size(); 

        $summaries = [];

        foreach ($targets as $target) {
            // Allow tests to inject fake adapters
            $adapter = $adapters[$target] ?? self::get_adapter($target);
            if (is_wp_error($adapter)) {
                return $adapter;
            }
            $v = $adapter->validate();
            if (is_wp_error($v)) {
                return $v;
            }

            // Load existing rows
            $existing = Storage::get_chunks($product_id, $target);

            // If existing rows have different model or dimension, treat as full rebuild
            $rebuild_due_to_model = false;
            foreach ($existing as $row) {
                if ((string) $row['model'] !== (string) $model || (int) $row['dimension'] !== $dim) {
                    $rebuild_due_to_model = true;
                    break;
                }
            }

            // If nothing changed and not forcing/rebuilding, short-circuit (touch timestamps)
            if (!$force && !$rebuild_due_to_model && !empty($existing)) {
                $all_match = true;
                if (!empty($existing)) {
                    // Check product sha equality across any row
                    $row = reset($existing);
                    if ($row && isset($row['product_sha']) && (string) $row['product_sha'] === (string) $product_sha) {
                        // also verify chunk set matches
                        $ex_idxs = array_keys($existing);
                        sort($ex_idxs);
                        $new_idxs = $chunk_indexes;
                        sort($new_idxs);
                        if ($ex_idxs !== $new_idxs) {
                            $all_match = false;
                        }
                    } else {
                        $all_match = false;
                    }
                }
                if ($all_match) {
                    Storage::touch_all($product_id, $target);
                    $summaries[] = [
                        'target'       => $target,
                        'upserted'     => 0,
                        'deleted'      => 0,
                        'skipped'      => count($chunk_indexes),
                        'chunks_total' => count($chunk_indexes),
                        'product_sha'  => $product_sha,
                    ];
                    continue;
                }
            }

            // Build delta
            $to_delete_idx = [];
            $to_upsert_idx = [];

            // Delete: indexes in existing but not in new
            foreach ($existing as $idx => $row) {
                if (!in_array((int) $idx, $chunk_indexes, true)) {
                    $to_delete_idx[] = (int) $idx;
                }
            }

            // Upsert: new or changed chunk_sha, or full rebuild
            foreach ($chunk_indexes as $idx) {
                $idx = (int) $idx;
                if ($rebuild_due_to_model || $force) {
                    $to_upsert_idx[] = $idx;
                    continue;
                }
                if (!isset($existing[$idx])) {
                    $to_upsert_idx[] = $idx;
                    continue;
                }
                $row = $existing[$idx];
                if ((string) $row['chunk_sha'] !== (string) $chunk_shas[$idx]) {
                    $to_upsert_idx[] = $idx;
                }
            }

            // Execute deletes (ids from existing rows)
            $deleted = 0;
            if (!empty($to_delete_idx)) {
                $ids = [];
                foreach ($to_delete_idx as $i) {
                    if (isset($existing[$i]['vector_id'])) {
                        $ids[] = (string) $existing[$i]['vector_id'];
                    } else {
                        $ids[] = self::vector_id($site_id, $product_id, $i);
                    }
                }
                $res = $adapter->delete_by_ids($ids);
                if (is_wp_error($res)) {
                    // Mark rows as error and continue to attempt upserts
                    Storage::mark_error($product_id, $target, $to_delete_idx, $res->get_error_code(), $res->get_error_message());
                } else {
                    $deleted = (int) ($res['deleted'] ?? count($to_delete_idx));
                    Storage::delete_chunks_by_indexes($product_id, $target, $to_delete_idx);
                }
            }

            // Execute upserts in batches
            $upserted = 0;
            if (!empty($to_upsert_idx) && !empty($payloads)) {
                // Build a map index -> payload row
                $payloads_by_idx = [];
                foreach ($payloads as $row) {
                    $payloads_by_idx[(int) $row['_chunk_index']] = $row;
                }

                // Prepare ordered list of payloads to upsert
                $ordered = [];
                foreach ($to_upsert_idx as $i) {
                    if (isset($payloads_by_idx[$i])) {
                        $ordered[] = $payloads_by_idx[$i];
                    }
                }

                $batches = array_chunk($ordered, max(10, (int) $batch));
                foreach ($batches as $batch_rows) {
                    // Strip internal key before sending
                    $send = array_map(static function ($r) {
                        $x = $r;
                        unset($x['_chunk_index']);
                        return $x;
                    }, $batch_rows);

                    $res = $adapter->upsert($send);
                    if (is_wp_error($res)) {
                        // Mark all batch indices as error
                        $idxs = array_map(static fn($r) => (int) $r['_chunk_index'], $batch_rows);
                        Storage::mark_error($product_id, $target, $idxs, $res->get_error_code(), $res->get_error_message());
                        continue; // proceed with other batches
                    }

                    $upserted += (int) ($res['upserted'] ?? count($batch_rows));

                    // Persist/replace rows for this batch
                    foreach ($batch_rows as $r) {
                        $idx = (int) $r['_chunk_index'];
                        Storage::replace_chunk([
                            'site_id'       => $site_id,
                            'product_id'    => (int) $product_id,
                            'target'        => $target,
                            'chunk_index'   => $idx,
                            'vector_id'     => (string) $r['id'],
                            'product_sha'   => $product_sha,
                            'chunk_sha'     => (string) $chunk_shas[$idx],
                            'model'         => $model,
                            'dimension'     => $dim,
                            'status'        => 'synced',
                            'last_synced_at'=> Storage::now(),
                            // optional: remote_id/error fields left null
                        ]);
                    }
                }
            }

            $skipped = max(0, count($chunk_indexes) - $upserted);
            $summaries[] = [
                'target'       => $target,
                'upserted'     => $upserted,
                'deleted'      => $deleted,
                'skipped'      => $skipped,
                'chunks_total' => count($chunk_indexes),
                'product_sha'  => $product_sha,
            ];
        }
        return $summaries;
    }
}
