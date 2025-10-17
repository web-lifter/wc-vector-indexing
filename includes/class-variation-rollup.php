<?php
/**
 * Build deterministic roll-up text for a parent variable product by aggregating
 * child variation info (attributes, prices, SKUs, and selected ACF fields).
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

final class Variation_Rollup
{
    /**
     * Build a text block to append to the parent product's normalized text.
     *
     * @param int   $parent_product_id
     * @param array $selection  Field selection map from Options::get_selected_fields()
     * @return string Empty string if no variations or not variable.
     */
    public static function build(int $parent_product_id, array $selection): string
    {
        $parent = wc_get_product($parent_product_id);
        if (!$parent || $parent->get_type() !== 'variable') {
            return '';
        }

        $max_vars   = Options::get_rollup_max_variations();
        $values_cap = Options::get_rollup_values_cap();

        // Fetch direct child variations (limit for safety)
        $variation_ids = get_posts([
            'post_type'      => 'product_variation',
            'post_status'    => Options::include_drafts_private() ? ['publish','draft','private'] : ['publish'],
            'numberposts'    => $max_vars,
            'fields'         => 'ids',
            'post_parent'    => $parent_product_id,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'suppress_filters'       => true,
        ]);
        $variation_ids = array_map('intval', (array) $variation_ids);
        if (empty($variation_ids)) {
            return '';
        }

        // Aggregates
        $attr_values = [];           // [ 'attribute_pa_size' => ['s','m'] ... ] (pretty names later)
        $min_price = null; $max_price = null;
        $skus = [];
        $acf_values = [];            // [ 'field_name' => set of values ]
        $count = 0;

        // Which ACF field names are selected for variations?
        $selected_acf = self::selected_acf_field_names_for_variations($selection);

        foreach ($variation_ids as $vid) {
            $v = wc_get_product($vid);
            if (!$v) { continue; }
            $count++;

            // Attributes (slug => value or term slug)
            $atts = (array) $v->get_attributes();
            foreach ($atts as $key => $val) {
                $key = (string) $key;
                if (!isset($attr_values[$key])) $attr_values[$key] = [];
                $pretty = self::pretty_attribute_value($key, (string) $val, $parent_product_id);
                if ($pretty !== '') { $attr_values[$key][$pretty] = true; }
            }

            // Price (current price)
            $p = $v->get_price();
            if ($p !== '') {
                $f = (float) $p;
                $min_price = is_null($min_price) ? $f : min($min_price, $f);
                $max_price = is_null($max_price) ? $f : max($max_price, $f);
            }

            // SKU
            $sku = $v->get_sku();
            if ($sku) { $skus[$sku] = true; }

            // ACF (only a light roll-up of scalar values)
            foreach ($selected_acf as $acf_name) {
                $val = self::safe_get_acf($acf_name, $vid);
                if (is_scalar($val) && $val !== '' && $val !== null) {
                    if (!isset($acf_values[$acf_name])) $acf_values[$acf_name] = [];
                    $acf_values[$acf_name][(string) $val] = true;
                }
            }
        }

        // Deterministic output
        ksort($attr_values, SORT_NATURAL);
        $lines = [];

        $lines[] = 'Variations summary:';
        $lines[] = "- Count: {$count}";

        if (!is_null($min_price) && !is_null($max_price)) {
            $lines[] = '- Price range: ' . self::format_money($min_price, $parent) . ' – ' . self::format_money($max_price, $parent);
        }

        // Attributes (pretty labels and sorted values)
        foreach ($attr_values as $akey => $set) {
            $label = self::attribute_label($akey, $parent_product_id);
            $vals  = array_keys($set);
            sort($vals, SORT_NATURAL | SORT_FLAG_CASE);

            $more = '';
            if (count($vals) > $values_cap) {
                $more = ' +' . (count($vals) - $values_cap) . ' more';
                $vals = array_slice($vals, 0, $values_cap);
            }
            $lines[] = "- {$label}: " . implode(', ', $vals) . $more;
        }

        // SKU list (short, helpful for matching; cap to values_cap)
        if (!empty($skus)) {
            $skuList = array_keys($skus);
            sort($skuList, SORT_NATURAL);
            if (count($skuList) > $values_cap) {
                $suffix = ' +' . (count($skuList) - $values_cap) . ' more';
                $skuList = array_slice($skuList, 0, $values_cap);
            } else {
                $suffix = '';
            }
            $lines[] = '- SKUs: ' . implode(', ', $skuList) . $suffix;
        }

        // ACF roll-up (field label if we can get it; otherwise name)
        if (!empty($acf_values)) {
            $lines[] = 'ACF (variation fields):';
            foreach ($acf_values as $field_name => $set) {
                $label = self::acf_label_fallback($field_name);
                $vals  = array_keys($set);
                sort($vals, SORT_NATURAL | SORT_FLAG_CASE);

                $more = '';
                if (count($vals) > $values_cap) {
                    $more = ' +' . (count($vals) - $values_cap) . ' more';
                    $vals = array_slice($vals, 0, $values_cap);
                }
                $lines[] = "• {$label}: " . implode(', ', $vals) . $more;
            }
        }

        return "\n\n" . implode("\n", $lines) . "\n";
    }

    /** Convert an attribute value to a pretty label (taxonomy term name if applicable). */
    private static function pretty_attribute_value(string $attr_key, string $raw, int $parent_product_id): string
    {
        if ($raw === '') return '';

        // Taxonomy-based attribute e.g. attribute_pa_color
        if (str_starts_with($attr_key, 'attribute_pa_')) {
            $tax = substr($attr_key, strlen('attribute_pa_'));
            $taxonomy = wc_attribute_taxonomy_name($tax);
            $term = get_term_by('slug', $raw, $taxonomy);
            return $term && !is_wp_error($term) ? (string) $term->name : $raw;
        }

        // Custom (non-taxonomy) attribute, store plain
        return $raw;
    }

    /** Attribute label for display. */
    private static function attribute_label(string $attr_key, int $parent_product_id): string
    {
        if (str_starts_with($attr_key, 'attribute_pa_')) {
            $tax = substr($attr_key, strlen('attribute_pa_'));
            return wc_attribute_label(wc_attribute_taxonomy_name($tax), $parent_product_id);
        }
        // Non-taxonomy attribute: remove prefix if present and beautify
        $key = $attr_key;
        if (str_starts_with($key, 'attribute_')) $key = substr($key, strlen('attribute_'));
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /** Format price per store currency settings (fallback to raw). */
    private static function format_money(float $amount, \WC_Product $product): string
    {
        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($amount));
        }
        return number_format($amount, 2, '.', '');
    }

    /** Extract selected ACF field names that may exist on variations. */
    private static function selected_acf_field_names_for_variations(array $selection): array
    {
        // Expected shape from P2: $selection['acf'] = [ ['name'=>'field_name', ...], ... ]
        $out = [];
        if (isset($selection['acf']) && is_array($selection['acf'])) {
            foreach ($selection['acf'] as $f) {
                if (!empty($f['name']) && is_string($f['name'])) {
                    $out[] = $f['name'];
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Safe ACF getter (returns null if ACF missing). */
    private static function safe_get_acf(string $field_name, int $post_id)
    {
        if (!function_exists('get_field')) return null;
        // Silence errors; return null if not present
        try {
            return get_field($field_name, $post_id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Try to get a friendly ACF label; fallback to field name. */
    private static function acf_label_fallback(string $field_name): string
    {
        if (function_exists('acf_get_field')) {
            $f = acf_get_field($field_name);
            if (is_array($f) && !empty($f['label'])) {
                return (string) $f['label'];
            }
        }
        return ucwords(str_replace(['_', '-'], ' ', $field_name));
    }
}
