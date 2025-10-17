<?php
/**
 * ACF Integration (discovery helpers)
 *
 * Lightweight wrappers around ACF functions with safety checks.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

class ACF_Integration
{
    /**
     * Is Advanced Custom Fields available?
     */
    public static function is_active(): bool
    {
        return function_exists('acf') || function_exists('get_field') || class_exists('ACF');
    }

    /**
     * Return ACF field groups that target the given post types.
     *
     * @param string[] $post_types Default ['product','product_variation']
     * @return array<int, array{key:string,title:string}>
     */
    public static function get_groups_for_product_types(array $post_types = ['product', 'product_variation']): array
    {
        if (!self::is_active() || !function_exists('acf_get_field_groups')) {
            return [];
        }

        $groups = [];
        foreach ($post_types as $pt) {
            $res = acf_get_field_groups(['post_type' => $pt]);
            if (is_array($res)) {
                foreach ($res as $g) {
                    if (!isset($g['key'], $g['title'])) {
                        // ACF usually provides key+title, but be defensive.
                        continue;
                    }
                    $groups[$g['key']] = [
                        'key'   => (string) $g['key'],
                        'title' => (string) $g['title'],
                    ];
                }
            }
        }
        // Ensure numerically indexed list.
        return array_values($groups);
    }

    /**
     * Return fields for a given ACF group key.
     *
     * @param string $group_key e.g. "group_abc123"
     * @return array<int, array{key:string,name:string,label:string,type:string}>
     */
    public static function get_fields_for_group(string $group_key): array
    {
        if (!self::is_active() || !function_exists('acf_get_fields')) {
            return [];
        }

        $fields = acf_get_fields($group_key);
        if (!is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $f) {
            // Flatten sub-fields: for Flexible/Repeater/Group, ACF returns nested arrays.
            $queue = [$f];
            while ($queue) {
                $node = array_shift($queue);

                $key   = isset($node['key'])   ? (string) $node['key']   : '';
                $name  = isset($node['name'])  ? (string) $node['name']  : '';
                $label = isset($node['label']) ? (string) $node['label'] : '';
                $type  = isset($node['type'])  ? (string) $node['type']  : 'text';

                if ($key !== '' && $name !== '') {
                    $out[] = [
                        'key'   => $key,
                        'name'  => $name,
                        'label' => $label !== '' ? $label : $name,
                        'type'  => $type,
                    ];
                }

                // Enqueue sub-fields if any (eg. repeater/flexible/group/layouts).
                if (!empty($node['sub_fields']) && is_array($node['sub_fields'])) {
                    foreach ($node['sub_fields'] as $sf) {
                        $queue[] = $sf;
                    }
                }
                if (!empty($node['layouts']) && is_array($node['layouts'])) {
                    foreach ($node['layouts'] as $layout) {
                        if (!empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                            foreach ($layout['sub_fields'] as $sf) {
                                $queue[] = $sf;
                            }
                        }
                    }
                }
            }
        }

        // Deduplicate by field key.
        $uniq = [];
        foreach ($out as $row) {
            $uniq[$row['key']] = $row;
        }
        return array_values($uniq);
    }
}
