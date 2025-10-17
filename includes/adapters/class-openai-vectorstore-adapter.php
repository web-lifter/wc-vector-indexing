<?php
/**
 * OpenAI Vector Store Adapter
 *
 * Notes:
 * - We assume a generic Vector Stores HTTP surface where you can create a store,
 *   then upsert/delete vectors with metadata. Exact endpoint paths are wrapped
 *   behind Http so they can evolve without changing call sites.
 * - Embedding dimension is validated upstream (Embeddings::validate_settings()).
 *
 * @package WCVec
 */

namespace WCVec\Adapters;

use WCVec\Http;
use WCVec\Options;
use WCVec\Events;
use WP_Error;

defined('ABSPATH') || exit;

final class OpenAI_VectorStore_Adapter implements VectorStoreInterface
{
    /** Max vectors per upsert call. */
    private int $batch_size = 100;

    /** Retry policy: attempts and base backoff seconds. */
    private int $retries = 3;
    private float $backoff_base = 0.25; // seconds

    // -------------
    // Public API
    // -------------

    public function validate()
    {
        $key = Options::get_openai_key_raw();
        if ($key === '') {
            return new WP_Error('wcvec_oai_key_missing', __('OpenAI API key is not set.', 'wc-vector-indexing'));
        }

        $ensure = $this->ensure_store($key);
        if (is_wp_error($ensure)) {
            return $ensure;
        }
        return true;
    }

    public function upsert(array $chunks)
    {
        $key = Options::get_openai_key_raw();
        if ($key === '') {
            return new WP_Error('wcvec_oai_key_missing', __('OpenAI API key is not set.', 'wc-vector-indexing'));
        }

        $store_id = (string) get_option('wcvec_openai_vectorstore_id', '');
        if ($store_id === '') {
            $ens = $this->ensure_store($key);
            if (is_wp_error($ens)) {
                return $ens;
            }
            $store_id = (string) get_option('wcvec_openai_vectorstore_id', '');
        }

        // Basic client-side shape validation
        $dim = (int) Options::get_dimension();
        foreach ($chunks as $c) {
            $vals = isset($c['values']) && is_array($c['values']) ? $c['values'] : null;
            if (!is_array($vals) || count($vals) !== $dim) {
                return new WP_Error(
                    'wcvec_openai_dim_client_mismatch',
                    sprintf(__('Vector length %1$d did not match expected %2$d.', 'wc-vector-indexing'), is_array($vals) ? count($vals) : 0, $dim)
                );
            }
        }

        $total = 0;
        $batches = array_chunk($chunks, $this->batch_size);
        foreach ($batches as $batch) {
            // Translate our shape -> upsert payload
            $vectors = array_map(static function ($c) {
                return [
                    'id'       => (string) $c['id'],
                    // Some APIs name this "embedding"; keep "values" for consistency with Pinecone.
                    'values'   => array_map('floatval', (array) $c['values']),
                    'metadata' => (array) $c['metadata'],
                ];
            }, $batch);

            $res = $this->request_with_retries(function () use ($key, $store_id, $vectors) {
                return Http::request(
                    sprintf('https://api.openai.com/v1/vector_stores/%s/vectors/upsert', rawurlencode($store_id)),
                    [
                        'method'  => 'POST',
                        'headers' => $this->headers($key),
                        'timeout' => 30,
                        'body'    => ['vectors' => $vectors],
                    ]
                );
            });

            if (is_wp_error($res)) {
                return $res;
            }
            if ((int) $res['code'] >= 400) {
                return $this->http_error('wcvec_openai_upsert_failed', 'OpenAI upsert failed', $res);
            }

            $total += count($batch);
        }

        return ['upserted' => $total];
    }

    public function delete_by_product(int $product_id, int $site_id)
    {
        $key = Options::get_openai_key_raw();
        if ($key === '') {
            return new WP_Error('wcvec_oai_key_missing', __('OpenAI API key is not set.', 'wc-vector-indexing'));
        }

        $store_id = (string) get_option('wcvec_openai_vectorstore_id', '');
        if ($store_id === '') {
            $ens = $this->ensure_store($key);
            if (is_wp_error($ens)) {
                return $ens;
            }
            $store_id = (string) get_option('wcvec_openai_vectorstore_id', '');
        }

        // Prefer server-side filter delete
        $payload = [
            'filter' => [
                'product_id' => (int) $product_id,
                'site_id'    => (int) $site_id,
            ],
        ];

        $res = $this->request_with_retries(function () use ($key, $store_id, $payload) {
            return Http::request(
                sprintf('https://api.openai.com/v1/vector_stores/%s/vectors/delete', rawurlencode($store_id)),
                [
                    'method'  => 'POST',
                    'headers' => $this->headers($key),
                    'timeout' => 30,
                    'body'    => $payload,
                ]
            );
        });

        if (is_wp_error($res)) {
            return $res;
        }
        if ((int) $res['code'] >= 400) {
            return $this->http_error('wcvec_openai_delete_failed', 'OpenAI delete by filter failed', $res);
        }

        $count = null;
        if (is_array($res['json'] ?? null) && isset($res['json']['deleted'])) {
            $count = (int) $res['json']['deleted'];
        }

        return ['deleted' => $count];
    }

    public function delete_by_ids(array $ids)
    {
        $key = Options::get_openai_key_raw();
        if ($key === '') {
            return new WP_Error('wcvec_oai_key_missing', __('OpenAI API key is not set.', 'wc-vector-indexing'));
        }

        $store_id = (string) get_option('wcvec_openai_vectorstore_id', '');
        if ($store_id === '') {
            $ens = $this->ensure_store($key);
            if (is_wp_error($ens)) {
                return $ens;
            }
            $store_id = (string) get_option('wcvec_openai_vectorstore_id', '');
        }

        $ids = array_values(array_map('strval', $ids));
        $payload = ['ids' => $ids];

        $res = $this->request_with_retries(function () use ($key, $store_id, $payload) {
            return Http::request(
                sprintf('https://api.openai.com/v1/vector_stores/%s/vectors/delete', rawurlencode($store_id)),
                [
                    'method'  => 'POST',
                    'headers' => $this->headers($key),
                    'timeout' => 30,
                    'body'    => $payload,
                ]
            );
        });

        if (is_wp_error($res)) {
            return $res;
        }
        if ((int) $res['code'] >= 400) {
            return $this->http_error('wcvec_openai_delete_ids_failed', 'OpenAI delete by IDs failed', $res);
        }

        $count = null;
        if (is_array($res['json'] ?? null) && isset($res['json']['deleted'])) {
            $count = (int) $res['json']['deleted'];
        }

        return ['deleted' => $count];
    }

    public function purge_site(int $site_id)
    {
        if (!$this->is_configured()) {
            return new WP_Error('openai_not_configured', 'OpenAI Vector Store not configured.');
        }
        $store_id = Options::get_openai_vectorstore_id();
        if (!$store_id) {
            return new WP_Error('openai_no_store', 'OpenAI Vector Store ID missing.');
        }

        $deleted = 0;
        $t0 = microtime(true);

        // Attempt a server-side delete-by-metadata if available in your adapter implementation.
        // If your implementation doesn’t support filter delete, fall back to list -> batch delete.

        // Prefer: DELETE with filter (pseudo-endpoint; align with your existing client methods).
        $can_filter_delete = method_exists($this, 'delete_by_filter'); // if you created one earlier
        if ($can_filter_delete) {
            $res = $this->delete_by_filter(['site_id' => (int) $site_id]);
            if (is_wp_error($res)) {
                Events::log('purge_site', 'error', 'OpenAI VS purge (filter) failed', [
                    'target'=>'openai','details'=>['code'=>$res->get_error_code(),'msg'=>$res->get_error_message()]
                ]);
                return $res;
            }
            $deleted = (int) ($res['deleted'] ?? 0);
        } else {
            // Fallback: list in pages, client-side filter by metadata.site_id, delete by IDs in batches
            $after = null;
            $batch_delete_limit = 500;
            do {
                $page = $this->vectors_list_page($store_id, 1000, $after); // implement or reuse your list call
                if (is_wp_error($page)) {
                    Events::log('purge_site', 'error', 'OpenAI VS list failed during purge', [
                        'target'=>'openai','details'=>['code'=>$page->get_error_code(),'msg'=>$page->get_error_message()]
                    ]);
                    return $page;
                }
                $after = $page['after'] ?? null;
                $candidates = [];
                foreach ((array) ($page['data'] ?? []) as $vec) {
                    $meta = (array) ($vec['metadata'] ?? []);
                    if ((int) ($meta['site_id'] ?? -1) === (int) $site_id) {
                        $candidates[] = (string) $vec['id'];
                    }
                }
                // Delete in batches
                while (!empty($candidates)) {
                    $chunk = array_splice($candidates, 0, $batch_delete_limit);
                    $res = $this->vectors_delete_ids($store_id, $chunk); // implement or reuse your delete call
                    if (is_wp_error($res)) {
                        Events::log('purge_site', 'error', 'OpenAI VS delete failed during purge', [
                            'target'=>'openai','details'=>['code'=>$res->get_error_code(),'msg'=>$res->get_error_message()]
                        ]);
                        return $res;
                    }
                    $deleted += count($chunk);
                }
            } while (!empty($after));
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        Events::log('purge_site', 'success', 'OpenAI VS purge completed', [
            'target'=>'openai','details'=>['deleted'=>$deleted],'duration_ms'=>$ms
        ]);
        return ['deleted' => $deleted];
    }

    // -------------
    // Internals
    // -------------

    /**
     * Ensure a usable vector store ID exists (creates one if missing).
     *
     * @return true|WP_Error
     */
    private function ensure_store(string $key)
    {
        $store_id = (string) get_option('wcvec_openai_vectorstore_id', '');
        if ($store_id !== '') {
            // Verify it exists
            $chk = Http::request(
                sprintf('https://api.openai.com/v1/vector_stores/%s', rawurlencode($store_id)),
                [
                    'method'  => 'GET',
                    'headers' => $this->headers($key),
                    'timeout' => 20,
                ]
            );
            if ((int) $chk['code'] === 200) {
                return true;
            }
            // Fall through and try to create a new one if GET fails (store may be gone)
        }

        // Create a new store
        $name = sprintf('wcvec_%d_%s_%s', (int) get_current_blog_id(), sanitize_title(get_bloginfo('name')), gmdate('Ymd_His'));
        $res = Http::request(
            'https://api.openai.com/v1/vector_stores',
            [
                'method'  => 'POST',
                'headers' => $this->headers($key),
                'timeout' => 30,
                'body'    => ['name' => $name],
            ]
        );

        if ((int) $res['code'] >= 400) {
            return $this->http_error('wcvec_openai_store_create_failed', 'OpenAI vector store create failed', $res);
        }

        $json = $res['json'] ?? [];
        $id = is_array($json) && isset($json['id']) ? (string) $json['id'] : '';
        if ($id === '') {
            return new WP_Error('wcvec_openai_store_missing_id', __('Created vector store but no id returned.', 'wc-vector-indexing'));
        }

        update_option('wcvec_openai_vectorstore_id', $id);
        return true;
    }

    private function headers(string $key): array
    {
        return [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Execute a callable that performs an HTTP request, with retries on transient errors.
     *
     * @param callable():array $fn
     * @return array|WP_Error
     */
    private function request_with_retries(callable $fn)
    {
        $attempt = 0;
        $last = null;
        while ($attempt < $this->retries) {
            $attempt++;
            $res = $fn();
            $last = $res;

            $code = (int) ($res['code'] ?? 0);
            $transient = ($code === 0) || $code === 429 || $code >= 500;

            if (!$transient) {
                // 2xx–4xx (except 429) → stop retrying
                break;
            }

            if ($code >= 200 && $code < 300) {
                break;
            }

            if ($attempt < $this->retries) {
                $sleep = $this->backoff_base * (pow(3, $attempt - 1)); // 0.25, 0.75, 2.25
                $jitter = mt_rand(50, 200) / 1000; // 50–200ms
                usleep((int) (($sleep + $jitter) * 1e6));
                continue;
            }
        }

        if (is_array($last) && isset($last['code']) && (int) $last['code'] >= 400) {
            return $this->http_error('wcvec_openai_http_error', 'OpenAI request failed', $last);
        }
        return $last;
    }

    private function http_error(string $code, string $prefix, array $res): WP_Error
    {
        $status = (int) ($res['code'] ?? 0);
        $body   = is_string($res['body'] ?? null) ? $res['body'] : '';
        $snippet = trim(wp_strip_all_tags($body));
        if (strlen($snippet) > 200) {
            $snippet = substr($snippet, 0, 200) . '…';
        }

        return new WP_Error(
            $code,
            sprintf(
                /* translators: 1: message prefix, 2: HTTP code, 3: truncated body */
                __('%1$s (HTTP %2$d): %3$s', 'wc-vector-indexing'),
                $prefix,
                $status,
                $snippet ?: __('No response body.', 'wc-vector-indexing')
            )
        );
    }

        /* Example stubs if you need them; wire to whatever you built earlier. */
    private function vectors_list_page(string $store_id, int $limit, ?string $after)
    {
        // return ['data' => [ ['id'=>'...','metadata'=>['site_id'=>1]], ...], 'after' => 'cursor' ] or WP_Error
        return new WP_Error('not_implemented', 'vectors_list_page not implemented');
    }

    private function vectors_delete_ids(string $store_id, array $ids)
    {
        // return ['deleted' => count($ids)] or WP_Error
        return new WP_Error('not_implemented', 'vectors_delete_ids not implemented');
    }
}
