<?php
/**
 * Vector Store Interface
 *
 * @package WCVec
 */

namespace WCVec\Adapters;

use WP_Error;

defined('ABSPATH') || exit;

interface VectorStoreInterface
{
    /**
     * Validate connectivity and compatibility (e.g., dimension) without performing writes.
     *
     * @return true|WP_Error
     */
    public function validate();

    /**
     * Upsert a batch of vectors for a single product.
     *
     * Each $chunk payload must include:
     *   - 'id'       => string Stable vector ID.
     *   - 'values'   => float[] Embedding values.
     *   - 'metadata' => array   Arbitrary metadata (site_id, product_id, sku, url, updated_at, fingerprint, fields[]).
     *
     * @param array<int, array{id:string,values:array,metadata:array}> $chunks
     * @return array{upserted:int}|WP_Error
     */
    public function upsert(array $chunks);

    /**
     * Delete all vectors for a product (prefer metadata filtering when supported).
     *
     * @param int $product_id
     * @param int $site_id
     * @return array{deleted:int|null}|WP_Error  (Some APIs won't return counts → null)
     */
    public function delete_by_product(int $product_id, int $site_id);

    /**
     * Delete vectors by explicit IDs.
     *
     * @param array<int,string> $ids
     * @return array{deleted:int|null}|WP_Error
     */
    public function delete_by_ids(array $ids);

     /**
     * Purge all vectors for a given site (by metadata `site_id`).
     * Return array like ['deleted' => int|null] or WP_Error on failure.
     */
    public function purge_site(int $site_id);
}
