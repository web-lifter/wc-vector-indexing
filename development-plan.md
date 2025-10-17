# Branching, Issues, and CI (once, up front)

**Branching**

* Default branch: `main`
* Working branch: `develop`
* Feature branches: `feature/<phase>-<short-name>` (e.g., `feature/p1-settings`)
* Hotfix: `hotfix/<issue>`; release branch: `release/<version>`

**Issue labels**

* `phase:P1`…`phase:P9`, `type:feat`, `type:bug`, `type:docs`, `type:tests`, `priority:high`, `area:admin-ui`, `area:indexer`, `area:adapters`, `area:acf`, `area:cron`, `area:about`, `good-first-issue`

**Project board**

* Columns: Backlog → In Progress → PR Ready → Review → QA → Done

**CI (GitHub Actions)**

* Jobs: `phpcs`, `phpstan`, `phpunit` (with wp-env or Docker), `build-zip`
* Secret scanning: enabled
* Required checks for merge to `develop`/`main`: all green

**Repo hygiene**

* `CODEOWNERS` (you + reviewers)
* `.editorconfig`, `.gitattributes`
* PR template (checklist + “Acceptance Criteria met?”)
* Issue templates (bug/feature)

---

# Phase P0 — Repo Bootstrap (meta)

**Goals**

* Ensure repo executes coding standards & tests locally and in CI.

**Tasks**

* Configure `composer.json` dev deps: `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpstan/phpstan`, `brain/monkey` (for WP mocks), `dealerdirect/phpcodesniffer-composer-installer`.
* Add `phpcs.xml.dist`, `phpstan.neon`, `phpunit.xml.dist`.
* Set up `wp-env` (or Docker) for local WP + WC.
* Minimal unit test that loads the plugin main file.

**Definition of Done**

* `composer test` runs unit tests
* `composer cs` runs PHPCS; `composer stan` runs PHPStan
* CI green on `develop`

---

Heck yes — let’s kick off **Phase P1** properly. Here’s a crisp, file-by-file task plan you can track as issues/PR checklists.

---

# Phase P1 — Skeleton, Settings, Connections, About

## 0) Prep (repo + composer)

* [ ] Add `paragonie/sodium_compat` to `composer.json` (for sodium fallback).
* [ ] Ensure dev tools from P0 are installed (`composer install`) and CI is green.

---

## 1) Bootstrap & Plugin Wiring

**Files**

* `wc-vector-indexing.php`
* `includes/class-plugin.php`
* `includes/class-admin.php` (menu + page loader)
* `assets/admin.css`, `assets/admin.js`
* `languages/` (load textdomain, stub `.pot` later)

**Tasks**

* [ ] Add plugin header, text domain `wc-vector-indexing`, and `defined('ABSPATH')` guard.
* [ ] In `class-plugin.php`:

  * [ ] Autoload (Composer) & singleton/instance wiring.
  * [ ] Load text domain.
  * [ ] Register activation/deactivation hooks (no DB tables yet).
  * [ ] Boot admin only in `is_admin()`.
* [ ] In `class-admin.php`:

  * [ ] Register top-level menu: **WooCommerce → Vector Indexing** (cap `manage_woocommerce`).
  * [ ] Subpages (tabs): **Connections**, **Fields** (placeholder), **Sync** (placeholder), **Logs** (placeholder), **Advanced** (placeholder), **About**.
  * [ ] Enqueue `admin.css` / `admin.js` **only** on our pages.
* [ ] Add `Settings` link to plugin row (optional polish).

**Definition of Done**

* Visiting **WooCommerce → Vector Indexing** shows the **Connections** and **About** tabs (others can 404 or show “coming soon”).

---

## 2) Secure Options Storage (Keys & Settings)

**Files**

* `includes/class-secure-options.php` (new)
* `includes/class-options.php` (new; typed getters/setters + defaults)

**Option keys (created now)**

* `wcvec_api_openai_key` (encrypted)
* `wcvec_api_pinecone_key` (encrypted)
* `wcvec_pinecone_env` (e.g., `gcp-starter`, `us-east-1-aws`)
* `wcvec_pinecone_project`
* `wcvec_pinecone_index`
* `wcvec_embedding_model` (enum)
* `wcvec_embedding_dimension` (int; stored to keep 1:1 with model)
* `wcvec_openai_vectorstore_id` (string; allowed to be blank for now)
* **About**:

  * `wcvec_about_company_name`
  * `wcvec_about_company_blurb`
  * `wcvec_about_company_logo_url`
  * `wcvec_about_products` (array of items: `title, desc, url, icon`)
  * `wcvec_about_support_url`
  * `wcvec_about_contact_email`

**Tasks**

* [ ] `class-secure-options.php`:

  * [ ] Provide `encrypt($plain)` / `decrypt($cipher)` with:

    * Primary: `sodium_crypto_aead_xchacha20poly1305_ietf_*` using a key derived from WP salts.
    * Fallback: `paragonie/sodium_compat` if native sodium missing.
  * [ ] Key derivation from concatenated WP salts via `hash('sha256', NONCE_SALT.AUTH_KEY...)`.
  * [ ] Constant-time compare for integrity checks.
* [ ] `class-options.php`:

  * [ ] Register defaults.
  * [ ] Centralize `get_*` / `set_*` methods; encrypted getters/setters for API keys.
  * [ ] Sanitize all inputs (urls, emails, enums, ints).
  * [ ] Masking helper to display keys as `sk-****abcd`.

**Definition of Done**

* Saving/retrieving options works.
* Keys are stored encrypted and presented masked in admin.

---

## 3) Connections Page (UI + Settings registration)

**Files**

* `admin/pages/class-admin-page-connections.php`
* `admin/views/connections.php`

**Model support (per your spec)**

* `text-embedding-3-large` → **1536**
* `text-embedding-3-small` → **3072**
* `text-embedding-ada-002` → **1536**

**Tasks**

* [ ] Register settings section(s) using Settings API or custom handlers with nonces:

  * **OpenAI**: API key (password field), Model (select: 3-large/3-small/ada-002), Embedding dimension (read-only auto-fill from model for now; editable later in Advanced).
  * **OpenAI Vector Store**: Vector Store ID (text; optional at this phase).
  * **Pinecone**: API key (password field), Environment, Project, Index name (text fields).
* [ ] On save:

  * [ ] Encrypt and persist API keys.
  * [ ] Store model + **dimension** mapping (1536/3072/1536).
  * [ ] Validate fields (non-empty where required; URLs/emails format checks not needed here).
* [ ] UI polish:

  * [ ] Masked rendering for saved keys with “Change” toggle to edit.
  * [ ] Inline help text & links.
  * [ ] Success/error admin notices.

**Definition of Done**

* Can save all above settings; reload shows masked keys and selected model with correct dimension displayed.

---

## 4) “Validate Connections” (no upserts yet)

**Files**

* `admin/pages/class-admin-page-connections.php` (handlers)
* `assets/admin.js` (AJAX for validate buttons)
* `includes/class-http.php` (optional tiny wrapper using `wp_remote_request`)
* `includes/class-validators.php` (new; shared validation logic)
* `includes/class-nonces.php` (optional helper)

**OpenAI validation (server-side)**

* [ ] Make a minimal **Embeddings** call with static input `"ping"` using selected model (no storage).
* [ ] If 200 and vector length equals stored dimension → **pass**.
* [ ] Else show clear error (HTTP code, message snippet).

**Pinecone validation (server-side)**

* [ ] Hit **Controller API** `/indexes` (requires only API key + environment) to ensure the key is valid.
* [ ] If `wcvec_pinecone_index` is set, try a **describe** call against the index host to confirm it exists.
* [ ] **No dimension check yet** (reserved for P3/Adapters), but capture/print index’s dimension if available.

**Tasks**

* [ ] Add two buttons on the Connections page:

  * **Validate OpenAI**
  * **Validate Pinecone**
* [ ] Buttons trigger admin-ajax or REST requests with nonces.
* [ ] Display result inline (✓ success / ✕ failure with reason).
* [ ] Don’t log secrets; redact tokens in error logs.

**Definition of Done**

* Clicking validate shows success/failure with actionable messages for both services.

---

## 5) About Page (UI, repeater, rendering)

**Files**

* `admin/pages/class-admin-page-about.php`
* `admin/views/about.php`
* `assets/admin.js` (add/remove product rows)

**Tasks**

* [ ] Options form fields:

  * Company name (text), blurb (textarea), logo URL (text), support URL (text), contact email (text).
  * **Products list** repeater: Title, Desc, URL, Icon URL (rows can be added/removed/reordered).
* [ ] Sanitization:

  * `esc_url_raw` for URLs, `sanitize_text_field` for short text, `wp_kses_post` for blurb.
  * For products array: deep sanitize each element; strip empty rows.
* [ ] Rendering:

  * Show logo (if set), company name/blurb.
  * Grid/list of products with title, description, and outbound links (target `_blank`, `rel="noopener"`).
  * Support/contact links if present.

**Definition of Done**

* Admin can add/edit/remove product entries and see them render on the About tab.

---

## 6) Basic REST Endpoint

**Files**

* `includes/rest/class-rest-status.php`

**Endpoint**

* `GET /wp-json/wcvec/v1/status`

**Response (JSON)**

```json
{
  "plugin_version": "x.y.z",
  "wp_version": "6.x",
  "php_version": "8.x",
  "woocommerce_version": "8.x",
  "checks": {
    "sodium_available": true,
    "openai_configured": true,
    "pinecone_configured": true
  }
}
```

**Tasks**

* [ ] Register namespace `wcvec/v1` and route `status` (readable for admins; or `manage_woocommerce` capability check).
* [ ] Implement checks:

  * sodium available (native or compat)
  * Options present for OpenAI/Pinecone (keys exist)
* [ ] Unit test: route returns 200 for admins; 403 for non-privileged.

**Definition of Done**

* Curling the endpoint while logged-in as admin returns the structured status.

---

## 7) Security, Privacy, i18n, UX polish

**Tasks**

* [ ] All admin forms use **nonces** and `current_user_can('manage_woocommerce')`.
* [ ] Keys displayed **masked**; if user submits an empty key field, keep existing value.
* [ ] Ensure error messages do **not** echo secrets.
* [ ] Add i18n wrappers for all strings (`__()`, `_x()`) with domain `wc-vector-indexing`.
* [ ] Accessibility: labels/aria attributes, focus management on AJAX result areas.
* [ ] Add a small **Data Sharing** notice on Connections page clarifying that selected content will be sent to OpenAI/Pinecone (actual selection happens in P2, but set expectations now).

---

## 8) Testing & Verification (P1)

**Manual checks**

* [ ] Save keys & settings; reload page → masked keys; model/dimension correct (3-large=1536, 3-small=3072, ada-002=1536).
* [ ] “Validate OpenAI” returns ✓ with valid key, ✕ with invalid; shows vector length check.
* [ ] “Validate Pinecone” returns ✓ with valid key; ✕ with invalid key or env.
* [ ] About page: add two products; reorder; save; render correctly.
* [ ] REST status returns expected flags.

**Automated (add basic tests now or in P9)**

* [ ] Unit tests for `class-secure-options` (roundtrip encryption).
* [ ] Unit tests for options sanitization (URLs/emails/enums).
* [ ] REST route permission test.

---

## 9) Deliverables (P1)

* [ ] Admin UI for **Connections** and **About**
* [ ] Options & **encryption helpers**
* [ ] **Validate connections** routines (OpenAI embeddings ping; Pinecone controller/index checks)
* [ ] REST `/wcvec/v1/status`

---

## 10) Out-of-scope (defer to later phases)

* No field discovery/preview (P2)
* No embeddings pipeline or adapters/upserts (P3–P5)
* No scheduler/hooks/manual indexing (P6–P7)
* No danger-zone or uninstall cleanup (P8)

---

If you want, I can also generate **stub classes and empty PHP files** with headers for the items above to speed up P1 implementation.

---

# Phase P2 — Field Discovery & ACF (Task Plan)

## A) Files to add/update

**New (core logic)**

* `includes/class-field-discovery.php`
* `includes/class-field-normalizer.php` (flatten values to text/JSON as indexed)
* `includes/class-acf-integration.php` (shims for ACF detection + field/group reads)

**New (admin)**

* `admin/pages/class-admin-page-fields.php`
* `admin/views/fields.php`

**New (AJAX)**

* In `class-admin-page-fields.php` add handlers:

  * `wp_ajax_wcvec_search_products` (search dropdown for sample product)
  * `wp_ajax_wcvec_fields_preview` (build preview for selected product + selections)
  * `wp_ajax_wcvec_list_meta_keys` (optional: fetch popular/custom meta keys)

**Existing updates**

* `includes/class-admin.php`

  * `require_once` for `class-admin-page-fields.php`
  * route **Fields** tab to real page (replacing placeholder)
* `assets/admin.js`

  * Add UI logic for live preview, product search select, toggles, and meta key “add” control
* `assets/admin.css`

  * Styles for field trees, search box, preview panel

---

## B) Option schema & data shapes

**Option:** `wcvec_selected_fields` (array). Persist as:

```php
[
  'core'      => ['title', 'short_description', 'description', 'sku', 'price', 'sale_price', 'stock_status'], // keys from discovery map
  'tax'       => ['product_cat', 'product_tag'], // taxonomy slugs
  'attributes'=> ['pa_color','pa_size'], // attribute tax slugs
  'seo'       => ['yoast_title','yoast_description','rankmath_title','rankmath_description'], // present only if selected
  'meta'      => [
    // meta key -> render mode (text/json); default 'text'
    '_custom_key' => 'text',
    'my_meta'     => 'json'
  ],
  'acf'       => [
    // each entry describes a specific ACF field
    [
      'group_key' => 'group_abc123',
      'field_key' => 'field_def456',
      'name'      => 'materials',
      'label'     => 'Materials',
      'type'      => 'repeater',    // for info in UI; not required at runtime
      'mode'      => 'text',        // 'text' | 'json'
      'for'       => 'product',     // 'product' | 'variation' (when you later support per-target)
    ],
    // …
  ],
  'flags'     => [
    'show_private_meta' => false, // surface `_` keys in UI list
  ],
  'chunking'  => [
    'size'    => 800,  // (optional in P2; wired for P3)
    'overlap' => 100,  // (optional in P2; wired for P3)
  ],
]
```

**Note:** In P2 we only **save & use** selections and preview **normalized output**; we don’t embed yet.

---

## C) Discovery logic (class-field-discovery.php)

### Responsibilities

* Return a **field catalog** describing available sources, for building the selection UI.
* The catalog is **static** from plugin POV (built on request) and **filtered** by environment (plugins active, ACF present, etc.).

### Methods

* `get_core_fields(): array`
  Returns an array of core Woo fields (id, sku, title, short_description, description, price, sale_price, stock_status, type, permalink, images_alt).
* `get_taxonomies(): array`
  Returns product taxonomies: `product_cat`, `product_tag`, and registered product **attribute taxonomies** via `wc_get_attribute_taxonomies()` → `pa_*`.
* `get_seo_fields(): array`
  Detect Yoast (`class_exists('WPSEO_Meta')` / options) and RankMath (function `defined('RANK_MATH_VERSION')`). Include keys:

  * Yoast: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`
  * RankMath: `rank_math_title`, `rank_math_description`
* `get_custom_meta_keys(array $args): array`
  Strategies (choose at least one):

  * Discover a **sample set** of meta keys from the selected sample product (safe and fast).
  * Provide a **manual add** input; optional AJAX to retrieve **top N** meta keys across recent products (limit scans).
* `get_acf_groups_for_product(): array`
  If ACF active: `acf_get_field_groups(['post_type'=>'product'])` and for variations `… 'product_variation'`.
* `get_acf_fields_for_group(string $group_key): array`
  `acf_get_fields($group_key)` returning field label, name, type, key.

**Return shape (catalog)**

```php
[
  'core' => [
    ['key'=>'title','label'=>'Title'],
    ['key'=>'short_description','label'=>'Short Description'],
    // …
  ],
  'tax' => [
    ['slug'=>'product_cat','label'=>'Categories'],
    ['slug'=>'product_tag','label'=>'Tags'],
  ],
  'attributes' => [
    ['slug'=>'pa_color','label'=>'Color'],
    // …
  ],
  'seo' => [
    ['key'=>'yoast_title','label'=>'Yoast SEO Title','meta_key'=>'_yoast_wpseo_title'],
    // …
  ],
  'meta' => [
    ['key'=>'my_meta','label'=>'my_meta','private'=>false],
    // `_private` suppressed unless flags.show_private_meta = true
  ],
  'acf' => [
    [
      'group' => ['key'=>'group_…','title'=>'Product Specs'],
      'fields' => [
        ['key'=>'field_…','name'=>'materials','label'=>'Materials','type'=>'repeater'],
        // …
      ]
    ],
  ],
]
```

---

## D) Normalization rules (class-field-normalizer.php)

### Responsibilities

* Convert selected fields for a given product (ID) into **normalized text or JSON snippets** exactly as they would be embedded.

### Methods

* `build_preview(int $product_id, array $selection): array`
  Returns:

  ```php
  [
    'sections' => [
      ['source'=>'core','label'=>'Title','value'=>'Tee Shirt'],
      ['source'=>'tax','label'=>'Categories','value'=>'Men, Tops'],
      ['source'=>'acf','label'=>'Materials','value'=>'Cotton; Linen'], // or JSON string
      // …
    ],
    'text' => "Title: Tee Shirt\nCategories: Men, Tops\nACF – Materials: Cotton; Linen\n…",
  ]
  ```
* Per-source helpers:

  * **Core**: Use `wc_get_product($id)` to get name/descriptions/SKU/price/stock; strip HTML from descriptions; format price with store currency symbol (or plain numeric string — choose plain string for consistency).
  * **Taxonomies / Attributes**: Get term names (`wp_get_post_terms`); join with `, `.
  * **SEO**: Read post meta keys for Yoast/RankMath; normalize to plain text.
  * **Custom Meta**: `get_post_meta($id, $key, true)`; if array and mode = `json` → `wp_json_encode($val)`, else flatten to readable text (`implode(', ', $val)`).
  * **ACF**: Prefer `get_field($field_key, $id)` if available; apply type-driven normalizers:

    * `text/textarea/wysiwyg` → strip tags → text
    * `true_false` → `true`/`false`
    * `number/range` → cast to string
    * `select/radio/checkbox` → labels joined by `, `
    * `date/date_time/time` → `Y-m-d` / ISO-like string
    * `taxonomy` → term names (or slugs if we add a toggle later)
    * `post_object/relationship` → post titles (optionally SKU if product)
    * `repeater/flexible_content/group` →

      * mode=`text`: flatten to `Label: value` semicolon-delimited lines
      * mode=`json`: compact JSON like `{"items":[…]}`
    * `image/gallery/file` → alt text/caption (optionally URL; for now keep alt/caption)

**Sanitization**

* Final text should be plain UTF-8; strip tags; trim; collapse whitespace.
* JSON values: ensure `json_encode` safe; fallback to text if encoding fails.

---

## E) Admin UI — Fields tab (class-admin-page-fields.php + view)

### Layout (tabs → Fields)

* Left column: **Field selection tree**

  * Sections: Core, Taxonomies, Attributes, SEO, Custom Meta, ACF
  * Each item has a checkbox; **ACF & Meta** items have a **mode** toggle (Text / JSON) (radio or select)
  * “Show private meta (`_*`)” toggle (off by default)
  * Search box filtering items by label/key
  * “Select essential” preset (Title, SKU, Short/Long Description, Categories, Attributes)
* Right column: **Live Preview**

  * Sample product selector (searchable)
  * “Refresh Preview” button (also auto-refresh on change with debounce)
  * Read-only `<pre>` / code block showing the exact **normalized text** (and an expandable “Sections” table for debugging)
* Bottom bar: **Save Selection** button

### Behavior

* On save:

  * Persist `wcvec_selected_fields` (structure above)
  * Show admin notice “Field selection saved”
* On change:

  * JS debounces calls to `wcvec_fields_preview` (POST product_id + current selection map)
  * Preview updates within ~250–500ms
* ACF handling:

  * If ACF not active, show an inline hint: “Install/activate ACF to enable ACF fields.”

---

## F) AJAX endpoints

### 1) `wp_ajax_wcvec_search_products`

* **Input:** `q` (search term), `page` (optional), `limit` (default 20)
* **Output:** `[{"id":123,"text":"Tee Shirt (SKU123)"}]`
* **Implementation:** `WP_Query` on `product` post_type, search title/SKU (join on `_sku` meta if q is alphanumeric). Keep it fast and paginated.

### 2) `wp_ajax_wcvec_fields_preview`

* **Input:** `product_id`, `selection` (JSON from UI), security nonce
* **Output:** `{ ok:true, text:"…", sections:[…] }`
  `ok:false` + `message` on errors (bad nonce, no caps, missing product)
* **Implementation:**

  * Capability: `manage_woocommerce`
  * Use `Field_Normalizer::build_preview($product_id, $selection)`
  * Return data; no persistence here

### 3) `wp_ajax_wcvec_list_meta_keys` (optional)

* **Input:** `q` (prefix filter), `include_private` (bool)
* **Output:** `["my_meta","_private_meta",…]`
* **Implementation:**

  * Fast path: scan selected sample product meta keys
  * Optional: scan top N recent products (limit 50) to build a deduped list
  * Always enforce `include_private=false` unless flag is set

**Security**

* All endpoints require `current_user_can('manage_woocommerce')`
* Nonces: `wcvec_fields_preview`, `wcvec_search_products`, `wcvec_list_meta`

---

## G) UX details

* **Search box** filters field lists by **label or key** (client-side)
* Sticky column headers in long lists
* Tooltip/info icons explaining “Text vs JSON” modes and private meta
* ACF groups collapsed by default; click to expand fields
* “Show only selected” toggle (helps review)
* Accessibility: labels for checkboxes, ARIA for collapsible groups, `aria-live` on preview panel

---

## H) Performance considerations

* Avoid loading full product objects repeatedly in preview; fetch only needed fields/terms
* Cache taxonomy/attribute term labels while previewing a product
* Debounce preview requests (250–500ms)
* Server-side: short timeouts; bail early if product not found
* Paginate product search; cap meta-key scanning

---

## I) Edge cases & error handling

* ACF inactive: section disabled with message
* Missing ACF field (deleted/renamed): show a small ⚠ next to field and exclude from preview; health checks later will surface this too
* Private meta hidden by default; when “show private meta” toggled on, visually mark `_` keys
* If SEO plugin not active, disable those checkboxes with hints

---

## J) Testing & Acceptance

**Manual TESTS**

1. **Core & Taxonomies**

   * Select Title, Short Desc, Description, Categories, Attributes → preview updates deterministically
2. **SEO**

   * With Yoast/RankMath active, select SEO fields → preview shows titles/descriptions when set
3. **Custom Meta**

   * Add key `my_meta` in list; choose **Text**; verify preview flattening
   * Switch to **JSON**; verify JSON output
4. **ACF**

   * With ACF active, choose text, repeater, relationship, taxonomy fields; toggle **Text/JSON**; verify flattening
5. **Private Meta**

   * Toggle **Show private meta** → `_secret` appears; ensure default excluded unless specifically selected
6. **Product Search**

   * Search/find product by title or SKU; preview updates
7. **Save Selection**

   * Save; reload page → previously selected items and modes persist

**Automated (add now or in P9)**

* Unit tests for `Field_Normalizer` against fixtures:

  * Core fields produce expected strings (HTML stripped, numbers stringified)
  * Taxonomies join logic
  * Custom meta text/JSON modes
  * ACF type matrix: text, repeater (text/json), relationship, taxonomy
* Smoke test for `wcvec_fields_preview` (permission/nonce gates)

**Acceptance Criteria**

* Selecting/deselecting fields updates preview deterministically ✅
* ACF fields display and flatten per chosen mode ✅
* `_`-prefixed meta excluded by default unless explicitly selected ✅

---

## K) Issue breakdown (suggested PRs)

1. **P2.1 – Discovery scaffolding**

   * `class-field-discovery.php`, `class-acf-integration.php` with catalog methods
   * Basic unit tests for catalog shapes

2. **P2.2 – Normalizer**

   * `class-field-normalizer.php` + fixtures/tests for normalization

3. **P2.3 – Fields UI (view + controller)**

   * `class-admin-page-fields.php`, `fields.php`, basic selection save

4. **P2.4 – Live Preview + AJAX**

   * AJAX endpoints, JS wiring, debounce, product search

5. **P2.5 – Polish & guards**

   * Private meta toggle, SEO plugin hints, ACF inactive hint, accessibility, CSS polish

---

Awesome — here’s an engineering-ready task plan for **Phase P3 — Chunker, SHA, Embeddings (Model Matrix)**. It’s broken into files, API contracts, validation rules, test coverage, and suggested PRs.

---

# Phase P3 — Chunker, SHA, Embeddings

## A) Files to add/update

**New (core)**

* `includes/class-chunker.php`
* `includes/class-embeddings.php`
* `includes/class-fingerprint.php` (tiny SHA utilities)

**Existing updates**

* `includes/class-options.php`

  * ensure defaults for `chunking.size` (800), `chunking.overlap` (100)
  * ensure `wcvec_embedding_model` + `wcvec_embedding_dimension` (locked to mapping, but overridable later)
* `admin/pages/*` (no UI required in P3; optional “Test Embedding” button can be added under Connections/Advanced if you want)
* `composer.json` (no new deps)

**Optional (DX)**

* `includes/cli/class-cli.php` → `wp wcvec chunk --product=ID` and `wp wcvec embed --text="..."`

---

## B) Model matrix (locked dims)

Follow our project spec:

| Model                    | Dim  |
| ------------------------ | ---- |
| `text-embedding-3-large` | 1536 |
| `text-embedding-3-small` | 3072 |
| `text-embedding-ada-002` | 1536 |

* Add a constant map in **Embeddings** and in **Options** for validation.
* On save of model, **auto-set** the dimension in options (as done in P1).
* **Dimension locking**: `Embeddings::validate_settings()` should check that current `dimension` equals model’s expected dim unless an override flag (for Advanced later) is enabled. If mismatch → throw a clear `WP_Error` with remediation.

---

## C) API contracts (methods & shapes)

### 1) Chunker

**File:** `class-chunker.php`

```php
namespace WCVec;

final class Chunker {
  public static function chunk_text(
    string $text,
    int $target_tokens = 800,
    int $overlap_tokens = 100,
    float $avg_chars_per_token = 4.0
  ): array;
}
```

**Behavior**

* Normalize newlines to `\n`, trim ends, collapse >2 blank lines to exactly 2.
* Use **hierarchical splitting**: paragraph (`\n\n+`) → sentence (regex on punctuation) → word.
* Use a **character budget** ≈ `target_tokens * avg_chars_per_token`; pack sentences until budget reached; if a sentence alone exceeds budget, split by words.
* **Overlap**: append the last `overlap_tokens * avg_chars_per_token` chars (rounded on sentence/word boundary) from previous chunk to the next chunk’s start.
* Deterministic: identical input → identical chunk boundaries.

**Return**

```php
[
  ['index'=>0, 'text'=>'...', 'chars'=>N, 'approx_tokens'=>M],
  ...
]
```

### 2) Fingerprint

**File:** `class-fingerprint.php`

```php
namespace WCVec;

final class Fingerprint {
  public static function sha_product(string $normalized_text, array $selection, array $chunking, string $model, int $dimension): string;
  public static function sha_chunk(string $chunk_text, string $product_sha, int $chunk_index): string;
}
```

**Rules**

* **Product SHA**: `sha256` over a canonical JSON blob:
  `{ "text": <normalized_text>, "selection": <sorted selection map>, "chunking": {"size":S,"overlap":O}, "model":MODEL, "dimension":DIM, "version": WC_VEC_VERSION }`

  * Sort arrays deterministically: `core/tax/attributes/seo` sorted; `meta` sorted by key; `acf` sorted by `field_key|name`.
* **Chunk SHA**: `sha256(product_sha . "\n" . chunk_index . "\n" . chunk_text)`.
* Output hex strings (lowercase). These will later populate `wp_wcvec_objects.sha` and/or per-chunk metadata.

### 3) Embeddings

**File:** `class-embeddings.php`

```php
namespace WCVec;

use WP_Error;

final class Embeddings {
  public static function model_map(): array; // ['text-embedding-3-large'=>1536, ...]
  public static function validate_settings(): true|WP_Error; // model/dimension/key checks
  public static function embed_texts(array $texts): array|WP_Error; // returns [[float,...], ...]
}
```

**Behavior**

* Reads key/model/dimension from `Options`.
* Calls OpenAI `/v1/embeddings` with `{model, input: texts}` (batch up to 100 per call; for P3, a single-call path is fine).
* Checks HTTP 200 and that each `data[i].embedding` length equals **stored** `dimension`. If not, return `WP_Error` with message “Embedding length X != expected Y. Check model/dimension.”
* Never log secrets; strip Authorization from any error context.

---

## D) Validation & failure modes

* **Chunker**: if input empty → return `[]`.
* **Fingerprints**: if normalized text empty → SHA still computed (of empty text + config), ensuring deterministic “no-content” hash.
* **Embeddings**:

  * Missing key → `WP_Error('wcvec_oai_key_missing', 'OpenAI API key is not set')`
  * Invalid model → `WP_Error('wcvec_oai_model_invalid', 'Unknown embedding model ...')`
  * Dimension mismatch (unless override) → `WP_Error('wcvec_dim_mismatch', 'Stored dimension 1536 does not match model text-embedding-3-small → 3072')`
  * HTTP non-200 → wrap code + short message body (“…”) in error
  * Null/short vectors → error with clear suggestion (“Verify the selected model in Connections”)

---

## E) Unit tests (high-value)

**1) Chunker determinism**

* Fixture string includes paragraphs, short & long sentences, emojis/accents, long words.
* Assert:

  * Given size=800, overlap=100 → same `count(chunks)` and same `chunks[i]['text']` across runs.
  * Overlap boundary: last N chars of chunk[i] appear at start of chunk[i+1] (with sentence/word-aware trimming).

**2) Fingerprints**

* Two runs with identical inputs → same product SHA; changing `selection.core` order without changing contents → same SHA (due to sorting).
* Changing `chunking.size` or `model` → different SHA.

**3) Embeddings (size check & erroring)**

* Mock OpenAI via `pre_http_request` filter to return a known JSON with a vector of length = expected dimension; assert success.
* Mock with wrong vector length; assert `WP_Error('wcvec_dim_mismatch_runtime', ...)` (or similar).
* Mock with 401; assert error code and helpful message.

---

## F) Developer UX (optional but recommended)

**WP-CLI (scaffold now; extend later)**

* `wp wcvec chunk --product=123 [--size=800] [--overlap=100]`

  * Outputs count and a preview of first/last chunk sizes.
* `wp wcvec embed --text="..."`

  * Returns length of the resulting vector (or error).

---

## G) Integration Points (prep for P4)

* Expose a single orchestrator method (to be used in P4 jobs):

  ```php
  // not implemented now, but define the contract for next phase
  function build_product_payload(int $product_id, array $selection): array {
    // 1) Field_Normalizer::build_preview → $text
    // 2) Fingerprint::sha_product(...)
    // 3) Chunker::chunk_text($text, size, overlap)
    // 4) For each chunk: sha_chunk, Embeddings::embed_texts([...]) → vectors
    // return array of {chunk_index, text, sha, vector, meta}
  }
  ```
* Confirm `Options::get_selected_fields()['chunking']` is the single source of truth for size/overlap.

---

## H) Security & Performance

* **Security:** Never log API keys; in `Embeddings`, on error include only HTTP code + short body snippet.
* **Perf:** In P3, single-call embedding is ok; in P4, we’ll add batching/retries/backoff.
* **i18n:** User-facing messages run through `__()`.

---

## I) Suggested PR breakdown

1. **P3.1 – Chunker + Fingerprint + tests**

   * `class-chunker.php`, `class-fingerprint.php`
   * `tests/test-chunker.php`, `tests/test-fingerprint.php`

2. **P3.2 – Embeddings wrapper + tests**

   * `class-embeddings.php`
   * `tests/test-embeddings.php` with `pre_http_request` mocks

3. **(Optional) P3.3 – CLI tooling**

   * `includes/cli/class-cli.php`, register commands if `WP_CLI` defined

---

## J) Acceptance checklist

* ✅ Same input → same chunks + SHAs across runs
* ✅ Embedding call returns vector size equal to **stored** dimension
* ✅ Invalid model/dimension settings are blocked with a clear message

---

# Phase P4 — Adapters (Pinecone + OpenAI Vector Store)

## A) New/updated files

**Interfaces & Orchestrator**

* `includes/adapters/class-adapter-interface.php`
* `includes/class-indexer.php` (lightweight orchestrator used by “Sample embed & upsert”)

**Adapters**

* `includes/adapters/class-pinecone-adapter.php`
* `includes/adapters/class-openai-vectorstore-adapter.php`

**Admin (Connections “Sample embed & upsert”)**

* `admin/pages/class-admin-page-connections.php` (handlers)
* `assets/admin.js` (AJAX hooks)

**Tests**

* `tests/test-pinecone-adapter.php`
* `tests/test-openai-vectorstore-adapter.php`

---

## B) VectorStoreInterface (contract)

```php
namespace WCVec\Adapters;

interface VectorStoreInterface {
  /** Validate connectivity and dimension/compat (no writes). */
  public function validate(): true|\WP_Error;

  /**
   * Upsert vectors for a single product’s chunk payloads.
   * @param array $chunks Each: [
   *   'id' => 'site-1:product-123:chunk-0',      // stable
   *   'values' => [float,...],                   // embedding
   *   'metadata' => [                            // see metadata map below
   *     'product_id'=>123, 'sku'=>'A-1', 'url'=>'...', 'updated_at'=>'ISO',
   *     'fingerprint'=>'sha256:...', 'site_id'=>1, 'fields'=>['title','desc']
   *   ]
   * ]
   */
  public function upsert(array $chunks): array|\WP_Error; // returns ['upserted'=>N]

  /** Delete all vectors for a product (prefer metadata filter, fallback to IDs). */
  public function delete_by_product(int $product_id, int $site_id): array|\WP_Error; // ['deleted'=>N]

  /** Delete explicit IDs (used for re-chunking). */
  public function delete_by_ids(array $ids): array|\WP_Error; // ['deleted'=>N]
}
```

---

## C) Shared conventions

**Vector IDs (stable, cross-store)**

```
site-{BLOG_ID}:product-{PRODUCT_ID}:chunk-{INDEX}
```

(<= 120 chars; safe for Pinecone limits.)

**Metadata (identical in both stores)**

```json
{
  "site_id": 1,
  "product_id": 123,
  "sku": "SKU-001",
  "url": "https://store.tld/product/slug",
  "updated_at": "2025-10-12T03:45:00Z",
  "fingerprint": "sha256:…",        // product SHA or chunk SHA; store product-level SHA in all chunks
  "fields": ["title","description","pa_size","product_cat"]
}
```

**Batching**

* Default **100 vectors** per upsert (`Options` → advanced to tune later).

**Retry policy**

* Transient: `429`, `>=500`, network timeouts → retry with **exponential backoff** + jitter: 0.25s, 1.5s, 5s (x3).
* Permanent: `400–428`, `430–499` → no retry; surface error.

---

## D) Pinecone adapter

**File:** `includes/adapters/class-pinecone-adapter.php`

### Config pulls

* API key: `wcvec_api_pinecone_key`
* Env: `wcvec_pinecone_env` (e.g. `us-east-1-aws`)
* Project: `wcvec_pinecone_project`
* Index name: `wcvec_pinecone_index`
* Expected dimension: `Options::get_dimension()`

### Endpoints

* **Control**: `https://api.pinecone.io/indexes` (already used in validation)
* **Data plane** (per index host):
  `https://{indexName}-{project}.svc.{env}.pinecone.io/vectors/upsert`
  `…/vectors/delete`
  `…/describe_index_stats` (read dimension; or use control-plane describe if available)

### Methods

* `validate()`

  * Resolve data-plane host from settings.
  * `GET …/describe_index_stats` → ensure `dimension == Options::get_dimension()` (fail with helpful message if not).
* `upsert(array $chunks)`

  * Build `vectors: [{id, values, metadata}]` body; up to 100 per request.
  * Perform batched POST with retries.
  * Return `['upserted' => count]`.
* `delete_by_product($product_id, $site_id)`

  * Prefer **filter delete**: `{"deleteAll": false, "filter": {"product_id":123,"site_id":1}}`.
  * Return `['deleted'=>null]` (Pinecone doesn’t always return counts); log best-effort.
* `delete_by_ids(array $ids)`

  * POST `{"ids":["…","…"]}`.

**Edge handling**

* Dimension mismatch → **block writes** with `WP_Error('wcvec_pine_dim_mismatch', 'Index dimension …')`.
* Redact keys in error messages; include request ID if Pinecone returns it.

---

## E) OpenAI Vector Store adapter

**File:** `includes/adapters/class-openai-vectorstore-adapter.php`

> We’ll keep the wrapper generic around the official **Vector Stores** API: create/reuse a store, then upsert/delete vectors with metadata. Exact endpoint names can be encapsulated behind our `Http` wrapper so we can adjust if OpenAI evolves the surface.

### Config pulls

* API key: `wcvec_api_openai_key`
* Store ID: `wcvec_openai_vectorstore_id` (can be blank)
* Model & dimension: `Options::get_model()`, `Options::get_dimension()`

### Endpoints (abstracted)

* Create vector store → save ID to `wcvec_openai_vectorstore_id`
* Upsert vectors to store: send `id`, `embedding` (values), `metadata`
* Delete vectors: by metadata filter if available, else by IDs

### Methods

* `ensure_store()` (private)

  * If no store ID: **create** (name suggestion: `wcvec_{site_id}_{blogname}_{Ymd}`), save ID.
  * Verify store exists (GET) and is writable.
* `validate()`

  * Ensure key present; ensure store resolved; **dimension check is indirect** (we already validate embedding size on P3; adapter accepts any vector length since we compute embeddings ourselves).
* `upsert(array $chunks)`

  * Ensure store.
  * Batch up to 100: payload shape `[{"id","values","metadata"}]`.
  * Return `['upserted'=>N]`.
* `delete_by_product($product_id, $site_id)`

  * If API supports metadata filter: use it; else compute IDs with our stable scheme and call delete-by-ids (best effort).
* `delete_by_ids(array $ids)`

**Edge handling**

* Store creation 4xx → permanent failure with short snippet.
* Upsert delete 5xx/429 → retries; on partial success, return count processed + an aggregated error message.

---

## F) Indexer (sample upsert / dry-run)

**File:** `includes/class-indexer.php`

Purpose: power the Connections page buttons without full job system yet.

```php
namespace WCVec;

use WCVec\Adapters\VectorStoreInterface;
use WCVec\Adapters\Pinecone_Adapter;
use WCVec\Adapters\OpenAI_VectorStore_Adapter;

final class Indexer {

  /** Build chunk payloads (id, values, metadata) for a product. */
  public static function build_payloads(int $product_id, array $selection): array|\WP_Error {
    $preview = Field_Normalizer::build_preview($product_id, $selection);
    if (empty($preview['ok'])) { return new \WP_Error('wcvec_preview_failed', $preview['message'] ?? ''); }

    $text  = (string) $preview['text'];
    $model = Options::get_model();
    $dim   = Options::get_dimension();
    $site  = get_current_blog_id();

    $chunking = $selection['chunking'] ?? ['size'=>800,'overlap'=>100];
    $product_sha = Fingerprint::sha_product($text, $selection, $chunking, $model, $dim);

    $chunks = Chunker::chunk_text($text, (int)$chunking['size'], (int)$chunking['overlap'], 4.0);
    $ids    = [];
    $inputs = [];
    foreach ($chunks as $c) {
      $ids[]    = self::vector_id($site, $product_id, $c['index']);
      $inputs[] = $c['text'];
    }

    $vectors = Embeddings::embed_texts($inputs);
    if (is_wp_error($vectors)) { return $vectors; }

    $sku = (function_exists('wc_get_product') && ($p = wc_get_product($product_id))) ? (string) $p->get_sku() : '';
    $url = get_permalink($product_id);
    $now = current_time('mysql', true); // UTC

    $field_list = array_values(array_unique(array_merge(
      $selection['core'] ?? [], $selection['tax'] ?? [], $selection['attributes'] ?? [],
      $selection['seo'] ?? [], array_keys($selection['meta'] ?? [])
    )));

    $payloads = [];
    foreach ($chunks as $i => $c) {
      $payloads[] = [
        'id'     => $ids[$i],
        'values' => $vectors[$i],
        'metadata' => [
          'site_id'     => (int) $site,
          'product_id'  => (int) $product_id,
          'sku'         => $sku,
          'url'         => (string) $url,
          'updated_at'  => gmdate('c', strtotime($now)),
          'fingerprint' => 'sha256:' . $product_sha,
          'fields'      => $field_list,
        ],
      ];
    }

    return $payloads;
  }

  public static function vector_id(int $site_id, int $product_id, int $chunk_index): string {
    return sprintf('site-%d:product-%d:chunk-%d', $site_id, $product_id, $chunk_index);
  }

  /** Route to specific adapters for the sample upsert/delete. */
  public static function get_adapter(string $target): VectorStoreInterface|\WP_Error {
    switch ($target) {
      case 'pinecone': return new \WCVec\Adapters\Pinecone_Adapter();
      case 'openai':   return new \WCVec\Adapters\OpenAI_VectorStore_Adapter();
      default:         return new \WP_Error('wcvec_unknown_target', 'Unknown vector store target.');
    }
  }
}
```

---

## G) Connections page: “Sample embed & upsert”

**UI (existing page)**

* Add two buttons:

  * **Sample embed & upsert → Pinecone**
  * **Sample embed & upsert → OpenAI Vector Store**
* Small product picker (ID) for the test; or use first published product.

**AJAX handlers**

* `action=wcvec_sample_upsert` (target=`pinecone|openai`, product_id)

  1. Build payloads via `Indexer::build_payloads($product_id, Options::get_selected_fields())`
  2. `$adapter = Indexer::get_adapter($target); $adapter->validate();`
  3. `$adapter->upsert($payloads);`
  4. Return counts and first/last vector IDs as proof.

* `action=wcvec_sample_delete` (target, product_id)

  * `$adapter->delete_by_product($product_id, get_current_blog_id())`

**Status rendering**

* Inline status card with ✓/✕ and a short message (HTTP code + snippet on failure).

---

## H) Tests (mocked HTTP)

**1) Pinecone**

* `test_validate_dimension_mismatch_blocks_writes()`

  * Mock `…/describe_index_stats` to return wrong `dimension` → assert `WP_Error('wcvec_pine_dim_mismatch', …)`.
* `test_upsert_batches_and_sends_metadata()`

  * Mock `/vectors/upsert` to capture request; assert `id`, `values` length, and presence of metadata keys.
* `test_delete_by_product_uses_filter()`

  * Mock `/vectors/delete` body contains `filter.product_id` + `filter.site_id`.

**2) OpenAI Vector Store**

* `test_ensure_store_creates_and_caches_id()`

  * First call: mock POST create → returns `{id:"vs_123"}`; assert option set.
  * Second call: no POST; mock GET returns OK.
* `test_upsert_succeeds_and_counts_vectors()`

  * Mock upsert endpoint; assert batched payload count matches.
* `test_delete_by_ids_fallback_when_filter_unavailable()`

  * Simulate 400 on filter delete; ensure adapter falls back to delete-by-ids with our stable IDs.

> Use `pre_http_request` to intercept calls; never hit the network.

---

## I) Error messages (clear + short)

* Pinecone dimension mismatch:

  * “The Pinecone index ‘X’ has dimension 1024 but your model (text-embedding-3-small) needs 3072. Update the index or change the model in Connections.”
* OpenAI store missing:

  * “Couldn’t create or access an OpenAI Vector Store. Check your API key and permissions.”
* Upsert failures:

  * “Upsert failed (HTTP 429). Retrying… (attempt 2/3)” → final: “Upsert failed after retries. Last error: …”

---

## J) Acceptance checklist

* ✅ **Dry-run “Sample embed & upsert”** succeeds for both targets (visible in Connections)
* ✅ **Pinecone dimension** verified and blocks writes if mismatched
* ✅ **Upsert & delete** work end-to-end for 2–3 sample products (with mocked tests + manual smoke if you have live keys)

---

## K) Suggested PR sequence

1. **P4.1 – Interface + Indexer + wiring**
2. **P4.2 – Pinecone adapter + tests**
3. **P4.3 – OpenAI Vector Store adapter + tests**
4. **P4.4 – Connections UI buttons + AJAX + happy-path manual smoke**

---

# Phase P5 — Indexing Pipeline & Storage

## A) New/updated files

**New**

* `includes/class-storage.php` — DB accessors for `wp_wcvec_objects`
* `includes/jobs/class-job-index-product.php` — Action Scheduler job to (re)index a product
* `includes/jobs/class-job-delete-product.php` — Action Scheduler job to purge a product from targets
* `includes/class-lifecycle.php` — wires WP/WC hooks → enqueue jobs

**Updates**

* `includes/class-indexer.php` — add `sync_product()` (calls build → delta → adapters → record)
* `includes/class-options.php` — add Advanced config (batch size, targets)
* `admin/pages/class-admin-page-advanced.php` & `admin/views/advanced.php` — expose batch size, targets
* `includes/class-plugin.php` — activation: create table; include new classes
* `includes/adapters/*` — optionally read `Options::get_batch_size()` (override default 100)

---

## B) Data model (DB schema)

**Table:** `{$wpdb->prefix}wcvec_objects` (engine InnoDB, utf8mb4)

| Column           | Type                              | Notes                                      |
| ---------------- | --------------------------------- | ------------------------------------------ |
| `id`             | bigint unsigned PK AUTO_INCREMENT |                                            |
| `site_id`        | bigint unsigned                   | `get_current_blog_id()`                    |
| `product_id`     | bigint unsigned                   |                                            |
| `target`         | varchar(16)                       | `pinecone` | `openai`                      |
| `chunk_index`    | int unsigned                      | 0-based                                    |
| `vector_id`      | varchar(191)                      | stable ID (`site-#:product-#:chunk-#`)     |
| `product_sha`    | char(64)                          | product-level SHA256                       |
| `chunk_sha`      | char(64)                          | chunk-level SHA256                         |
| `model`          | varchar(64)                       | e.g. `text-embedding-3-small`              |
| `dimension`      | int unsigned                      | 1536/3072                                  |
| `remote_id`      | varchar(191)                      | if store returns its own id (optional)     |
| `status`         | varchar(16)                       | `synced` | `pending` | `error` | `deleted` |
| `error_code`     | varchar(64)                       | last error code (nullable)                 |
| `error_msg`      | text                              | last error message (nullable)              |
| `last_synced_at` | datetime                          | UTC                                        |
| `created_at`     | datetime                          | UTC                                        |
| `updated_at`     | datetime                          | UTC ON UPDATE                              |

**Indexes**

* `UNIQUE (target, vector_id)` (fast idempotency)
* `UNIQUE (target, product_id, chunk_index)` (chunk address)
* `INDEX (product_id, target)`
* `INDEX (product_sha)`
* `INDEX (status)`

**Activation** (`class-plugin.php` → `on_activate`):

* `dbDelta()` for table create
* store schema version option `wcvec_schema_version = 1`

---

## C) Options (Advanced)

Add to `Options` defaults + getters/setters:

* `wcvec_targets_enabled` (array) — `['pinecone','openai']` default both ON
* `wcvec_batch_upsert_size` (int) — default **100**, min 10, max 500
* (future) `wcvec_max_concurrency` (int) — default 1 (left dormant unless needed)

**Advanced page UI**

* Checkboxes: Pinecone / OpenAI Vector Store
* Number: “Batch upsert size” (10–500)
* Help text: costs/limits note

---

## D) Lifecycle mapping (enqueue jobs)

**File:** `includes/class-lifecycle.php`

* Hooks:

  * `save_post_product`, `woocommerce_update_product` → `enqueue_index($product_id)`
  * `trashed_post`, `before_delete_post` (product/variation) → `enqueue_delete($product_id)`
  * `transition_post_status` where `publish`→`draft|trash` → `enqueue_delete`
* Variations:

  * If variation updated, enqueue parent **and** variation (configurable later)
* Dedup:

  * Use unique Action Scheduler args so the same product isn’t queued twice within a short window
* Bulk tools (coming next phase): still enqueue through same jobs

---

## E) Jobs (Action Scheduler)

**Index job:** `class-job-index-product.php`

* Args: `{ product_id: int, force: bool=false }`
* Steps:

  1. Load `selection = Options::get_selected_fields()` + targets
  2. `Indexer::sync_product($product_id, $selection, $targets, $force)`
  3. Bubble errors to AS log; mark per-chunk rows accordingly

**Delete job:** `class-job-delete-product.php`

* Args: `{ product_id: int }`
* Steps:

  1. For each enabled target → adapter->validate() → `delete_by_product(product_id, site_id)`
  2. In DB: either delete rows (`DELETE FROM …`) or set `status='deleted'` (choose **delete** to keep table lean)
  3. Record a “purged” count in AS log

Scheduling helpers:

* Requeue with backoff if transient adapter error occurs (use AS `as_schedule_single_action( time()+N, … )`)

---

## F) Indexer orchestration (delta & storage)

**File:** `includes/class-indexer.php`
Add:

```php
public static function sync_product(int $product_id, array $selection, array $targets, bool $force=false): array|WP_Error
```

**Algorithm**

1. `build_payloads($product_id, $selection)` → returns:

   * `payloads[] = {id, values, metadata{…}}`
   * (augment in this phase to also return) `product_sha`, `chunk_shas[index]`
2. For each **target**:

   * Load existing rows for `(target, product_id)` from `Storage`
   * If **not force** and `existing.product_sha == new.product_sha` and `count(chunks)` stable:

     * **Short-circuit**: set `last_synced_at` for all rows and exit (no API calls)
   * Build **delta**:

     * `to_upsert` = all chunks whose `chunk_sha` differs or doesn’t exist
     * `to_delete` = rows with indexes not present anymore (e.g., chunk count shrank)
     * If **model/dimension** in DB differ from current Options → treat as **full rebuild** (delete all, upsert all)
3. Execute:

   * If `to_delete` non-empty → adapter->delete_by_ids( ids[] )
   * If `to_upsert` non-empty → adapter->upsert( payloads for those indices, batched)
4. Storage updates (per target):

   * Upsert/replace rows: set `product_sha`, `chunk_sha`, `model`, `dimension`, `status='synced'`, `last_synced_at=now`, `updated_at=now`, `vector_id`, `target`, `site_id`, `product_id`, `chunk_index`
   * Remove rows that were deleted remotely
   * On per-batch error: mark affected chunks `status='error'`, store `error_code/error_msg`
5. Return summary:

   ```php
   [
     'product_id'=>…, 'target'=>'pinecone',
     'upserted'=>N, 'deleted'=>M,
     'skipped'=>K, 'chunks_total'=>T,
     'product_sha'=>'…'
   ]
   ```

**Batch size:** use `Options::get_batch_upsert_size()`; pass to adapters if they expose setter, else sub-batch in `Indexer` before calling adapter.

---

## G) Storage layer

**File:** `includes/class-storage.php`

Methods:

* `get_chunks(int $product_id, string $target): array`
  → keyed by `chunk_index`, rows include `chunk_sha`, `vector_id`, `model`, `dimension`
* `replace_chunk(array $row): void`
  → INSERT … ON DUPLICATE KEY UPDATE (vector_id, sha, status, timestamps, model/dim)
* `delete_chunks_by_indexes(int $product_id, string $target, array $indexes): int`
* `delete_all_for_product(int $product_id): int` (all targets)
* `mark_error(int $product_id, string $target, array $indexes, string $code, string $msg): void`
* `touch_all(int $product_id, string $target): void` (updates `last_synced_at`)

All timestamps in UTC (`gmdate('Y-m-d H:i:s')`).

---

## H) Admin Advanced page (batch & targets)

**Fields**

* Enabled targets (checkboxes)
* Batch size (10–500)
* Danger zone: “Purge all vectors for this site” (already planned; wire to jobs in next phase)

Validation & save via `Options`.

---

## I) CLI (nice-to-have)

Extend `wp wcvec`:

* `wp wcvec sync --product=ID [--force] [--targets=openai,pinecone]`
  → calls `Indexer::sync_product()`; prints summary row
* `wp wcvec purge --product=ID [--targets=…]`
  → enqueues delete job or calls adapters directly with confirmation flag

---

## J) Tests

**1) Storage CRUD**

* Create rows; ensure unique constraints hold
* Replace (update) a chunk; delete by indexes; purge product

**2) Indexer delta logic (use a **fake adapter** stub)**

* Case A: First index → upsert all chunks; storage rows match; statuses `synced`
* Case B: No changes (same product_sha) → no adapter calls; `touch_all` only
* Case C: One chunk changed (chunk_sha delta) → only that index upserted
* Case D: Chunk count decreased → stale indexes deleted
* Case E: Model/dimension changed → full rebuild (delete all, upsert all)

**3) Lifecycle jobs**

* Saving a product enqueues an index job (assert AS action scheduled)
* Trashing a product enqueues a delete job
* Delete job removes DB rows and (with mocked adapter) calls `delete_by_product`

**4) Adapter integration smoke (optional)**

* With `pre_http_request` mocks, run `sync_product()` end-to-end and assert counts

---

## K) Error handling & idempotency

* Any adapter `WP_Error`:

  * Write `status='error'`, `error_code`, `error_msg` on affected chunk rows
  * Return aggregated `WP_Error` to job → AS will log; we can reschedule if transient
* Idempotent vector IDs (`site-:product-:chunk-`) + unique keys avoid duplicates
* If a batch upsert partially fails:

  * Mark those indices as error; succeed others; rerun job later will pick up errored indices (since sha mismatch persists)

---

## L) PR sequence

1. **P5.1 – Schema & Storage**

   * Table, activation, `class-storage.php`, unit tests

2. **P5.2 – Indexer sync (delta) + Options (batch/targets)**

   * `Indexer::sync_product()`, options, tests with fake adapter

3. **P5.3 – Jobs & Lifecycle**

   * Action Scheduler jobs, lifecycle hooks, enqueue logic, tests

4. **P5.4 – Advanced page UI**

   * Batch size & targets UI; save/validate

*(Optional P5.5 – CLI sync/purge commands)*

---

## M) Acceptance checklist (maps to your criteria)

* ✅ Editing product fields triggers index job; if `product_sha` unchanged → **no remote writes**
* ✅ When content changes, **only changed chunk indices** are re-embedded/upserted
* ✅ Deleting/unpublishing a product **purges** vectors for that `product_id` in all enabled targets
* ✅ `wp_wcvec_objects` shows `status`, `product_sha`, `chunk_sha`, `last_synced_at` accurate per chunk/target

---

# Phase P6 — Scheduling & Event Hooks

## A) New/updated files

**New**

* `includes/class-scheduler.php` — recurring scanner + enqueue logic
* `admin/pages/class-admin-page-sync.php` + `admin/views/sync.php` — Scheduler controls + metrics

**Updates**

* `includes/class-plugin.php` — schedule on activate; ensure recurring action exists
* `includes/class-lifecycle.php` — add ACF hook; minor polish
* `includes/class-options.php` — new getters/setters for scheduler settings
* (optional) `includes/class-storage.php` — helper queries for scan/diff

---

## B) Options (with sensible defaults)

Add to `Options`:

* `wcvec_auto_sync_enabled` (bool) — **true**
* `wcvec_scheduler_cadence` (enum) — **`15min`** (allowed: `5min`, `15min`, `hourly`, `twicedaily`, `daily`)
* `wcvec_max_concurrent_jobs` (int) — **3** (cap for in-flight index jobs)
* `wcvec_scan_batch_limit` (int) — **200** (max products examined per scan)
* `wcvec_last_scan_gmt` (string) — ISO 8601 of last completed scan
* `wcvec_acf_hook_enabled` (bool) — **true**

Validation rules:

* concurrency 1..10; batch 20..2000; cadence one of the whitelisted schedules.

---

## C) Recurring scanner (Action Scheduler + WP-Cron fallback)

**Action names**

* `wcvec/scan_changes` (group `wcvec`)

**On activate (and on every `plugins_loaded`)**

* If `wcvec_auto_sync_enabled` and no action scheduled, schedule recurring per cadence.
* If Action Scheduler unavailable, register a WP-Cron event using the closest interval (`wp_schedule_event`).

**Cadence mapping**

* `5min` → `as_schedule_recurring_action( time()+60, 5*60, … )`
* `15min` → `+ 15*60`
* `hourly` → `+ 3600`
* etc.

**Scan algorithm (high level)**

1. Determine **window**: `$since = get_option('wcvec_last_scan_gmt')` (fallback: now - 2 days).
2. Find **candidate products** changed since `$since` (see D).
3. Compute **enqueue quota** = `wcvec_max_concurrent_jobs - (in_progress index jobs)`. If ≤ 0 → exit.
4. Enqueue up to `min(quota, wcvec_scan_batch_limit, candidates_count)` using `Job_Index_Product::enqueue($pid)`.
5. Also:

   * include products with **no rows** in `wp_wcvec_objects` but `publish` status (first-time sync).
   * include products whose latest chunk rows are **error**.
6. Update `wcvec_last_scan_gmt = now()`.

**Counting in-progress/pending**

* Use Action Scheduler if present:

  * `as_get_scheduled_actions([ 'group' => 'wcvec', 'hook' => Job_Index_Product::ACTION, 'status' => 'in-progress' ])`
  * same for `pending`
* WP-Cron fallback: track our own counters in a transient (best-effort).

---

## D) Change detection (SQL helpers)

Add to `Storage`:

1. `get_product_ids_needing_initial_sync($limit)`

   * Products `publish` with **no** rows in `wcvec_objects`.

2. `get_product_ids_modified_since($since_gmt, $limit)`

   * Join `wp_posts p` (type in `('product','product_variation')`, status `publish`) to a subquery of `wcvec_objects` grouped by `product_id`:

     * `HAVING MAX(GREATEST(o.updated_at, o.last_synced_at)) IS NULL OR MAX(GREATEST(o.updated_at, o.last_synced_at)) < p.post_modified_gmt`
   * Map variations to parent product ID (return parent if non-zero).

3. `get_product_ids_with_errors($limit)`

   * Select distinct `product_id` where `status='error'`.

Return arrays of product IDs de-duplicated and ordered by `post_modified_gmt DESC`.

---

## E) ACF hooks

In `Lifecycle` (or a tiny new class):

* Add:

  ```php
  add_action('acf/save_post', [$this, 'on_acf_save_post'], 20);
  ```
* Handler:

  * If `Options::acf_hook_enabled()` false → return
  * Accept `$post_id` that could be:

    * Numeric post ID → if type `product` or `product_variation`, enqueue `Job_Index_Product::enqueue($post_id)`
    * If variation, also enqueue parent (delay 30s)
  * Avoid recursion: ACF sometimes calls `save_post` again — rely on dedup in jobs (we already dedup by args).

*Note:* ACF typically updates `post_modified_gmt` too, but this hook ensures we catch edge cases (AJAX, programmatic saves).

---

## F) Concurrency caps & queue discipline

* Respect `wcvec_max_concurrent_jobs`:

  * Scanner computes **quota** using current `in-progress` jobs count.
  * Only enqueues up to quota (plus a small cushion of +10% pending allowed, optional).
* Optional (feature flag later): enforce global cap via filter:

  * `add_filter('action_scheduler_queue_runner_concurrent_batches', fn() => 1)` if needed on slow hosts.
* Batch size used by Indexer remains from `Options::get_batch_upsert_size()` (P5.2).

---

## G) Sync tab (admin)

**Controller:** `class-admin-page-sync.php`
**View:** `admin/views/sync.php`

**Controls**

* Toggle: **Enable automatic sync** (on/off)
* Cadence select: 5 min / 15 min / Hourly / Twice daily / Daily
* Concurrency cap (1–10)
* Scan batch limit (20–2000)
* ACF hook toggle
* Buttons:

  * **Run scan now** (fires `wcvec/scan_changes` immediately)
  * **Requeue all errors** (enqueue index job for all `status='error'` products)
  * **Pause all** (disables auto sync + displays a warning bar)

**Metrics panel** (read-only)

* **Queue:** pending / in-progress / failed (Action Scheduler stats) for our hook(s)
* **Throughput (last 24h):** completed index jobs; failed index jobs
* **Backlog estimate:** count of candidates from D (capped by `scan_batch_limit` in view)
* **Last scan:** ISO time; **Next scan:** timestamp or “paused”
* **Per-target heartbeat:** adapter `validate()` quick check (✓/✕)

Implement a small metrics getter:

* Uses Action Scheduler APIs when available:

  * `as_get_scheduled_actions([... 'status' => 'pending' ])` (count only)
  * `status='failed'`, `status='complete'`, with `date` window
* WP-Cron fallback: show “Limited metrics (WP-Cron)” and only compute what we can (e.g., backlog & last scan).

---

## H) Scheduler class

**`class-scheduler.php` responsibilities**

* `ensure_recurring()` — schedules/unschedules based on options
* `run_scan()` — implements the algorithm in C
* `get_metrics()` — returns counts for Sync tab:

  ```php
  [
    'pending'=>N,'in_progress'=>N,'failed'=>N,'completed_24h'=>N,'failed_24h'=>N,
    'backlog_estimate'=>N,'last_scan'=>iso,'next_scan'=>iso_or_null,'targets'=>['pinecone'=>true/false,'openai'=>true/false]
  ]
  ```
* `run_now()` — enqueue a one-off scan (`as_enqueue_async_action` or schedule single in 5s)
* `requeue_errors()` — finds products with errors and enqueues force reindex

**Hook wiring**

* `add_action('wcvec/scan_changes', [Scheduler::class, 'run_scan'])`
* Admin actions (POST) from Sync tab to call `run_now()`, `requeue_errors()`, `ensure_recurring()` after settings save.

---

## I) WP-Cron fallback

* Register a custom schedule(s) as needed (5min) via `cron_schedules` filter.
* If AS is missing:

  * Schedule `wcvec_scan_changes_event` with `wp_schedule_event`.
  * Mirror handler: `add_action('wcvec_scan_changes_event', [Scheduler::class, 'run_scan'])`.
  * Metrics: show limited info and a dismissible admin notice recommending Action Scheduler (WooCommerce).

---

## J) Tests

**Scheduler (unit/integration with factory data)**

1. **Initial sync candidates:** create `N` published products with no rows → `run_scan()` enqueues up to `scan_batch_limit` (check scheduled actions or directly call handler to assert `Storage`).
2. **Modified since:** change `post_modified_gmt` on a subset → only those get enqueued.
3. **Errors requeue:** mark rows for a product as `status='error'` → `requeue_errors()` enqueues that product (no duplicates).
4. **Quota:** set `wcvec_max_concurrent_jobs=1` and simulate an in-progress job → `enqueue_quota=0` → no new enqueues.
5. **ACF hook:** trigger `do_action('acf/save_post', $product_id)` → ensure index job scheduled (deduped).

**Admin Sync tab**

* Save settings persists toggles/cadence/limits correctly.
* “Run scan now” calls `run_scan()`.
* Metrics render with realistic counts (mock Action Scheduler queries with filters).

---

## K) PR sequence

1. **P6.1 – Options & Scheduler skeleton**

   * Options + `class-scheduler.php` with `ensure_recurring()` + hook registration
2. **P6.2 – Scan algorithm & storage helpers**

   * Candidate queries + `run_scan()` + unit tests
3. **P6.3 – ACF hook + polish lifecycle**

   * `acf/save_post` handler + tests
4. **P6.4 – Sync tab UI + metrics + actions**

   * Admin page, metrics, “Run now”, “Requeue errors”, settings save
5. **P6.5 – WP-Cron fallback & guardrails**

   * Custom intervals, limited metrics, admin notice
6. **P6.6 – QA & docs**

   * Help tab on Sync page explaining cadence, quotas, ACF behavior

---

## L) Acceptance checklist (maps to your criteria)

* ✅ Editing products or ACF fields **automatically schedules** index jobs (deduped)
* ✅ Every `15min` (configurable), the **scanner** diffs recent changes and **enqueues work** within concurrency caps
* ✅ Sync tab shows **pending/in-progress/failed/completed(24h)**, **backlog estimate**, and **last/next scan**
* ✅ Works with **Action Scheduler**; gracefully degrades to **WP-Cron** with clear messaging

---

# Phase P7 — Manual Indexing, Logs, Health

## A) New / updated files

**New**

* `admin/pages/class-admin-page-health.php`
* `admin/views/health.php`
* `includes/class-events.php` (lightweight event logger)
* (Optional) `assets/health.css`, `assets/health.js`

**Updated**

* `includes/class-admin.php` (register **Health** tab)
* `admin/pages/class-admin-page-logs.php` + `admin/views/logs.php` (filters: action/outcome; link to health)
* `includes/class-plugin.php` (require new files)
* `includes/class-lifecycle.php` (emit events)
* `includes/jobs/class-job-index-product.php` & `class-job-delete-product.php` (emit events)
* `includes/class-indexer.php` & adapters (emit per-operation events)
* `includes/class-options.php` (toggle event logging retention)
* **Products list hooks**: new file `admin/class-products-actions.php` (bulk & row actions)

---

## B) P7.1 — Manual controls on Products list

**Goals**

* Bulk actions: **Index to Vector Stores**, **Remove from Vector Stores**
* Row actions: **Index now**, **Remove from indexes**
* Safe, nonce-protected, capability-checked; enqueue jobs (no blocking).

**Tasks**

1. Add `admin/class-products-actions.php`

   * Hooks:

     * `bulk_actions-edit-product` → register two bulk actions.
     * `handle_bulk_actions-edit-product` → schedule jobs, return admin notice (`add_query_arg`).
     * `post_row_actions` → append row links with nonces.
   * Behavior:

     * **Index** → `Jobs\Job_Index_Product::enqueue($id, false)`.
     * **Remove** → `Jobs\Job_Delete_Product::enqueue($id)`.
     * For **variable** parents, also enqueue their **variations** if “index variations separately” flag is ON (read from Options).
     * Cap: `manage_woocommerce`.
2. UX polish

   * After action, redirect with `notice=wcvec_bulk_indexed|wcvec_bulk_removed&count=N`.
   * Products screen: show admin notice with counts.

**Acceptance**

* Selecting 1..N products → jobs enqueued; clear notice shows count.
* Row links present, secure (nonce), and schedule correctly.

---

## C) P7.2 — Rich logs (actions + outcomes)

> We already have a **Logs** page that reads `wp_wcvec_objects`. To capture **operations** (upsert, delete, validate), add a lightweight event logger.

**Schema (no new DB table required)**
Use a **flat file** appender in `wp-content/uploads/wcvec/logs-YYYYMMDD.jsonl` (one JSON per line) **or** (if you prefer DB) a small table `wp_wcvec_events`. Choose one:

**Option A — JSONL files (default)**

* File rotation daily; max size cap; retention N days (option).
* Write-only, append; avoids query overhead.
* CSV export: stream from most recent files; filters applied at read time.

**Option B — DB table (if you want queryable logs)**

* `wp_wcvec_events`: `id, ts, product_id NULL, target NULL, action, outcome, message, details JSON, duration_ms, request_id, chunk_count`.
* Index on `(ts)`, `(product_id)`, `(action)`, `(outcome)`.

*(Pick A for speed; pick B if you need admin filters over long history. Below assumes **A**; swapping to B only changes the reader/writer.)*

**Event emission points**

* Validators: OpenAI embed “ping”, Pinecone `/indexes`, Vector Store check.
* Indexer: start/end, each adapter **upsert** (batch), **delete_by_ids**, **delete_by_product**.
* Jobs: enqueue, handler success/error, retry scheduling.
* Lifecycle/ACF: trigger reason.

**Logs page enhancements**

* New filters: **Action** (`upsert|delete|validate_openai|validate_pinecone|sample_upsert|job|scan`), **Outcome** (`success|error`).
* Columns: time (UTC), product, target, **action**, **outcome**, message (expand), duration, count.
* CSV export: respects filters; includes raw `details` JSON column.
* “Jump to Health” link when validation-type errors are present.

**Acceptance**

* After manual index/remove or validators: events appear within seconds; CSV exports reasonable volume; message is actionable (HTTP code snippet, dimension mismatch note, etc.).

---

## E) P7.4 — Tests

**Manual controls**

* Simulate bulk handler with a set of IDs → verify `Job_Index_Product::enqueue()` / `Job_Delete_Product::enqueue()` called right number of times (use filters to capture).
* Row action URLs contain valid nonces and capabilities enforced.

**Logs**

* Emit fake events via `Events::log()`; verify reader returns filtered results; CSV stream includes headers; large messages truncated in table view but intact in CSV.

---

## F) Options & retention (small)

* `wcvec_event_log_retention_days` (default 7; clamp 1–90).
* `wcvec_manual_include_variations` (bool; default **true**).
* Add setters/getters in `Options`; add to **Advanced** page if you want to expose now.

---

## G) PR sequence

1. **P7.1 – Products actions**

   * Bulk/row actions + notices + tests

2. **P7.2 – Event logger + Logs UI upgrade**

   * `class-events.php`, emit hooks, Logs page filters/action/outcome, CSV export update + tests

3. **P7.4 – Docs & screenshots**

   * README “Admin Guide” section (Manual actions, Logs, Health), troubleshooting matrix

---

## H) Copy cues (user-facing)

* Bulk success: “Queued indexing for **%d** products. Processing in background.”
* Bulk remove: “Queued deletion from vector stores for **%d** products.”
* Health dimension mismatch:
  “Your Pinecone index is **%d** dims but the selected model expects **%d**. Update your Pinecone index or change the embedding model in **Connections → OpenAI**.”

---

## I) Done =

* ✅ Manual **bulk + row** controls working
* ✅ Logs show **action + outcome** with CSV export

---

# Phase P8 — Advanced, Danger Zone, Uninstall

## A) New / updated files

**New**

* `admin/pages/class-admin-page-advanced.php` — Advanced + Danger Zone UI
* `admin/views/advanced.php` — form + confirmations
* `includes/jobs/class-job-purge-site.php` — async “Delete all vectors for this site”

**Updated**

* `includes/class-options.php` — advanced flags + uninstall toggle
* `includes/class-admin.php` — register Advanced tab
* `includes/class-scheduler.php` — stop/resume on settings change (already OK)
* `includes/class-lifecycle.php` — respect drafts/private & variation strategy
* `includes/class-indexer.php` / `class-field-normalizer.php` — honor variation strategy
* `includes/class-storage.php` — helper for site-wide cleanup
* `includes/adapters/class-adapter-interface.php` — add `purge_site(int $site_id)`
* `includes/adapters/class-pinecone-adapter.php` — implement `purge_site()` via metadata filter
* `includes/adapters/class-openai-vectorstore-adapter.php` — implement `purge_site()` (filter / fallback loop)
* `includes/class-events.php` — log purge start/end
* `uninstall.php` — full cleanup + optional remote purge
* (optional) `assets/admin.js` — Danger Zone confirm UX

---

## B) Advanced settings (persist + affect runtime)

### 1) Options (getters/setters)

Add to `includes/class-options.php`:

* `wcvec_batch_upsert_size` (already in P5.2; expose UI here)
* `wcvec_max_concurrent_jobs` (already in P6.4; mirror here for convenience)
* `wcvec_include_drafts_private` (bool; default **false**)
* `wcvec_variation_strategy` (enum: `separate` | `collapse` | `parent_only`; default **separate**)
* `wcvec_manual_include_variations` (already added in P7.1; surface here)
* `wcvec_event_log_retention_days` (already added in P7.2; surface here)
* `wcvec_uninstall_remote_purge` (bool; default **false**)
* (guarded) `wcvec_allow_dimension_override` (bool; default **false**) — only shows when Pinecone dimension doesn’t match; warns loudly

### 2) Advanced UI (admin)

**Page**: WooCommerce → Vector Indexing → **Advanced**

Sections:

* **Performance**

  * Max concurrent index jobs (1–10)
  * Batch upsert size (10–500)
* **Content scope**

  * Include **draft/private** products (checkbox)
  * Variation strategy (radio):

    * Separate chunks per variation (default)
    * Collapse variations into parent (aggregate variant attrs into parent text)
    * Parent only (ignore variations)
  * Manual actions: “Include variations” (checkbox)
* **Diagnostics & logs**

  * Event log retention (days: 1–90)
* **Compatibility (guarded)**

  * “Allow manual dimension override” (checkbox + scary warning)

    > If enabled, let user set Pinecone index dimension when model differs; we still block embeddings when invalid.
* **Save** button (nonce + capability)

**Save handler effects**

* Update options
* `Scheduler::ensure_recurring()` (if concurrency/cadence toggled elsewhere, no conflict)
* Show success notice

### 3) Runtime behavior changes

* **Lifecycle** (`includes/class-lifecycle.php`):

  * If `include_drafts_private` is **true**, do **not** skip `draft`/`private` on save/ACF hooks; otherwise keep current publish-only behavior.
* **Scan helpers** (`Storage::get_product_ids_*`):

  * Respect `include_drafts_private`: widen `post_status IN ('publish','draft','private')` when true.
* **Variation strategy**:

  * `separate`: current behavior (index parent + each variation).
  * `collapse`:

    * Skip scheduling for `product_variation` in lifecycle/scan.
    * When indexing a **parent product**, `Field_Normalizer` aggregates key attributes of published variations into the parent’s text block (e.g., “Variations: Size=S,M,L; Color=Red/Blue” plus ACF per-variation summaries when feasible).
  * `parent_only`:

    * Skip variation scheduling entirely; index parent only, no aggregation.
  * Add `Options::get_variation_strategy()` and switch logic in `Lifecycle::on_save_variation()` + `Scheduler` + `Indexer::build_payloads_full()` (pass a flag into normalizer to include/exclude variation roll-up text).

---

## C) Danger Zone — Delete all vectors for this site

### 1) UI (in Advanced page, bottom)

* Card with red border “**Danger Zone**”
* Button: **Delete all vectors for this site**
* **Triple confirmation**:

  1. Checkbox “I understand this is destructive.”
  2. Require typing `DELETE` into an input.
  3. A second confirm modal (“This will remove all vectors for site ID X from Pinecone and OpenAI Vector Store. Proceed?”)
* Nonce + capability `manage_woocommerce`

### 2) Server action

* Posts to `admin-post.php?action=wcvec_purge_site` (nonce required)
* Enqueues **async job** `wcvec/purge_site` (group `wcvec`) handled by `class-job-purge-site.php`
* Redirects back with notice “Purge job queued”.
  Optionally expose a progress badge (see below).

### 3) Job implementation (`includes/jobs/class-job-purge-site.php`)

* Resolve `$site_id = get_current_blog_id()`
* Emit `Events::log('purge_site','info','Starting site purge', ['details'=>['site_id'=>$site_id]])`
* For each enabled adapter:

  * Call `$adapter->purge_site($site_id)`

    * If returns `WP_Error` → log `'error'` and continue (do not abort)
    * On success → capture deleted count if available; log `'success'`
* Local cleanup:

  * `Storage::delete_all_for_site($site_id)` (truncate rows for site)
  * Delete **event log files** under `wp-content/uploads/wcvec/` (we can keep non-site-segregated; acceptable to delete all)
* Emit `Events::log('purge_site','success','Completed site purge', ['count'=>N])`

**Optional progress**: write a transient `wcvec_purge_progress` with `{status, target, deleted, started_at}` so the Advanced page can show a live status. (Nice-to-have.)

### 4) Adapter interface & implementations

**Interface** (`adapters/class-adapter-interface.php`):

* Add:

  ```php
  /**
   * Purge all vectors for a site.
   * @return array{deleted:int}|WP_Error
   */
  public function purge_site(int $site_id);
  ```

**Pinecone adapter**:

* Use delete with metadata filter:

  ```json
  {"filter":{"site_id":{"$eq": <site_id> }}}
  ```
* Loop if API requires pagination; return total `deleted` if provided; else best-effort count or `null`.

**OpenAI Vector Store adapter**:

* If the Vector Store supports `delete` with filter on metadata `site_id`, use it.
* If not: list items in pages where `metadata.site_id == site_id` (if listing supports filter or client-side filter), collect IDs, delete in batches.
* Return `{deleted:N}` best-effort.

> If the OpenAI store API lacks server-side filtering, we document the operation may take longer and is best run during low-traffic windows.

---

## D) Uninstall — full cleanup

**File:** `uninstall.php`

Steps:

1. **Guard**: run only when `defined('WP_UNINSTALL_PLUGIN')`.
2. **Options to delete** (complete list):

   * All `wcvec_*` options:

     * keys and secrets (`wcvec_api_openai_key`, `wcvec_api_pinecone_key`, …)
     * connection settings (`wcvec_pinecone_env`, `wcvec_pinecone_project`, `wcvec_pinecone_index`, `wcvec_openai_vectorstore_id`)
     * model/dimension (`wcvec_embedding_model`, `wcvec_embedding_dimension`)
     * selection map (`wcvec_selected_fields`)
     * scheduler (`wcvec_auto_sync_enabled`, `wcvec_scheduler_cadence`, `wcvec_max_concurrent_jobs`, `wcvec_scan_batch_limit`, `wcvec_last_scan_gmt`, `wcvec_acf_hook_enabled`)
     * advanced (`wcvec_batch_upsert_size`, `wcvec_include_drafts_private`, `wcvec_variation_strategy`, `wcvec_manual_include_variations`, `wcvec_event_log_retention_days`, `wcvec_allow_dimension_override`, `wcvec_uninstall_remote_purge`)
     * about page content (all `wcvec_about_*`)
     * admin state/transients used by the plugin
3. **DB tables**:

   * Drop `wp_wcvec_objects` (use `$wpdb->prefix` - aware).
     If multisite, drop **each** site’s table when the plugin is network-uninstalled (iterate blogs).
4. **Schedules**:

   * `Scheduler::unschedule_all()` (Action Scheduler & WP-Cron)
   * Also unschedule all **index/delete** pending jobs in group `wcvec` (use `as_unschedule_all_actions` when available).
5. **Files**:

   * Remove `wp-content/uploads/wcvec/` directory recursively (events).
6. **Optional remote purge**:

   * If `wcvec_uninstall_remote_purge` is true: run a lightweight **single-shot purge** per site:

     * Instantiate adapters; call `purge_site($site_id)` (best-effort; ignore failures; avoid long timeouts).
     * Document that for large stores you should run the Danger Zone purge before uninstall to guarantee deletion.
7. **Multisite considerations**:

   * On network uninstall, loop all blogs and run steps 2–6 per site (switch_to_blog).

---

## E) Tests

* **Advanced options persist**

  * Save via controller → getters return expected values; invalid inputs are clamped (batch size, retention days, enums).
* **Drafts/private respected**

  * With option **off**, save a draft → no job queued; **on**, same action queues index job.
* **Variation strategy**

  * `separate`: both parent & variation enqueued by lifecycle;
  * `parent_only`: variation enqueues are suppressed;
  * `collapse`: variation enqueues suppressed; parent’s preview includes aggregated variation attributes (unit test the normalizer output string contains variation terms).
* **Purge job**

  * Enqueue action creates an Action Scheduler/WP-Cron event; handler calls `purge_site()` on adapters (mock adapters) and clears `Storage` rows (assert zero rows remain).
* **Uninstall**

  * After running `uninstall.php` in a test context, assert:

    * table gone,
    * options removed,
    * schedules unscheduled (no next scheduled scan),
    * uploads dir removed (create a dummy log file first).

---

## F) PR sequence

1. **P8.1 – Advanced options & UI**

   * Options + Advanced admin page + save handler
   * Lifecycle/Storage tweaks for drafts/private
   * Variation strategy scaffolding (hooked points + normalizer flag)
2. **P8.2 – Variation strategy implementation**

   * Normalizer aggregation (`collapse`) + tests
   * Suppress variation jobs for `collapse`/`parent_only`
3. **P8.3 – Danger Zone purge**

   * Job + adapter `purge_site()` + UI + events
4. **P8.4 – Uninstall**

   * `uninstall.php` + multisite handling + tests
5. **P8.5 – Docs**

   * README: Advanced settings, Danger Zone workflow, Uninstall notes & caveats

---

## G) Acceptance checklist (maps to your criteria)

* ✅ **Advanced settings** save and **change behavior** (concurrency/batch; drafts/private; variation strategy)
* ✅ Clicking **Delete all vectors for this site** runs an async job that **removes vectors from both stores** (scoped by `site_id`) and clears local state
* ✅ Running **Uninstall** cleans **all local data** (options, tables, schedules, logs), with an **optional** best-effort remote purge.

---

## Test Matrix (high level)

* **WP**: 6.2 / 6.3 / latest
* **PHP**: 8.1 / 8.2 / 8.3
* **WooCommerce**: 8.x latest minor
* **Plugins**: ACF active/inactive; Yoast/RankMath active/inactive
* **Data sizes**: small (≤100 products), medium (~5k), synthetic large (IDs only to test scheduler & batching)
* **Models**: 3-small (3072), 3-large (1536), ada-002 (1536)
* **Targets**: Pinecone only, OpenAI Vector Store only, both

---

## Risk Register & Mitigations

* **Dimension mismatches** → Block save; validate on every adapter call; health check warnings.
* **Rate limits/costs** → Batching + backoff; defaults favor 3-small for cost/perf; chunk size configurable.
* **ACF field drift** (group/field renamed) → Health check flags stale mappings; selection UI shows missing fields.
* **Mass variations** → Variation collapse option in Advanced; batch size caps.
* **Sensitive meta leakage** → Default exclude `_` meta; prominent notice; per-field opt-in only.

---

## Release Flow

1. Merge P9 PRs into `develop`; create `release/<version>` branch.
2. Bump version, update changelog, smoke test.
3. Merge to `main` with a signed tag `vX.Y.Z`.
4. GitHub Action attaches ZIP to Release; publish release notes.
5. Create `vNext` milestone & seed issues from backlog.