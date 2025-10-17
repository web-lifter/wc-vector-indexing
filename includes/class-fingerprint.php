<?php
/**
 * Fingerprint: stable SHA-256 content hashing for products & chunks.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

final class Fingerprint
{
    /**
     * SHA for the product-level content/config.
     *
     * @param string $normalized_text  The fully normalized, concatenated text (from Field_Normalizer).
     * @param array  $selection        Selection map (core/tax/attributes/seo/meta/acf/flags).
     * @param array  $chunking         ['size'=>int,'overlap'=>int]
     * @param string $model
     * @param int    $dimension
     */
    public static function sha_product(
        string $normalized_text,
        array $selection,
        array $chunking,
        string $model,
        int $dimension
    ): string {
        $canon = [
            'text'      => (string) $normalized_text,
            'selection' => self::canonicalize_selection($selection),
            'chunking'  => [
                'size'    => isset($chunking['size']) ? (int) $chunking['size'] : 800,
                'overlap' => isset($chunking['overlap']) ? (int) $chunking['overlap'] : 100,
            ],
            'model'     => (string) $model,
            'dimension' => (int) $dimension,
            'version'   => defined('WC_VEC_VERSION') ? (string) WC_VEC_VERSION : '0.0.0',
        ];

        $json = wp_json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', (string) $json);
    }

    /**
     * SHA for an individual chunk (binds to product sha + index + text).
     */
    public static function sha_chunk(string $chunk_text, string $product_sha, int $chunk_index): string
    {
        $payload = $product_sha . "\n" . (string) $chunk_index . "\n" . (string) $chunk_text;
        return hash('sha256', $payload);
    }

    // -----------------
    // Canonicalization
    // -----------------

    private static function canonicalize_selection(array $sel): array
    {
        $out = [
            'core'       => [],
            'tax'        => [],
            'attributes' => [],
            'seo'        => [],
            'meta'       => [], // assoc key => mode
            'acf'        => [], // list of rows
            'flags'      => [
                'show_private_meta' => !empty($sel['flags']['show_private_meta']),
            ],
        ];

        foreach (['core','tax','attributes','seo'] as $k) {
            $vals = isset($sel[$k]) && is_array($sel[$k]) ? $sel[$k] : [];
            $vals = array_map('strval', $vals);
            $vals = array_values(array_unique(array_filter($vals, 'strlen')));
            sort($vals, SORT_NATURAL | SORT_FLAG_CASE);
            $out[$k] = $vals;
        }

        $meta = isset($sel['meta']) && is_array($sel['meta']) ? $sel['meta'] : [];
        $mout = [];
        foreach ($meta as $key => $mode) {
            $k = (string) $key;
            if ($k === '') { continue; }
            $mout[$k] = ($mode === 'json') ? 'json' : 'text';
        }
        ksort($mout, SORT_NATURAL | SORT_FLAG_CASE);
        $out['meta'] = $mout;

        $acf  = isset($sel['acf']) && is_array($sel['acf']) ? $sel['acf'] : [];
        $rows = [];
        foreach ($acf as $row) {
            if (!is_array($row)) { continue; }
            $rows[] = [
                'group_key' => isset($row['group_key']) ? (string) $row['group_key'] : '',
                'field_key' => isset($row['field_key']) ? (string) $row['field_key'] : '',
                'name'      => isset($row['name']) ? (string) $row['name'] : '',
                'label'     => isset($row['label']) ? (string) $row['label'] : '',
                'type'      => isset($row['type']) ? (string) $row['type'] : 'text',
                'mode'      => (isset($row['mode']) && $row['mode'] === 'json') ? 'json' : 'text',
            ];
        }
        // Sort by stable key: field_key if present, else name, then group_key
        usort($rows, static function ($a, $b) {
            $ak = $a['field_key'] !== '' ? $a['field_key'] : $a['name'];
            $bk = $b['field_key'] !== '' ? $b['field_key'] : $b['name'];
            if ($ak === $bk) {
                return strcmp($a['group_key'], $b['group_key']);
            }
            return strcmp($ak, $bk);
        });
        $out['acf'] = array_values($rows);

        return $out;
    }
}
