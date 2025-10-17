<?php
/**
 * OpenAI Embeddings wrapper with model/dimension validation.
 *
 * @package WCVec
 */

namespace WCVec;

use WP_Error;

defined('ABSPATH') || exit;

final class Embeddings
{
    /** Map of supported models to locked dimensions. */
    public static function model_map(): array
    {
        return [
            'text-embedding-3-large' => 1536,
            'text-embedding-3-small' => 3072,
            'text-embedding-ada-002' => 1536,
        ];
    }

    /**
     * Validate configured model, dimension, and API key.
     *
     * @return true|WP_Error
     */
    public static function validate_settings()
    {
        $model     = Options::get_model();
        $dimension = Options::get_dimension();
        $key       = Options::get_openai_key_raw();

        if ($key === '') {
            return new WP_Error('wcvec_oai_key_missing', __('OpenAI API key is not set.', 'wc-vector-indexing'));
        }

        $map = self::model_map();
        if (!isset($map[$model])) {
            return new WP_Error('wcvec_oai_model_invalid', sprintf(
                /* translators: %s is the model name. */
                __('Unknown embedding model: %s', 'wc-vector-indexing'),
                esc_html($model)
            ));
        }

        $expected = (int) $map[$model];
        if ((int) $dimension !== $expected) {
            return new WP_Error('wcvec_dim_mismatch', sprintf(
                /* translators: 1: stored dim, 2: expected dim, 3: model */
                __('Stored dimension %1$d does not match expected %2$d for model %3$s.', 'wc-vector-indexing'),
                (int) $dimension, (int) $expected, esc_html($model)
            ));
        }

        return true;
    }

    /**
     * Embed an array of texts with the configured model.
     *
     * @param array<int,string> $texts
     * @return array<int,array<int,float>>|WP_Error
     */
    public static function embed_texts(array $texts)
    {
        $texts = array_values(array_map('strval', $texts));
        if (empty($texts)) {
            return [];
        }

        $valid = self::validate_settings();
        if (is_wp_error($valid)) {
            return $valid;
        }

        $model     = Options::get_model();
        $dimension = (int) Options::get_dimension();
        $key       = Options::get_openai_key_raw();

        // Batch up to 100 inputs per request (OpenAI supports batching).
        $batches = array_chunk($texts, 100);
        $vectors = [];

        foreach ($batches as $batch) {
            $res = Http::request(
                'https://api.openai.com/v1/embeddings',
                [
                    'method'  => 'POST',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $key,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => [
                        'model' => $model,
                        'input' => $batch,
                    ],
                    'timeout' => 30,
                ]
            );

            if ((int) $res['code'] !== 200 || !is_array($res['json'])) {
                $snippet = self::safe_error_snippet((string) $res['body']);
                return new WP_Error(
                    'wcvec_http_error',
                    sprintf(
                        /* translators: 1: HTTP code, 2: short error body */
                        __('OpenAI embeddings request failed (HTTP %1$d): %2$s', 'wc-vector-indexing'),
                        (int) $res['code'],
                        $snippet
                    )
                );
            }

            // Expect data array with embeddings.
            if (empty($res['json']['data']) || !is_array($res['json']['data'])) {
                return new WP_Error(
                    'wcvec_bad_payload',
                    __('OpenAI returned an unexpected payload for embeddings.', 'wc-vector-indexing')
                );
            }

            foreach ($res['json']['data'] as $row) {
                $vec = isset($row['embedding']) && is_array($row['embedding']) ? $row['embedding'] : null;
                if (!is_array($vec)) {
                    return new WP_Error('wcvec_bad_vector', __('Missing embedding vector in response.', 'wc-vector-indexing'));
                }
                if (count($vec) !== $dimension) {
                    return new WP_Error(
                        'wcvec_embed_size_mismatch',
                        sprintf(
                            /* translators: 1: got length, 2: expected length */
                            __('Embedding length %1$d did not match expected %2$d. Check your model/dimension.', 'wc-vector-indexing'),
                            (int) count($vec),
                            (int) $dimension
                        )
                    );
                }
                // Cast to float for safety.
                $vectors[] = array_map('floatval', $vec);
            }
        }

        // Flatten preserves original order due to batch sequencing.
        return $vectors;
    }

    /** Keep error output short and safe (no secrets). */
    private static function safe_error_snippet(string $body): string
    {
        $trim = trim(wp_strip_all_tags($body));
        if ($trim === '') {
            return __('No response body.', 'wc-vector-indexing');
        }
        if (strlen($trim) > 300) {
            $trim = substr($trim, 0, 300) . '…';
        }
        return $trim;
    }
}
