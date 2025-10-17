<?php
/**
 * Field Discovery
 *
 * Enumerates Core Woo fields, taxonomies/attributes, SEO fields (Yoast/RankMath),
 * custom meta keys, and ACF groups/fields (via ACF_Integration).
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

class Field_Discovery
{
    /**
     * Core Woo product fields we expose for indexing.
     *
     * @return array<int, array{key:string,label:string}>
     */
    public static function get_core_fields(): array
    {
        return [
            ['key' => 'title',             'label' => __('Title', 'wc-vector-indexing')],
            ['key' => 'short_description', 'label' => __('Short Description', 'wc-vector-indexing')],
            ['key' => 'description',       'label' => __('Description', 'wc-vector-indexing')],
            ['key' => 'sku',               'label' => __('SKU', 'wc-vector-indexing')],
            ['key' => 'price',             'label' => __('Price', 'wc-vector-indexing')],
            ['key' => 'sale_price',        'label' => __('Sale Price', 'wc-vector-indexing')],
            ['key' => 'stock_status',      'label' => __('Stock Status', 'wc-vector-indexing')],
            ['key' => 'product_type',      'label' => __('Product Type', 'wc-vector-indexing')],
            ['key' => 'permalink',         'label' => __('Permalink', 'wc-vector-indexing')],
            ['key' => 'image_alt',         'label' => __('Main Image Alt Text', 'wc-vector-indexing')],
        ];
    }

    /**
     * Product taxonomies (categories, tags) and attribute taxonomies (pa_*).
     *
     * @return array{
     *   tax: array<int, array{slug:string,label:string}>,
     *   attributes: array<int, array{slug:string,label:string}>
     * }
     */
    public static function get_taxonomies(): array
    {
        $tax = [
            ['slug' => 'product_cat', 'label' => __('Categories', 'wc-vector-indexing')],
            ['slug' => 'product_tag', 'label' => __('Tags', 'wc-vector-indexing')],
        ];

        $attributes = [];
        if (function_exists('wc_get_attribute_taxonomies')) {
            $atts = wc_get_attribute_taxonomies(); // returns objects with attribute_name/label
            if (is_array($atts)) {
                foreach ($atts as $a) {
                    $slug = 'pa_' . sanitize_title($a->attribute_name);
                    $label = isset($a->attribute_label) && $a->attribute_label !== ''
                        ? $a->attribute_label
                        : $slug;
                    $attributes[] = [
                        'slug'  => $slug,
                        'label' => $label,
                    ];
                }
            }
        }

        return [
            'tax'        => $tax,
            'attributes' => $attributes,
        ];
    }

    /**
     * SEO fields from Yoast and RankMath if present.
     *
     * @return array<int, array{key:string,label:string,meta_key:string,provider:string}>
     */
    public static function get_seo_fields(): array
    {
        $out = [];

        // Yoast detection.
        $yoast_active = defined('WPSEO_VERSION') || class_exists('WPSEO_Options') || function_exists('wpseo_replace_vars');
        if ($yoast_active) {
            $out[] = [
                'key'       => 'yoast_title',
                'label'     => __('Yoast SEO Title', 'wc-vector-indexing'),
                'meta_key'  => '_yoast_wpseo_title',
                'provider'  => 'yoast',
            ];
            $out[] = [
                'key'       => 'yoast_description',
                'label'     => __('Yoast Meta Description', 'wc-vector-indexing'),
                'meta_key'  => '_yoast_wpseo_metadesc',
                'provider'  => 'yoast',
            ];
        }

        // RankMath detection.
        $rm_active = defined('RANK_MATH_VERSION') || class_exists('\RankMath\Helper');
        if ($rm_active) {
            $out[] = [
                'key'       => 'rankmath_title',
                'label'     => __('RankMath Title', 'wc-vector-indexing'),
                'meta_key'  => 'rank_math_title',
                'provider'  => 'rankmath',
            ];
            $out[] = [
                'key'       => 'rankmath_description',
                'label'     => __('RankMath Description', 'wc-vector-indexing'),
                'meta_key'  => 'rank_math_description',
                'provider'  => 'rankmath',
            ];
        }

        return $out;
    }

    /**
     * Return a list of candidate custom meta keys for a given product (fast-path).
     * Default: excludes '_' prefixed keys unless include_private=true.
     *
     * @param array{
     *   product_id?:int,
     *   include_private?:bool,
     *   q?:string
     * } $args
     * @return array<int, array{key:string,label:string,private:bool}>
     */
    public static function get_custom_meta_keys(array $args = []): array
    {
        $product_id      = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $include_private = !empty($args['include_private']);
        $q               = isset($args['q']) ? (string) $args['q'] : '';

        if ($product_id <= 0) {
            return []; // we’ll add broader scans later if needed.
        }

        $all = get_post_meta($product_id);
        if (!is_array($all)) {
            return [];
        }

        // Meta keys to ignore by default.
        $blacklist = [
            '_edit_lock', '_edit_last', '_wp_old_slug',
            '_thumbnail_id', '_product_image_gallery', '_wc_review_count',
            '_wc_average_rating', '_wc_rating_count',
            '_wc_review_count', '_sale_price_dates_from', '_sale_price_dates_to',
        ];

        $keys = [];
        foreach ($all as $key => $vals) {
            if (in_array($key, $blacklist, true)) {
                continue;
            }
            $is_private = strlen($key) > 0 && $key[0] === '_';
            if ($is_private && !$include_private) {
                continue;
            }
            if ($q !== '' && stripos($key, $q) === false) {
                continue;
            }
            $keys[$key] = [
                'key'     => $key,
                'label'   => $key, // basic label; UI can prettify later
                'private' => $is_private,
            ];
        }

        // Return sorted by key for deterministic UI.
        ksort($keys, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($keys);
    }

    /**
     * Build a unified catalog used by the Fields UI.
     *
     * @param array{include_private_meta?:bool, product_id?:int} $args
     * @return array{
     *   core: array<int, array{key:string,label:string}>,
     *   tax: array<int, array{slug:string,label:string}>,
     *   attributes: array<int, array{slug:string,label:string}>,
     *   seo: array<int, array{key:string,label:string,meta_key:string,provider:string}>,
     *   meta: array<int, array{key:string,label:string,private:bool}>,
     *   acf: array<int, array{group:array{key:string,title:string},fields:array<int,array{key:string,name:string,label:string,type:string}>}>
     * }
     */
    public static function get_catalog(array $args = []): array
    {
        $include_private = !empty($args['include_private_meta']);
        $product_id      = isset($args['product_id']) ? (int) $args['product_id'] : 0;

        $core = self::get_core_fields();

        $tx = self::get_taxonomies();
        $tax = $tx['tax'];
        $attributes = $tx['attributes'];

        $seo = self::get_seo_fields();

        $meta = self::get_custom_meta_keys([
            'product_id'      => $product_id,
            'include_private' => $include_private,
        ]);

        $acf_catalog = [];
        if (ACF_Integration::is_active()) {
            $groups = ACF_Integration::get_groups_for_product_types(['product', 'product_variation']);
            foreach ($groups as $g) {
                $fields = ACF_Integration::get_fields_for_group($g['key']);
                if (!$fields) {
                    continue;
                }
                $acf_catalog[] = [
                    'group'  => $g,
                    'fields' => $fields,
                ];
            }
        }

        return [
            'core'       => $core,
            'tax'        => $tax,
            'attributes' => $attributes,
            'seo'        => $seo,
            'meta'       => $meta,
            'acf'        => $acf_catalog,
        ];
    }
}
