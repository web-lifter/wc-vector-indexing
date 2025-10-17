<?php
/**
 * Field Normalizer
 *
 * Builds a deterministic preview payload (sections + text) for a given product
 * and a selection map from wcvec_selected_fields.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

class Field_Normalizer
{
    /**
     * Build preview data for a product and selection map.
     *
     * @param int   $product_id
     * @param array $selection See Phase P2 schema (core,tax,attributes,seo,meta,acf,flags,chunking)
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   product_id?: int,
     *   sections?: array<int, array{source:string,key:string,label:string,value:string}>,
     *   text?: string
     * }
     */
    public static function build_preview(int $product_id, array $selection): array
    {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            return ['ok' => false, 'message' => __('Product not found.', 'wc-vector-indexing')];
        }

        $sections = [];

        // Map of core key -> human label
        $core_labels = [];
        foreach (Field_Discovery::get_core_fields() as $row) {
            $core_labels[$row['key']] = $row['label'];
        }

        // 1) Core fields
        $core_keys = isset($selection['core']) && is_array($selection['core']) ? $selection['core'] : [];
        $sections = array_merge($sections, self::collect_core($product, $core_keys, $core_labels));

        // 2) Taxonomies (cats/tags) + Attributes (pa_*)
        $tax_slugs = isset($selection['tax']) && is_array($selection['tax']) ? $selection['tax'] : [];
        $attr_slugs = isset($selection['attributes']) && is_array($selection['attributes']) ? $selection['attributes'] : [];
        $sections = array_merge($sections, self::collect_taxonomies($product_id, $tax_slugs, 'tax'));
        $sections = array_merge($sections, self::collect_taxonomies($product_id, $attr_slugs, 'attribute'));

        // 3) SEO meta (Yoast/RankMath), if present
        $seo_keys = isset($selection['seo']) && is_array($selection['seo']) ? $selection['seo'] : [];
        $sections = array_merge($sections, self::collect_seo($product_id, $seo_keys));

        // 4) Custom meta (key => mode)
        $meta_map = isset($selection['meta']) && is_array($selection['meta']) ? $selection['meta'] : [];
        $show_private = !empty($selection['flags']['show_private_meta']);
        $sections = array_merge($sections, self::collect_meta($product_id, $meta_map, $show_private));

        // 5) ACF fields (array of entries)
        $acf_entries = isset($selection['acf']) && is_array($selection['acf']) ? $selection['acf'] : [];
        $sections = array_merge($sections, self::collect_acf($product_id, $acf_entries));

        // Compose the final "text" form
        $lines = [];
        foreach ($sections as $s) {
            // Skip empties
            if (!isset($s['value']) || $s['value'] === '') {
                continue;
            }
            $label = isset($s['label']) && $s['label'] !== '' ? $s['label'] : $s['key'];
            $lines[] = $label . ': ' . $s['value'];
        }
        $text = implode("\n", $lines);

        return [
            'ok'         => true,
            'product_id' => $product_id,
            'sections'   => $sections,
            'text'       => $text,
        ];
    }

    // -------------------------
    // Collectors
    // -------------------------

    private static function collect_core(\WC_Product $product, array $keys, array $labels): array
    {
        $out = [];
        $id = $product->get_id();

        foreach ($keys as $key) {
            $label = $labels[$key] ?? $key;
            $value = '';

            switch ($key) {
                case 'title':
                    $value = self::plain($product->get_name());
                    break;
                case 'short_description':
                    $value = self::plain($product->get_short_description());
                    break;
                case 'description':
                    $value = self::plain($product->get_description());
                    break;
                case 'sku':
                    $value = (string) $product->get_sku();
                    break;
                case 'price':
                    // Plain numeric string preferred for embeddings
                    $value = (string) $product->get_regular_price();
                    if ($value === '') {
                        $value = (string) $product->get_price(); // fallback
                    }
                    break;
                case 'sale_price':
                    $value = (string) $product->get_sale_price();
                    break;
                case 'stock_status':
                    $value = (string) $product->get_stock_status(); // instock|outofstock|onbackorder
                    break;
                case 'product_type':
                    $value = (string) $product->get_type(); // simple|variable|variation|...
                    break;
                case 'permalink':
                    $value = (string) get_permalink($id);
                    break;
                case 'image_alt':
                    $thumb_id = (int) get_post_thumbnail_id($id);
                    if ($thumb_id) {
                        $value = (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
                        $value = self::plain($value);
                    }
                    break;
                default:
                    // ignore unknowns
                    break;
            }

            if ($value !== '') {
                $out[] = [
                    'source' => 'core',
                    'key'    => $key,
                    'label'  => $label,
                    'value'  => $value,
                ];
            }
        }
        return $out;
    }

    private static function collect_taxonomies(int $product_id, array $slugs, string $source): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            $terms = wp_get_post_terms($product_id, $slug, ['fields' => 'names']);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }
            $label = self::taxonomy_label($slug);
            $value = implode(', ', array_map([__CLASS__, 'plain'], $terms));
            if ($value === '') {
                continue;
            }
            $out[] = [
                'source' => $source, // 'tax' or 'attribute'
                'key'    => $slug,
                'label'  => $label,
                'value'  => $value,
            ];
        }
        return $out;
    }

    private static function collect_seo(int $product_id, array $seo_keys): array
    {
        if (empty($seo_keys)) {
            return [];
        }
        $out = [];
        $map = [];
        foreach (Field_Discovery::get_seo_fields() as $row) {
            $map[$row['key']] = $row; // includes meta_key + label
        }

        foreach ($seo_keys as $key) {
            if (!isset($map[$key])) {
                continue;
            }
            $meta_key = $map[$key]['meta_key'];
            $label    = $map[$key]['label'];
            $val = get_post_meta($product_id, $meta_key, true);
            $val = self::plain((string) $val);
            if ($val === '') {
                continue;
            }
            $out[] = [
                'source' => 'seo',
                'key'    => $key,
                'label'  => $label,
                'value'  => $val,
            ];
        }
        return $out;
    }

    private static function collect_meta(int $product_id, array $meta_map, bool $include_private): array
    {
        $out = [];
        foreach ($meta_map as $key => $mode) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $is_private = $key[0] === '_';
            if ($is_private && !$include_private) {
                // Respect default privacy rules unless explicitly allowed via UI flag.
                continue;
            }

            $raw = get_post_meta($product_id, $key, true);
            $value = self::normalize_meta_value($raw, (string) $mode);

            if ($value === '') {
                continue;
            }
            $out[] = [
                'source' => 'meta',
                'key'    => $key,
                'label'  => $key,
                'value'  => $value,
            ];
        }
        return $out;
    }

    private static function collect_acf(int $product_id, array $acf_entries): array
    {
        if (empty($acf_entries)) {
            return [];
        }

        $out = [];
        $has_acf = ACF_Integration::is_active();
        foreach ($acf_entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $field_key = isset($entry['field_key']) ? (string) $entry['field_key'] : '';
            $name      = isset($entry['name']) ? (string) $entry['name'] : '';
            $label     = isset($entry['label']) ? (string) $entry['label'] : $name;
            $type      = isset($entry['type']) ? (string) $entry['type'] : 'text';
            $mode      = isset($entry['mode']) ? (string) $entry['mode'] : 'text';

            if ($field_key === '' && $name === '') {
                continue;
            }

            $raw = null;
            if ($has_acf && function_exists('get_field') && $field_key !== '') {
                // Prefer exact field key when available to bypass name collisions.
                $raw = get_field($field_key, $product_id);
                // If null, try by name as a fallback.
                if ($raw === null && $name !== '') {
                    $raw = get_field($name, $product_id);
                }
            } else {
                // No ACF: fall back to plain post meta by name.
                if ($name !== '') {
                    $raw = get_post_meta($product_id, $name, true);
                }
            }

            $val = self::normalize_acf_value($raw, $type, $mode);
            if ($val === '') {
                continue;
            }

            $out[] = [
                'source' => 'acf',
                'key'    => $name ?: $field_key,
                'label'  => $label,
                'value'  => $val,
            ];
        }

        return $out;
    }

    // -------------------------
    // Normalizers
    // -------------------------

    private static function normalize_meta_value($raw, string $mode): string
    {
        if ($raw === '' || $raw === null) {
            return '';
        }

        if ($mode === 'json') {
            // If scalar, wrap in array for consistency?
            if (is_string($raw) || is_numeric($raw) || is_bool($raw)) {
                $encoded = wp_json_encode($raw);
                return is_string($encoded) ? $encoded : (string) $raw;
            }
            if (is_array($raw) || is_object($raw)) {
                $encoded = wp_json_encode($raw, JSON_UNESCAPED_UNICODE);
                return is_string($encoded) ? $encoded : '';
            }
            return '';
        }

        // text mode: flatten arrays into readable text
        if (is_array($raw)) {
            $flat = self::flatten_array($raw);
            return self::plain(implode('; ', $flat));
        }

        return self::plain((string) $raw);
    }

    private static function normalize_acf_value($raw, string $type, string $mode): string
    {
        if ($raw === '' || $raw === null) {
            return '';
        }

        // JSON mode: always encode as compact JSON
        if ($mode === 'json') {
            $encoded = wp_json_encode($raw, JSON_UNESCAPED_UNICODE);
            return is_string($encoded) ? $encoded : '';
        }

        // TEXT mode: type-driven flattening
        switch ($type) {
            case 'text':
            case 'textarea':
            case 'wysiwyg':
                return self::plain((string) $raw);

            case 'true_false':
                return $raw ? 'true' : 'false';

            case 'number':
            case 'range':
                return (string) $raw;

            case 'select':
            case 'radio':
            case 'checkbox':
                // ACF returns label values when configured; if it's an array, join.
                if (is_array($raw)) {
                    $flat = self::flatten_array($raw);
                    return self::plain(implode(', ', $flat));
                }
                return self::plain((string) $raw);

            case 'date_picker':
            case 'date_time_picker':
            case 'time_picker':
                // Keep ISO-like human text
                return self::plain((string) $raw);

            case 'taxonomy':
                // ACF taxonomy fields may return IDs or term arrays depending on settings
                if (is_array($raw)) {
                    // attempt to extract names
                    $names = [];
                    foreach ($raw as $term) {
                        if (is_array($term) && isset($term['name'])) {
                            $names[] = (string) $term['name'];
                        } elseif (is_numeric($term)) {
                            $t = get_term((int) $term);
                            if ($t && !is_wp_error($t)) {
                                $names[] = $t->name;
                            }
                        } elseif (is_string($term)) {
                            $names[] = $term;
                        }
                    }
                    $names = array_filter(array_map([__CLASS__, 'plain'], $names), 'strlen');
                    return implode(', ', $names);
                }
                return self::plain((string) $raw);

            case 'post_object':
            case 'relationship':
                // Could be single ID or array of IDs/objects
                $titles = [];
                if (is_array($raw)) {
                    foreach ($raw as $item) {
                        $titles[] = self::object_to_title($item);
                    }
                } else {
                    $titles[] = self::object_to_title($raw);
                }
                $titles = array_filter($titles, 'strlen');
                return implode(', ', $titles);

            case 'repeater':
            case 'flexible_content':
            case 'group':
                // Flatten key: value pairs; for nested structures build semi-colon delimited lines
                if (is_array($raw)) {
                    $parts = self::flatten_kv($raw);
                    $parts = array_filter(array_map([__CLASS__, 'plain'], $parts), 'strlen');
                    return implode('; ', $parts);
                }
                return self::plain((string) $raw);

            case 'image':
            case 'gallery':
            case 'file':
                // Prefer alt text/caption if available; otherwise URL string
                if (is_array($raw)) {
                    $pieces = [];
                    if (isset($raw['alt']) && $raw['alt'] !== '') {
                        $pieces[] = $raw['alt'];
                    }
                    if (isset($raw['caption']) && $raw['caption'] !== '') {
                        $pieces[] = $raw['caption'];
                    }
                    if (empty($pieces) && isset($raw['url'])) {
                        $pieces[] = $raw['url'];
                    }
                    $pieces = array_filter(array_map([__CLASS__, 'plain'], $pieces), 'strlen');
                    return implode('; ', $pieces);
                }
                return self::plain((string) $raw);

            default:
                // Unknown type → best-effort to text
                if (is_array($raw)) {
                    $flat = self::flatten_array($raw);
                    return self::plain(implode('; ', $flat));
                }
                return self::plain((string) $raw);
        }
    }

    // -------------------------
    // Helpers
    // -------------------------

    private static function plain(string $htmlish): string
    {
        $s = wp_strip_all_tags($htmlish, true);
        $s = preg_replace('/\s+/u', ' ', $s ?? '');
        return trim((string) $s);
    }

    private static function taxonomy_label(string $slug): string
    {
        $tx = get_taxonomy($slug);
        if ($tx && isset($tx->labels->name)) {
            return (string) $tx->labels->name;
        }
        // Fallbacks for common slugs
        if ($slug === 'product_cat') {
            return __('Categories', 'wc-vector-indexing');
        }
        if ($slug === 'product_tag') {
            return __('Tags', 'wc-vector-indexing');
        }
        return $slug;
    }

    private static function flatten_array(array $arr): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($arr));
        foreach ($it as $v) {
            if (is_scalar($v)) {
                $out[] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * Flatten associative/nested arrays into "Label: value" parts.
     * Example: [ ['size'=>'M','color'=>'Blue'], ['size'=>'L'] ] → ["size: M; color: Blue", "size: L"]
     */
    private static function flatten_kv(array $arr): array
    {
        $parts = [];

        // Normalize to list of rows
        $rows = self::ensure_list($arr);

        foreach ($rows as $row) {
            if (is_array($row)) {
                // Collect simple key:value pairs from this row (one level deep)
                $kv_parts = [];
                foreach ($row as $k => $v) {
                    if (is_array($v)) {
                        $vals = self::flatten_array($v);
                        $kv_parts[] = sprintf('%s: %s', $k, implode(', ', $vals));
                    } elseif (is_scalar($v)) {
                        $kv_parts[] = sprintf('%s: %s', $k, (string) $v);
                    }
                }
                if (!empty($kv_parts)) {
                    $parts[] = implode('; ', $kv_parts);
                }
            } elseif (is_scalar($row)) {
                $parts[] = (string) $row;
            }
        }
        return $parts;
    }

    private static function ensure_list($maybe_list): array
    {
        // If assoc array, wrap as single row; if already list, return as-is.
        if (!is_array($maybe_list)) {
            return [$maybe_list];
        }
        $is_list = array_keys($maybe_list) === range(0, count($maybe_list) - 1);
        return $is_list ? $maybe_list : [$maybe_list];
    }

    private static function object_to_title($item): string
    {
        if (is_numeric($item)) {
            $p = get_post((int) $item);
            return $p ? self::plain($p->post_title) : '';
        }
        if (is_array($item)) {
            if (isset($item['post_title'])) {
                return self::plain((string) $item['post_title']);
            }
            if (isset($item['ID'])) {
                $p = get_post((int) $item['ID']);
                return $p ? self::plain($p->post_title) : '';
            }
            if (isset($item['title'])) {
                return self::plain((string) $item['title']);
            }
        }
        if (is_object($item)) {
            if (isset($item->post_title)) {
                return self::plain((string) $item->post_title);
            }
            if (isset($item->ID)) {
                $p = get_post((int) $item->ID);
                return $p ? self::plain($p->post_title) : '';
            }
        }
        // Fallback to string cast
        return is_scalar($item) ? self::plain((string) $item) : '';
    }
}
