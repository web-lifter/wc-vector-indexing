<?php
/**
 * Options API Wrapper
 *
 * Centralizes typed getters/setters and sanitization.
 * Secrets are transparently encrypted/decrypted using Secure_Options.
 *
 * @package WCVec
 */

namespace WCVec;

defined('ABSPATH') || exit;

class Options
{
    // === Option keys ===
    public const OAI_KEY      = 'wcvec_api_openai_key';
    public const PINE_KEY     = 'wcvec_api_pinecone_key';
    public const PINE_ENV     = 'wcvec_pinecone_env';
    public const PINE_PROJECT = 'wcvec_pinecone_project';
    public const PINE_INDEX   = 'wcvec_pinecone_index';
    public const MODEL        = 'wcvec_embedding_model';
    public const DIMENSION    = 'wcvec_embedding_dimension';
    public const OAI_VS_ID    = 'wcvec_openai_vectorstore_id';

    // About page
    public const ABOUT_COMPANY       = 'wcvec_about_company_name';
    public const ABOUT_BLURB         = 'wcvec_about_company_blurb';
    public const ABOUT_LOGO_URL      = 'wcvec_about_company_logo_url';
    public const ABOUT_PRODUCTS      = 'wcvec_about_products';
    public const ABOUT_SUPPORT_URL   = 'wcvec_about_support_url';
    public const ABOUT_CONTACT_EMAIL = 'wcvec_about_contact_email';

    // === Embedding models (as per project spec) ===
    public const MODEL_3_LARGE = 'text-embedding-3-large'; // 1536
    public const MODEL_3_SMALL = 'text-embedding-3-small'; // 3072
    public const MODEL_ADA002  = 'text-embedding-ada-002'; // 1536

    public const SELECTED_FIELDS = 'wcvec_selected_fields';

    /**
     * Default option values.
     */
    public static function defaults(): array
    {
        return [
            self::OAI_KEY      => '',
            self::PINE_KEY     => '',
            self::PINE_ENV     => '',
            self::PINE_PROJECT => '',
            self::PINE_INDEX   => '',
            self::MODEL        => self::MODEL_3_SMALL,
            self::DIMENSION    => 3072, // matches 3-small as per spec
            self::OAI_VS_ID    => '',
            // About
            self::ABOUT_COMPANY       => '',
            self::ABOUT_BLURB         => '',
            self::ABOUT_LOGO_URL      => '',
            self::ABOUT_PRODUCTS      => [], // array of product entries
            self::ABOUT_SUPPORT_URL   => '',
            self::ABOUT_CONTACT_EMAIL => '',
            self::SELECTED_FIELDS => [
                'core'       => [],
                'tax'        => [],
                'attributes' => [],
                'seo'        => [],
                'meta'       => [],   // key => mode ('text'|'json')
                'acf'        => [],   // list of entries (see setter)
                'flags'      => ['show_private_meta' => false],
                'chunking'   => ['size' => 800, 'overlap' => 100],
            ],
        ];
    }

    /**
     * Low-level getter with default.
     *
     * @param string $key
     * @return mixed
     */
    private static function get(string $key)
    {
        $defaults = self::defaults();
        $default = array_key_exists($key, $defaults) ? $defaults[$key] : null;
        return get_option($key, $default);
    }

    /**
     * Low-level setter (sanitization handled by callers).
     *
     * @param string $key
     * @param mixed  $value
     */
    private static function set(string $key, $value): void
    {
        update_option($key, $value);
    }

    // ====== Secrets (encrypted) ======

    public static function get_openai_key_raw(): string
    {
        $stored = (string) self::get(self::OAI_KEY);
        return Secure_Options::decrypt($stored);
    }

    public static function set_openai_key_raw(string $key): void
    {
        $key = trim((string) $key);
        $enc = Secure_Options::encrypt($key);
        self::set(self::OAI_KEY, $enc);
    }

    public static function get_openai_key_masked(): string
    {
        $raw = self::get_openai_key_raw();
        return Secure_Options::mask($raw);
    }

    public static function get_pinecone_key_raw(): string
    {
        $stored = (string) self::get(self::PINE_KEY);
        return Secure_Options::decrypt($stored);
    }

    public static function set_pinecone_key_raw(string $key): void
    {
        $key = trim((string) $key);
        $enc = Secure_Options::encrypt($key);
        self::set(self::PINE_KEY, $enc);
    }

    public static function get_pinecone_key_masked(): string
    {
        $raw = self::get_pinecone_key_raw();
        return Secure_Options::mask($raw);
    }

    // ====== Pinecone config ======

    public static function get_pinecone_env(): string
    {
        return sanitize_text_field((string) self::get(self::PINE_ENV));
    }

    public static function set_pinecone_env(string $env): void
    {
        self::set(self::PINE_ENV, sanitize_text_field($env));
    }

    public static function get_pinecone_project(): string
    {
        return sanitize_text_field((string) self::get(self::PINE_PROJECT));
    }

    public static function set_pinecone_project(string $project): void
    {
        self::set(self::PINE_PROJECT, sanitize_text_field($project));
    }

    public static function get_pinecone_index(): string
    {
        return sanitize_text_field((string) self::get(self::PINE_INDEX));
    }

    public static function set_pinecone_index(string $index): void
    {
        self::set(self::PINE_INDEX, sanitize_text_field($index));
    }

    // ====== Model + Dimension ======

    /**
     * Allowed models.
     *
     * @return string[]
     */
    public static function allowed_models(): array
    {
        return [self::MODEL_3_LARGE, self::MODEL_3_SMALL, self::MODEL_ADA002];
    }

    /**
     * Default dimension for each model (per spec).
     */
    public static function model_default_dimension(string $model): int
    {
        switch ($model) {
            case self::MODEL_3_LARGE:
                return 1536;
            case self::MODEL_3_SMALL:
                return 3072;
            case self::MODEL_ADA002:
            default:
                return 1536;
        }
    }

    public static function get_model(): string
    {
        $model = (string) self::get(self::MODEL);
        if (!in_array($model, self::allowed_models(), true)) {
            $model = self::defaults()[self::MODEL];
        }
        return $model;
    }

    public static function set_model(string $model): void
    {
        $model = in_array($model, self::allowed_models(), true) ? $model : self::defaults()[self::MODEL];
        self::set(self::MODEL, $model);

        // If current dimension doesn't match default for new model, update it.
        $current = (int) self::get(self::DIMENSION);
        $expected = self::model_default_dimension($model);
        if ($current !== $expected) {
            self::set_dimension($expected);
        }
    }

    public static function get_dimension(): int
    {
        $dim = (int) self::get(self::DIMENSION);
        // Hard guard: ensure positive.
        if ($dim <= 0) {
            $dim = self::model_default_dimension(self::get_model());
            self::set_dimension($dim);
        }
        return $dim;
    }

    public static function set_dimension(int $dimension): void
    {
        $dimension = max(1, (int) $dimension);
        self::set(self::DIMENSION, $dimension);
    }

    // ====== OpenAI Vector Store ======

    public static function get_openai_vectorstore_id(): string
    {
        return sanitize_text_field((string) self::get(self::OAI_VS_ID));
    }

    public static function set_openai_vectorstore_id(string $id): void
    {
        self::set(self::OAI_VS_ID, sanitize_text_field($id));
    }

    // ====== About page ======

    public static function get_about_company_name(): string
    {
        return sanitize_text_field((string) self::get(self::ABOUT_COMPANY));
    }

    public static function set_about_company_name(string $name): void
    {
        self::set(self::ABOUT_COMPANY, sanitize_text_field($name));
    }

    public static function get_about_blurb(): string
    {
        // Allow limited HTML: wp_kses_post at render time; store raw-ish sanitized text here.
        return (string) self::get(self::ABOUT_BLURB);
    }

    public static function set_about_blurb(string $blurb): void
    {
        // Store as-is; sanitize on render with wp_kses_post
        self::set(self::ABOUT_BLURB, (string) $blurb);
    }

    public static function get_about_logo_url(): string
    {
        return esc_url_raw((string) self::get(self::ABOUT_LOGO_URL));
    }

    public static function set_about_logo_url(string $url): void
    {
        self::set(self::ABOUT_LOGO_URL, esc_url_raw($url));
    }

    public static function get_about_support_url(): string
    {
        return esc_url_raw((string) self::get(self::ABOUT_SUPPORT_URL));
    }

    public static function set_about_support_url(string $url): void
    {
        self::set(self::ABOUT_SUPPORT_URL, esc_url_raw($url));
    }

    public static function get_about_contact_email(): string
    {
        return sanitize_email((string) self::get(self::ABOUT_CONTACT_EMAIL));
    }

    public static function set_about_contact_email(string $email): void
    {
        self::set(self::ABOUT_CONTACT_EMAIL, sanitize_email($email));
    }

    /**
     * Products array: each item { title, desc, url, icon }
     *
     * @return array<int, array{title:string,desc:string,url:string,icon:string}>
     */
    public static function get_about_products(): array
    {
        $val = self::get(self::ABOUT_PRODUCTS);
        return is_array($val) ? $val : [];
    }

    /**
     * @param array $products
     */
    public static function set_about_products(array $products): void
    {
        $sanitized = [];
        foreach ($products as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = isset($row['title']) ? sanitize_text_field((string) $row['title']) : '';
            $desc  = isset($row['desc']) ? (string) $row['desc'] : '';
            $url   = isset($row['url']) ? esc_url_raw((string) $row['url']) : '';
            $icon  = isset($row['icon']) ? esc_url_raw((string) $row['icon']) : '';

            // Skip empty rows
            if ($title === '' && $desc === '' && $url === '' && $icon === '') {
                continue;
            }

            $sanitized[] = [
                'title' => $title,
                'desc'  => $desc,
                'url'   => $url,
                'icon'  => $icon,
            ];
        }
        self::set(self::ABOUT_PRODUCTS, $sanitized);
    }

    /**
     * @param array $stored
     */    

    /** Get full selection map (with defaults merged). */
    public static function get_selected_fields(): array
    {
        $stored = get_option(self::SELECTED_FIELDS, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        // Merge shallowly with defaults to ensure keys exist.
        return wp_parse_args($stored, self::defaults()[self::SELECTED_FIELDS]);
    }

    /**
     * Products array: each item { group_key, field_key, name, label, type, mode  }
     *
     * @return array<int, array{group_key:string,field_key:string,name:string,label:string,type:string,mode:string}>
     */

    /** Persist selection map with deep sanitization. */
    public static function set_selected_fields(array $map): void
    {
        $out = self::defaults()[self::SELECTED_FIELDS];

        // Core / Tax / Attributes / SEO — lists of strings
        foreach (['core','tax','attributes','seo'] as $k) {
            $vals = isset($map[$k]) && is_array($map[$k]) ? array_values($map[$k]) : [];
            $vals = array_map('sanitize_text_field', $vals);
            $vals = array_values(array_unique(array_filter($vals, 'strlen')));
            $out[$k] = $vals;
        }

        // Meta — assoc key => mode
        $meta = isset($map['meta']) && is_array($map['meta']) ? $map['meta'] : [];
        $meta_out = [];
        foreach ($meta as $key => $mode) {
            $mkey = sanitize_text_field((string) $key);
            $mmode = (string) $mode === 'json' ? 'json' : 'text';
            if ($mkey !== '') {
                $meta_out[$mkey] = $mmode;
            }
        }
        $out['meta'] = $meta_out;

        // ACF — list of entries: group_key, field_key, name, label, type, mode
        $acf = isset($map['acf']) && is_array($map['acf']) ? $map['acf'] : [];
        $acf_out = [];
        foreach ($acf as $row) {
            if (!is_array($row)) { continue; }
            $group_key = isset($row['group_key']) ? sanitize_text_field((string) $row['group_key']) : '';
            $field_key = isset($row['field_key']) ? sanitize_text_field((string) $row['field_key']) : '';
            $name      = isset($row['name'])      ? sanitize_text_field((string) $row['name'])      : '';
            $label     = isset($row['label'])     ? sanitize_text_field((string) $row['label'])     : '';
            $type      = isset($row['type'])      ? sanitize_text_field((string) $row['type'])      : 'text';
            $mode      = (isset($row['mode']) && $row['mode'] === 'json') ? 'json' : 'text';
            if ($field_key === '' && $name === '') { continue; }
            $acf_out[] = compact('group_key','field_key','name','label','type','mode');
        }
        $out['acf'] = $acf_out;

        // Flags
        $flags = isset($map['flags']) && is_array($map['flags']) ? $map['flags'] : [];
        $out['flags']['show_private_meta'] = !empty($flags['show_private_meta']);

        // Chunking (kept for future phases)
        if (isset($map['chunking']['size'])) {
            $out['chunking']['size'] = max(1, (int) $map['chunking']['size']);
        }
        if (isset($map['chunking']['overlap'])) {
            $out['chunking']['overlap'] = max(0, (int) $map['chunking']['overlap']);
        }

        update_option(self::SELECTED_FIELDS, $out);
    }

    // ====== Convenience checks for status endpoint ======

    public static function is_openai_configured(): bool
    {
        return self::get_openai_key_raw() !== '';
    }

    public static function is_pinecone_configured(): bool
    {
        return self::get_pinecone_key_raw() !== '';
    }

        /** Enabled targets. Defaults to ['pinecone','openai'] */
    public static function get_targets_enabled(): array
    {
        $raw = get_option('wcvec_targets_enabled', ['pinecone','openai']);
        if (!is_array($raw)) { $raw = ['pinecone','openai']; }
        $allowed = ['pinecone','openai'];
        $out = [];
        foreach ($raw as $t) {
            $t = (string) $t;
            if (in_array($t, $allowed, true) && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        // safety default
        if (empty($out)) { $out = ['pinecone','openai']; }
        return $out;
    }

    /** Batch upsert size. Default 100, range 10..500. */
    public static function get_batch_upsert_size(): int
    {
        $n = (int) get_option('wcvec_batch_upsert_size', 100);
        if ($n < 10)  { $n = 10; }
        if ($n > 500) { $n = 500; }
        return $n;
    }

        /** Whether automatic background sync is enabled (default true). */
    public static function auto_sync_enabled(): bool
    {
        $v = get_option('wcvec_auto_sync_enabled', true);
        return (bool) $v;
    }

    /** Scheduler cadence enum: 5min|15min|hourly|twicedaily|daily (default 15min). */
    public static function get_scheduler_cadence(): string
    {
        $v = (string) get_option('wcvec_scheduler_cadence', '15min');
        $allowed = ['5min','15min','hourly','twicedaily','daily'];
        return in_array($v, $allowed, true) ? $v : '15min';
    }

    /** Max concurrent index jobs in-flight (default 3, clamp 1..10). */
    public static function get_max_concurrent_jobs(): int
    {
        $n = (int) get_option('wcvec_max_concurrent_jobs', 3);
        if ($n < 1)  $n = 1;
        if ($n > 10) $n = 10;
        return $n;
    }

    /** Max products to consider per scan (default 200, clamp 20..2000). */
    public static function get_scan_batch_limit(): int
    {
        $n = (int) get_option('wcvec_scan_batch_limit', 200);
        if ($n < 20)   $n = 20;
        if ($n > 2000) $n = 2000;
        return $n;
    }

    /** Last completed scan timestamp (GMT ISO8601) — for internal use. */
    public static function get_last_scan_gmt(): string
    {
        return (string) get_option('wcvec_last_scan_gmt', '');
    }

    public static function set_last_scan_gmt(string $iso): void
    {
        update_option('wcvec_last_scan_gmt', $iso);
    }

    /** Whether to hook ACF saves (default true) — wired in P6.3. */
    public static function acf_hook_enabled(): bool
    {
        $v = get_option('wcvec_acf_hook_enabled', true);
        return (bool) $v;
    }

    public static function set_auto_sync_enabled(bool $enabled): void
    {
        update_option('wcvec_auto_sync_enabled', (bool) $enabled);
    }

    public static function set_scheduler_cadence(string $cadence): void
    {
        $allowed = ['5min','15min','hourly','twicedaily','daily'];
        if (!in_array($cadence, $allowed, true)) $cadence = '15min';
        update_option('wcvec_scheduler_cadence', $cadence);
    }

    public static function set_max_concurrent_jobs(int $n): void
    {
        $n = max(1, min(10, (int) $n));
        update_option('wcvec_max_concurrent_jobs', $n);
    }

    public static function set_scan_batch_limit(int $n): void
    {
        $n = max(20, min(2000, (int) $n));
        update_option('wcvec_scan_batch_limit', $n);
    }

    public static function set_acf_hook_enabled(bool $enabled): void
    {
        update_option('wcvec_acf_hook_enabled', (bool) $enabled);
    }

    public static function manual_include_variations(): bool
    {
        $v = get_option('wcvec_manual_include_variations', true);
        return (bool) $v;
    }

    public static function set_manual_include_variations(bool $on): void
    {
        update_option('wcvec_manual_include_variations', (bool) $on);
    }

    public static function get_event_log_retention_days(): int {
        $n = (int) get_option('wcvec_event_log_retention_days', 7);
        if ($n < 1) $n = 1; if ($n > 90) $n = 90; return $n;
    }
    public static function set_event_log_retention_days(int $n): void {
        $n = max(1, min(90, (int)$n));
        update_option('wcvec_event_log_retention_days', $n);
    }

    /* ===== Performance ===== */

    /** Batch size for adapter upserts (default 100; clamp 10..500). */
    public static function get_batch_upsert_size(): int
    {
        $n = (int) get_option('wcvec_batch_upsert_size', 100);
        if ($n < 10)  $n = 10;
        if ($n > 500) $n = 500;
        return $n;
    }
    public static function set_batch_upsert_size(int $n): void
    {
        $n = max(10, min(500, (int) $n));
        update_option('wcvec_batch_upsert_size', $n);
    }

    /* ===== Content scope ===== */

    /** Include draft/private products in sync (default false). */
    public static function include_drafts_private(): bool
    {
        return (bool) get_option('wcvec_include_drafts_private', false);
    }
    public static function set_include_drafts_private(bool $on): void
    {
        update_option('wcvec_include_drafts_private', (bool) $on);
    }

    /**
     * Variation strategy: separate|collapse|parent_only (default separate)
     * - separate: parent + each variation indexed individually
     * - collapse: variations not indexed; parent includes rolled-up variation data (P8.2 implements roll-up)
     * - parent_only: variations ignored entirely; parent only (no roll-up)
     */
    public static function get_variation_strategy(): string
    {
        $v = (string) get_option('wcvec_variation_strategy', 'separate');
        $allowed = ['separate', 'collapse', 'parent_only'];
        return in_array($v, $allowed, true) ? $v : 'separate';
    }
    public static function set_variation_strategy(string $strategy): void
    {
        $allowed = ['separate', 'collapse', 'parent_only'];
        if (!in_array($strategy, $allowed, true)) $strategy = 'separate';
        update_option('wcvec_variation_strategy', $strategy);
    }

    /** Already added earlier; setting included here for Advanced UI completeness. */
    public static function set_event_log_retention_days(int $n): void
    {
        $n = max(1, min(90, (int) $n));
        update_option('wcvec_event_log_retention_days', $n);
    }

    /** Guarded: allow manual dimension override in edge cases (default false). */
    public static function allow_dimension_override(): bool
    {
        return (bool) get_option('wcvec_allow_dimension_override', false);
    }
    public static function set_allow_dimension_override(bool $on): void
    {
        update_option('wcvec_allow_dimension_override', (bool) $on);
    }

        /** Max number of variations to inspect when rolling up (default 500). */
    public static function get_rollup_max_variations(): int {
        $n = (int) get_option('wcvec_rollup_max_variations', 500);
        if ($n < 20) $n = 20;
        if ($n > 5000) $n = 5000;
        return $n;
    }
    public static function set_rollup_max_variations(int $n): void {
        $n = max(20, min(5000, (int) $n));
        update_option('wcvec_rollup_max_variations', $n);
    }

    /** Cap for how many distinct values to list per attribute/field (default 20). */
    public static function get_rollup_values_cap(): int {
        $n = (int) get_option('wcvec_rollup_values_cap', 20);
        if ($n < 5) $n = 5;
        if ($n > 200) $n = 200;
        return $n;
    }
    public static function set_rollup_values_cap(int $n): void {
        $n = max(5, min(200, (int) $n));
        update_option('wcvec_rollup_values_cap', $n);
    }
}
