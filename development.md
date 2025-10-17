# 1) Goal & Scope

**Goal:** A WooCommerce plugin that indexes product data into **Pinecone** and **OpenAI Vector Store**, using **OpenAI embeddings**, with:

* Settings for API keys & model/index choices
* Field selection (core, taxonomies, attributes, **custom fields & ACF**)
* Auto-sync (scheduler) and manual indexing actions
* Logs, health checks, and an **About** page to promote your company & products

**In scope (MVP):**

* Settings UI (OpenAI, Pinecone, Vector Store, model, scheduler)
* Field selection UI (core + taxonomies + attributes + custom meta + **ACF fields**)
* Background auto-sync (Action Scheduler / WP-Cron)
* Manual indexing (bulk + row actions on Products)
* Pinecone + OpenAI Vector Store adapters
* Logs, status/health
* **About page** (company info, other plugins/software)

**Out of scope (MVP):**

* Frontend search/chat
* Public query/retrieval endpoints
* Multilingual enrichment
* Non-product post types

---

# 2) Target Platform & Assumptions

* **WordPress** ≥ 6.2, **PHP** ≥ 8.1, **WooCommerce** ≥ 8.x
* **Pinecone:** user supplies API key, environment/project, index name
* **OpenAI:** Embeddings + **OpenAI Vector Store** enabled on key
* Stores up to ~100k products (we batch, retry, rate-limit)

---

# 3) Supported Embedding Models (per your spec)

Selector in Settings → Connections:

| Model                      | Dimensions (used for index compatibility) |
| -------------------------- | ----------------------------------------: |
| **text-embedding-3-large** |                                  **1536** |
| **text-embedding-3-small** |                                  **3072** |
| **text-embedding-ada-002** |                                  **1536** |

**Notes & guardrails**

* We will **persist the selected model + dimension** and require Pinecone index dimension to **match exactly**.
* “Advanced” option to **override dimension** (for migration/compat), with warnings; we’ll block save if Pinecone dimension mismatches.
* Defaults: **3-small (3072)** for better recall on long product text.

---

# 4) ACF & Custom Field Support (first-class)

**Discovery**

* Enumerate:

  * Core Woo fields (ID, SKU, name, short/long descriptions, prices, stock, etc.)
  * Taxonomies (cats, tags, product attributes)
  * **Custom meta** via `get_post_meta()` (searchable; exclude `_`-prefixed by default)
  * **ACF field groups** targeting `product` / `product_variation`

    * Use ACF APIs to list groups & fields, resolving **display values** (not raw `_meta`).

**Selection UI (Fields tab)**

* Sections: Core, Taxonomies/Attributes, SEO (Yoast/RankMath if present), **Custom Meta**, **ACF**
* ACF: group → field tree with type badges; checkboxes per field
* Per-field render mode for complex types: **“as text”** (flattened) or **“as JSON”**
* Search across labels/names; “Select essential” preset
* **Live Preview** (choose sample product) to show exactly what will be embedded

**Normalization**

* Text/WYSIWYG → plain text (HTML stripped by default)
* Select/Checkbox/Radio → labels joined with commas
* Number/Boolean/Date/Time → stringified/ISO text
* Taxonomy fields → term names (optionally slugs/IDs)
* Relationship/Post Object → titles (optionally SKUs for product refs)
* Repeater/Flexible → flattened lines (text) or compact JSON
* Image/Gallery/File → alt text/captions (optionally URLs)
* Same rules apply to **non-ACF custom meta** when selected

**Reindex triggers for ACF**

* Hook **`acf/save_post`** to enqueue reindex for products/variations, with SHA change detection to avoid unnecessary embeddings.

---

# 5) Architecture Overview

**Admin UI**

* Connections (keys, model, Pinecone/OpenAI store, tests)
* Fields (selection + preview + chunking options)
* Sync (auto-sync toggle, cadence, “Index All”, stats)
* Logs (paginated table + CSV export)
* Advanced (concurrency, batch sizes, variation strategy, danger zone)
* **About** (company info & links to your other plugins/software)

**Indexer Engine**

* Collect → Transform → Chunk → Embed → Upsert → Record
* Content fingerprint (SHA) to skip unchanged
* Partial reindex on changes; deletion on unpublish/trash

**Background Processing**

* Prefer **Action Scheduler**; WP-Cron fallback
* Batching, retries, backoff, rate-limit awareness

**Manual Triggers**

* Bulk & row actions on Products
* “Index Now” button in Settings
* Optional WP-CLI commands

**Adapters**

```
VectorStoreInterface
  -> PineconeAdapter
  -> OpenAIVectorStoreAdapter
```

---

# 6) Data Model & Storage

**Options (encrypted where possible)**

* `wcvec_api_openai_key`
* `wcvec_api_pinecone_key`, `wcvec_pinecone_env`, `wcvec_pinecone_project`, `wcvec_pinecone_index`
* `wcvec_openai_vectorstore_id` (or name → stored ID)
* `wcvec_embedding_model` (enum) + `wcvec_embedding_dimension` (int)
* `wcvec_selected_fields` (JSON mapping, including ACF field IDs/modes)
* `wcvec_chunking` (size, overlap)
* `wcvec_scheduler_cadence` (e.g., 15m)
* `wcvec_flags` (include drafts/private, index variations separately, etc.)
* **About page content options** (company name, blurb, links list)

**Custom tables**

* `wp_wcvec_objects`
  `id, product_id, variation_id, target(pinecone|openai), remote_id, sha, last_synced_at, status, error_msg`

(No local vector storage in MVP.)

---

# 7) What We Index (Field Mapping)

* Core: ID, SKU, name, short/long description, pricing, stock, type
* Taxonomies: categories, tags, product attributes (global & custom)
* Media: image alt text/captions (optional)
* SEO: Yoast/RankMath titles/descriptions (optional)
* **Custom meta & ACF fields** (per selection + normalization rules)

**Variation strategy (configurable)**

* Default: index **parent** + **each variation** (attributes included)
* Option: collapse variations into parent (for very large catalogs)

---

# 8) Indexing Pipeline

1. **Collect** selected fields (Core/Tax/Attributes/Custom/ACF)
2. **Transform** (normalize, flatten, label sections)
3. **Chunk** to ~800 tokens (default) with 100 overlap (configurable)
4. **Embed** via selected model (uses stored dimension)
5. **Upsert**

   * Pinecone: `id = product-{id}-chunk-{n}`, `values`=embedding, `metadata` (product_id, sku, url, fields, updated_at, fingerprint, site/blog id)
   * OpenAI Vector Store: add with same metadata
6. **Record** in `wp_wcvec_objects` (vector IDs, hashes)
7. **Update**: compare SHA; re-embed only changed chunks; delete stale ones
8. **Delete/Unpublish**: remove all vectors with `product_id` metadata filters
9. **Taxonomy/attribute rename**: enqueue lightweight reindex for affected products

**Batching defaults**

* Fetch products in pages of 100; embed chunks in batches of up to 100
* Pinecone/OpenAI upserts in batches of 100; configurable in Advanced

---

# 9) Triggers & Scheduling

**Real-time hooks**

* `save_post_product`, `woocommerce_update_product`, `woocommerce_trash_product`, `woocommerce_delete_product`
* **`acf/save_post`** for ACF changes on products/variations

**Scheduled sync**

* Action Scheduler recurring job (default **every 15 minutes**)
* Scans `post_modified_gmt` and our SHA to enqueue diffs

**Manual**

* Products list: Bulk “Index to Vector Stores” / “Remove from Vector Stores”
* Row actions: “Index now” / “Remove from indexes”
* Settings → Sync: “Index All”, “Reconcile/Repair”

---

# 10) Admin UI (UX)

**Menu:** WooCommerce → **Vector Indexing**

**Tabs:**

1. **Connections**:

   * OpenAI key; **model** selector (3-large 1536 / 3-small 3072 / ada-002 1536)
   * Optional **dimension override** (guarded)
   * OpenAI Vector Store: pick existing or create
   * Pinecone: key, env, project, index name; **Validate** button checks dimension/metric
2. **Fields**:

   * Core, Taxonomies/Attributes, SEO, Custom Meta, **ACF** (grouped)
   * Per-field “text/JSON” mode for complex fields
   * Search, presets, **Live Preview** (sample product)
   * Chunk size & overlap inputs
3. **Sync**:

   * Auto-sync toggle & cadence
   * “Index All”, “Reconcile/Repair”
   * Stats: last run, pending, successes, errors
4. **Logs**:

   * Pagination; filters (target, action, status)
   * Download CSV
5. **Advanced**:

   * Concurrency, batch sizes, include drafts/private, variation strategy
   * Danger Zone: “Delete all vectors for this site”
6. **About** (new):

   * **Company name/logo/blurb** (from options)
   * **List of your plugins/software** (title, description, link, optional icon)
   * Optional sections: “Changelog”, “Support”, “Contact”

All forms use nonces; capability: `manage_woocommerce` (or a custom capability we add later).

---

# 11) External Integrations

**OpenAI**

* Use selected model + stored dimension
* Create/use Vector Store; upsert documents with metadata
* Handle rate limits (backoff + jitter) and timeouts

**Pinecone**

* Validate target index **dimension equals selected dimension**
* Upsert/delete with metadata filters by `product_id` and `site_id`
* Metric: **cosine** (default, configurable later if needed)

---

# 12) Security, Privacy, Compliance

* Keys encrypted at rest (libsodium; WP salts fallback)
* Strict capability checks, nonces, REST permissions
* Data Sharing notice (what leaves the site); **exclude `_` meta by default** unless user opts in or selects via ACF UI
* Logs avoid storing secrets; include request IDs when available

---

# 13) Error Handling & Observability

* Retries: 3 attempts with exponential backoff (200ms → 2s → 10s + jitter)
* Classify transient vs permanent errors; surface clear messages in Logs
* Health page checks:

  * Connection tests
  * Pinecone dimension vs model/dimension
  * Sample embed + upsert dry-run
  * ACF mappings resolve (if selected)

---

# 14) Multisite & Internationalization

* Per-site settings and indexes (no network-wide sharing in MVP)
* All strings i18n with text domain `wc-vector-indexing`
* Accessible UI (labels/aria, keyboard navigation)

---

# 15) Uninstall / Cleanup

* `uninstall.php` removes options & custom tables
* Optional remote cleanup: delete vectors by `site_id` metadata
* Clear scheduled hooks and queues

---

# 16) Developer Experience

**Coding standards**

* PSR-4 autoload; WP Coding Standards via PHPCS
* PHPUnit tests for chunker, field mapping, adapters (HTTP mocked)
* **WP-CLI**

  * `wp wcvec status`
  * `wp wcvec index --product=123|all`
  * `wp wcvec delete --product=123|all`

**Updated project layout (with About page)**

```
/wc-vector-indexing
  wc-vector-indexing.php
  /includes
    class-plugin.php
    class-admin.php
    class-field-discovery.php
    class-indexer.php
    class-chunker.php
    class-embeddings.php
    /adapters
      class-adapter-interface.php
      class-pinecone-adapter.php
      class-openai-vectorstore-adapter.php
    /jobs
      class-job-index-product.php
      class-job-delete-product.php
    /rest
      class-rest-status.php
  /admin
    /pages
      class-admin-page-connections.php
      class-admin-page-fields.php
      class-admin-page-sync.php
      class-admin-page-logs.php
      class-admin-page-advanced.php
      **class-admin-page-about.php**        ← NEW
    /views
      connections.php
      fields.php
      sync.php
      logs.php
      advanced.php
      **about.php**                         ← NEW (company info, products list UI)
  /assets
    admin.css
    admin.js
    /img (logo/icons for About page)
  /languages
  uninstall.php
  composer.json (guzzlehttp/guzzle, paragonie/sodium_compat if needed)
```

**About page content model (stored in options)**

```json
{
  "company_name": "Your Company",
  "company_blurb": "Short description…",
  "company_logo_url": "https://…",
  "products": [
    {"title":"Plugin A","desc":"…","url":"https://…","icon":"https://…"},
    {"title":"App B","desc":"…","url":"https://…"}
  ],
  "support_url": "https://…",
  "contact_email": "hello@…"
}
```

---

# 17) Milestones & Acceptance Criteria

**M1 – Skeleton, Settings, About (3–4 days)**

* Plugin scaffold, key storage + encryption, model/dimension selector
* Pinecone/OpenAI “Test connections”
* **About page** with editable content (options page)
* **Accept:** Keys masked; tests pass/fail clearly; About content renders

**M2 – Field Discovery & Mapping (incl. ACF) (3–5 days)**

* Discover core/meta/taxonomies/attributes/ACF; selection UI
* Live Preview with text/JSON modes; chunker + SHA fingerprint
* **Accept:** Admin selects fields; preview matches a sample product

**M3 – Embeddings & Pinecone Adapter (4–5 days)**

* Embedding calls with selected model/dimension; Pinecone upsert/delete
* Pinecone index **dimension validation** on save & before first upsert
* **Accept:** 20 sample products indexed; logs show success

**M4 – OpenAI Vector Store Adapter (4–5 days)**

* Create/use store; upsert/delete with metadata
* **Accept:** Same 20 products appear in OpenAI store

**M5 – Background Jobs & Manual Actions (3–4 days)**

* Action Scheduler wiring; cadence; Products bulk/row actions
* **Accept:** Edits/deletes/ACF updates propagate; manual actions work

**M6 – Logs, Health, Advanced, Uninstall (3–4 days)**

* Logs UI + CSV; health checks; uninstall cleanup; danger zone
* **Accept:** Clear logs; uninstall removes local data; optional remote purge works

**M7 – QA, Hardening, Docs (2–3 days)**

* PHPCS, unit tests, readme/help tabs
* **Accept:** Test plan passes WP 6.2+/WC 8+

---

# 18) Test Plan (high level)

* **Functional:** index/update/delete across both targets; ACF changes trigger reindex
* **Edge cases:** very long WYSIWYG; large repeaters; 50k+ products (sampled); rate limits; network blips
* **Security:** capability enforcement; nonce checks; option sanitization; secret masking
* **Data:** Pinecone dimension matches model; metadata filters allow precise deletes
* **Performance:** batch sizes; memory; query counts; SHA skip effectiveness
* **UX:** keyboard navigation; i18n; About page content edit flow

---

# 19) Example: Product → Chunk (flattened)

> **Title:** {name}
> **SKU:** {sku}
> **Price:** {price_display}
> **Categories:** {cat1, cat2}
> **Attributes:** {Size: M, Color: Blue}
> **ACF – Materials:** Cotton; Linen
> **ACF – Care:** Cold wash; Do not bleach
> **Short Description:** …
> **Description (part 1):** …
> *(further parts chunked as needed)*

Each chunk metadata: `product_id`, `sku`, `url`, `fields`, `updated_at`, `fingerprint`, `site_id`.

---

# 20) Risks & Mitigations

* **Dimension mismatches:** validate Pinecone against selected model/dimension before saving/embedding; block misconfig
* **Costs & rate limits:** default to batched calls; retries with backoff; model choice clearly surfaced
* **Sensitive data leakage:** exclude `_` meta by default; prominent notice; explicit opt-in for custom meta/ACF
* **Massive variants:** option to collapse variations; cap batch sizes
