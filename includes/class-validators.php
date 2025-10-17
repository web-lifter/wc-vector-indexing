<?php
/**
 * Connection validators for OpenAI and Pinecone.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

class Validators {

    /**
     * Validate OpenAI by making a minimal Embeddings call with input "ping".
     * Checks that the returned embedding length matches the configured dimension.
     *
     * @return array{ok:bool, message:string}
     */
    public static function validate_openai(): array
    {
        $key       = Options::get_openai_key_raw();
        $model     = Options::get_model();
        $dimension = Options::get_dimension();

        if ($key === '') {
            return ['ok' => false, 'message' => __('OpenAI API key is not set.', 'wc-vector-indexing')];
        }

        $url = 'https://api.openai.com/v1/embeddings';
        $headers = [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ];
        $body = [
            'model' => $model,
            'input' => 'ping',
        ];

        $res = Http::request($url, ['method' => 'POST', 'headers' => $headers, 'body' => $body]);

        if ($res['code'] !== 200 || !is_array($res['json'])) {
            $snippet = self::safe_error_snippet($res['body']);
            $msg = sprintf(
                /* translators: 1: HTTP status code, 2: error snippet */
                __('OpenAI validation failed (HTTP %1$d): %2$s', 'wc-vector-indexing'),
                (int) $res['code'],
                $snippet
            );
            return ['ok' => false, 'message' => $msg];
        }

        // Expect data[0].embedding to exist and match our dimension.
        $first = $res['json']['data'][0] ?? null;
        $embed = is_array($first) ? ($first['embedding'] ?? null) : null;
        $len   = is_array($embed) ? count($embed) : 0;

        if ($len !== $dimension) {
            $msg = sprintf(
                /* translators: 1: got, 2: expected */
                __('OpenAI responded, but embedding length was %1$d (expected %2$d). Check your model/dimension.', 'wc-vector-indexing'),
                (int) $len,
                (int) $dimension
            );
            return ['ok' => false, 'message' => $msg];
        }

        return ['ok' => true, 'message' => __('OpenAI connection looks good.', 'wc-vector-indexing')];
    }

    /**
     * Validate Pinecone credentials. First checks controller /indexes,
     * then (if index is set) checks /indexes/{index}.
     *
     * @return array{ok:bool, message:string}
     */
    public static function validate_pinecone(): array
    {
        $key     = Options::get_pinecone_key_raw();
        $env     = Options::get_pinecone_env();
        $project = Options::get_pinecone_project();
        $index   = Options::get_pinecone_index();

        if ($key === '') {
            return ['ok' => false, 'message' => __('Pinecone API key is not set.', 'wc-vector-indexing')];
        }
        if ($env === '') {
            return ['ok' => false, 'message' => __('Pinecone environment is not set.', 'wc-vector-indexing')];
        }

        $controller = sprintf('https://controller.%s.pinecone.io/indexes', $env);
        $headers = [
            'Api-Key'      => $key,
            'Content-Type' => 'application/json',
        ];

        $res = Http::request($controller, ['headers' => $headers, 'method' => 'GET']);
        if ($res['code'] !== 200) {
            $snippet = self::safe_error_snippet($res['body']);
            $msg = sprintf(
                __('Pinecone controller validation failed (HTTP %1$d): %2$s', 'wc-vector-indexing'),
                (int) $res['code'],
                $snippet
            );
            return ['ok' => false, 'message' => $msg];
        }

        // If index is set, try to fetch index details (dimension may be present in JSON).
        if ($index !== '') {
            $idxUrl = sprintf('https://controller.%s.pinecone.io/indexes/%s', $env, rawurlencode($index));
            $resIdx = Http::request($idxUrl, ['headers' => $headers, 'method' => 'GET']);

            if ($resIdx['code'] !== 200 || !is_array($resIdx['json'])) {
                $snippet = self::safe_error_snippet($resIdx['body']);
                $msg = sprintf(
                    __('Pinecone index check failed for "%1$s" (HTTP %2$d): %3$s', 'wc-vector-indexing'),
                    esc_html($index),
                    (int) $resIdx['code'],
                    $snippet
                );
                return ['ok' => false, 'message' => $msg];
            }

            // Try to surface dimension if available (varies by account/plan).
            $dim = $resIdx['json']['dimension'] ?? ($resIdx['json']['spec']['pod']['dimension'] ?? null);
            if ($dim) {
                $msg = sprintf(
                    __('Pinecone OK. Index "%1$s" exists (dimension: %2$d).', 'wc-vector-indexing'),
                    esc_html($index),
                    (int) $dim
                );
                return ['ok' => true, 'message' => $msg];
            }

            return ['ok' => true, 'message' => sprintf(__('Pinecone OK. Index "%s" exists.', 'wc-vector-indexing'), esc_html($index))];
        }

        return ['ok' => true, 'message' => __('Pinecone controller reachable. No index specified yet.', 'wc-vector-indexing')];
    }

    /**
     * Avoid leaking secrets in error output.
     */
    private static function safe_error_snippet(string $body): string
    {
        $trim = trim(wp_strip_all_tags($body));
        if ($trim === '') {
            return __('No response body.', 'wc-vector-indexing');
        }
        // Keep it short.
        if (strlen($trim) > 300) {
            $trim = substr($trim, 0, 300) . '…';
        }
        return $trim;
    }
}
