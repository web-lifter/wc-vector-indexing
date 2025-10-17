<?php
/**
 * Pinecone Adapter
 *
 * @package WCVec
 */

namespace WCVec\Adapters;

use WCVec\Http;
use WCVec\Options;
use WCVec\Events;
use WP_Error;

defined('ABSPATH') || exit;

final class Pinecone_Adapter implements VectorStoreInterface
{
    /** Max vectors per upsert call. */
    private int $batch_size = 100;

    /** Retry policy: attempts and base backoff seconds. */
    private int $retries = 3;
    private float $backoff_base = 0.25; // seconds

    // -----------------
    // Interface methods
    // -----------------

    public function validate()
    {
        $conf = $this->get_config();
        if (is_wp_error($conf)) {
            return $conf;
        }

        // Validate index availability + dimension via control plane,
        // then sanity-hit data plane /describe_index_stats.
        $ctl = $this->ctrl_request(sprintf('https://api.pinecone.io/indexes/%s', rawurlencode($conf['index'])), [
            'method'  => 'GET',
            'headers' => $this->ctrl_headers($conf['key']),
            'timeout' => 20,
        ]);

        if ((int) $ctl['code'] !== 200) {
            return $this->http_error('wcvec_pine_index_not_found', 'Pinecone index lookup failed', $ctl);
        }

        $remote_dim = $this->extract_dimension_from_control($ctl['json'] ?? []);
        if ($remote_dim !== null) {
            $expected = (int) Options::get_dimension();
            if ((int) $remote_dim !== $expected) {
                return new WP_Error(
                    'wcvec_pine_dim_mismatch',
                    sprintf(
                        /* translators: 1: remote dim, 2: local/expected dim, 3: index */
                        __('The Pinecone index dimension (%1$d) does not match the expected dimension (%2$d) for index %3$s. Update the index or change the embedding model.', 'wc-vector-indexing'),
                        (int) $remote_dim,
                        (int) $expected,
                        esc_html($conf['index'])
                    )
                );
            }
        }

        // Light data-plane check (optional; doesn’t assert dimension)
        $dp = $this->data_request($conf, '/describe_index_stats', [
            'method'  => 'POST',
            'headers' => $this->data_headers($conf['key']),
            'timeout' => 20,
            'body'    => ['filter' => (object)[]],
        ]);
        if ((int) $dp['code'] >= 400) {
            return $this->http_error('wcvec_pine_stats_failed', 'Describe index stats failed', $dp);
        }

        return true;
    }

    public function upsert(array $chunks)
    {
        $conf = $this->get_config();
        if (is_wp_error($conf)) {
            return $conf;
        }

        // Sanity: check vectors length match configured dimension
        $dim = (int) Options::get_dimension();
        foreach ($chunks as $c) {
            $vals = isset($c['values']) && is_array($c['values']) ? $c['values'] : null;
            if (!is_array($vals) || count($vals) !== $dim) {
                return new WP_Error(
                    'wcvec_pine_dim_client_mismatch',
                    sprintf(__('Vector length %1$d did not match expected %2$d.', 'wc-vector-indexing'), is_array($vals) ? count($vals) : 0, $dim)
                );
            }
        }

        $total = 0;
        $batches = array_chunk($chunks, $this->batch_size);
        foreach ($batches as $batch) {
            $payload = [
                'vectors' => array_map(static function ($c) {
                    return [
                        'id'       => (string) $c['id'],
                        'values'   => array_map('floatval', (array) $c['values']),
                        'metadata' => (array) $c['metadata'],
                    ];
                }, $batch),
            ];

            $res = $this->request_with_retries(function () use ($conf, $payload) {
                return $this->data_request($conf, '/vectors/upsert', [
                    'method'  => 'POST',
                    'headers' => $this->data_headers($conf['key']),
                    'timeout' => 30,
                    'body'    => $payload,
                ]);
            });

            if (is_wp_error($res)) {
                return $res;
            }

            // Pinecone upsert may not return a count; assume all batch items accepted on 200.
            if ((int) $res['code'] === 200) {
                $total += count($batch);
            } else {
                return $this->http_error('wcvec_pine_upsert_failed', 'Upsert failed', $res);
            }
        }

        return ['upserted' => $total];
    }

    public function delete_by_product(int $product_id, int $site_id)
    {
        $conf = $this->get_config();
        if (is_wp_error($conf)) {
            return $conf;
        }

        $payload = [
            'deleteAll' => false,
            'filter'    => [
                'product_id' => (int) $product_id,
                'site_id'    => (int) $site_id,
            ],
        ];

        $res = $this->request_with_retries(function () use ($conf, $payload) {
            return $this->data_request($conf, '/vectors/delete', [
                'method'  => 'POST',
                'headers' => $this->data_headers($conf['key']),
                'timeout' => 30,
                'body'    => $payload,
            ]);
        });

        if (is_wp_error($res)) {
            return $res;
        }
        if ((int) $res['code'] >= 400) {
            return $this->http_error('wcvec_pine_delete_failed', 'Delete by filter failed', $res);
        }

        // Pinecone may return { "match_count": N } or nothing; normalize to null.
        $count = null;
        if (is_array($res['json'] ?? null) && isset($res['json']['match_count'])) {
            $count = (int) $res['json']['match_count'];
        }

        return ['deleted' => $count];
    }

    public function delete_by_ids(array $ids)
    {
        $conf = $this->get_config();
        if (is_wp_error($conf)) {
            return $conf;
        }

        $ids = array_values(array_map('strval', $ids));
        $payload = ['ids' => $ids];

        $res = $this->request_with_retries(function () use ($conf, $payload) {
            return $this->data_request($conf, '/vectors/delete', [
                'method'  => 'POST',
                'headers' => $this->data_headers($conf['key']),
                'timeout' => 30,
                'body'    => $payload,
            ]);
        });

        if (is_wp_error($res)) {
            return $res;
        }
        if ((int) $res['code'] >= 400) {
            return $this->http_error('wcvec_pine_delete_ids_failed', 'Delete by IDs failed', $res);
        }

        $count = null;
        if (is_array($res['json'] ?? null) && isset($res['json']['match_count'])) {
            $count = (int) $res['json']['match_count'];
        }

        return ['deleted' => $count];
    }

    public function purge_site(int $site_id)
    {
        if (!$this->is_configured()) {
            return new WP_Error('pinecone_not_configured', 'Pinecone not configured.');
        }
        $host = $this->index_host(); // e.g. https://{index}-{project}.svc.{env}.pinecone.io
        if (!$host) {
            return new WP_Error('pinecone_no_index', 'Pinecone index host unavailable.');
        }

        // Pinecone delete with metadata filter
        $url = rtrim($host, '/') . '/vectors/delete';
        $payload = [
            'filter' => [ 'site_id' => [ '$eq' => (int) $site_id ] ],
            // Do NOT set deleteAll — we only want this site's vectors.
        ];

        $t0 = microtime(true);
        $resp = Http::json('POST', $url, $payload, $this->headers());
        $ms = (int) round((microtime(true) - $t0) * 1000);

        if (is_wp_error($resp)) {
            Events::log('purge_site', 'error', 'Pinecone purge failed', [
                'target' => 'pinecone',
                'details'=> ['code'=>$resp->get_error_code(),'msg'=>$resp->get_error_message()],
                'duration_ms' => $ms,
            ]);
            return $resp;
        }

        // Pinecone may return {"matches": N} or empty. Normalize:
        $deleted = is_array($resp) && isset($resp['total']) ? (int) $resp['total'] : null;

        Events::log('purge_site', 'success', 'Pinecone purge completed', [
            'target' => 'pinecone',
            'details'=> ['deleted'=>$deleted],
            'duration_ms' => $ms,
        ]);

        return ['deleted' => $deleted];
    }

    // ---------------
    // Private helpers
    // ---------------

    /**
     * Resolve settings and build data-plane host.
     *
     * @return array{key:string,index:string,project:string,env:string,host:string}|WP_Error
     */
    private function get_config()
    {
        $key     = Options::get_pinecone_key_raw();
        $index   = trim((string) get_option('wcvec_pinecone_index', ''));
        $project = trim((string) get_option('wcvec_pinecone_project', ''));
        $env     = trim((string) get_option('wcvec_pinecone_env', ''));

        if ($key === '' || $index === '' || $project === '' || $env === '') {
            return new WP_Error(
                'wcvec_pine_config_missing',
                __('Pinecone configuration is incomplete (API key, environment, project, and index are required).', 'wc-vector-indexing')
            );
        }

        $host = sprintf('https://%s-%s.svc.%s.pinecone.io', $index, $project, $env);
        return compact('key', 'index', 'project', 'env', 'host');
    }

    private function ctrl_headers(string $key): array
    {
        return [
            'Api-Key'      => $key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    private function data_headers(string $key): array
    {
        // Same headers for data-plane.
        return $this->ctrl_headers($key);
    }

    /**
     * Control-plane request (api.pinecone.io)
     */
    private function ctrl_request(string $url, array $args): array
    {
        return Http::request($url, $args);
    }

    /**
     * Data-plane request against index host.
     *
     * @param array $conf from get_config()
     * @param string $path e.g., '/vectors/upsert'
     */
    private function data_request(array $conf, string $path, array $args): array
    {
        $url = rtrim($conf['host'], '/') . $path;
        return Http::request($url, $args);
    }

    private function extract_dimension_from_control(array $json): ?int
    {
        // Pinecone payloads have changed over time; try several places.
        // Examples:
        // { "dimension": 1536, ... }
        // { "spec": { "pod": { "dimension": 1536 } } }
        // { "index_config": { "dimension": 1536 } }
        $paths = [
            ['dimension'],
            ['spec', 'pod', 'dimension'],
            ['index_config', 'dimension'],
        ];
        foreach ($paths as $p) {
            $cur = $json;
            foreach ($p as $k) {
                if (!is_array($cur) || !array_key_exists($k, $cur)) {
                    $cur = null;
                    break;
                }
                $cur = $cur[$k];
            }
            if (is_numeric($cur)) {
                return (int) $cur;
            }
        }
        return null;
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
            return $this->http_error('wcvec_pine_http_error', 'Pinecone request failed', $last);
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
}
