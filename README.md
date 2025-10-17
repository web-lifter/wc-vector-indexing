# WooCommerce Vector Indexing (Pinecone + OpenAI Vector Store)

Index your WooCommerce products into **Pinecone** and **OpenAI Vector Store** using **OpenAI embeddings**. Choose exactly which fields (including **custom fields and ACF**) to embed, keep vectors in sync automatically, and trigger manual indexing from the Products screen.

---

## Features

* **Two vector targets**: Pinecone and OpenAI Vector Store (enable either or both).
* **Embedding models (built-in):**

  * `text-embedding-3-large` — **1536** dimensions
  * `text-embedding-3-small` — **3072** dimensions
  * `text-embedding-ada-002` — **1536** dimensions
* **Field selection UI**: Core product fields, taxonomies/attributes, SEO (Yoast/RankMath), **custom meta**, and **ACF** (Advanced Custom Fields) with per-field “text/JSON” modes.
* **Auto-sync** via Action Scheduler (WP-Cron fallback).
* **Manual indexing**: Bulk and row actions on **Products**; “Index All” button in settings.
* **Chunking** defaults: ~800 tokens with 100 overlap (configurable).
* **Logs & Health checks** with CSV export and “Validate connections”.
* **About page** in admin to showcase your company & other products.

---

## Requirements

* **WordPress** ≥ 6.2
* **PHP** ≥ 8.1
* **WooCommerce** ≥ 8.x
* **Pinecone** account & index (dimension must match selected embedding model)
* **OpenAI** API key with access to Embeddings + **OpenAI Vector Store**
* (Optional) **ACF** (Advanced Custom Fields) for ACF field indexing

---

## Installation

1. Place the plugin folder in `/wp-content/plugins/wc-vector-indexing`.
2. Activate **WooCommerce Vector Indexing** in **Plugins**.
3. Go to **WooCommerce → Vector Indexing → Connections**:

   * Enter **OpenAI API key**.
   * Pick an **embedding model** (see table above).
   * (Optional) Select or create an **OpenAI Vector Store**.
   * Enter **Pinecone** API key, environment, project, and index name.
   * Click **Validate** to confirm connectivity and dimension compatibility.
4. Configure **Fields** (Core, Taxonomies/Attributes, SEO, Custom Meta, **ACF**).
5. Configure **Sync** (enable Auto-sync; set cadence; optionally run **Index All**).

---

## Embeddings & Dimensions

| Model                  | Dimensions (expected) |
| ---------------------- | --------------------: |
| text-embedding-3-large |              **1536** |
| text-embedding-3-small |              **3072** |
| text-embedding-ada-002 |              **1536** |

* The **Pinecone index dimension must match** the selected model’s dimension above (or any manual override you set).
* The plugin blocks saving settings if dimensions are incompatible.

> Default model: **text-embedding-3-small (3072)** for strong recall on longer texts.

---

## Fields & ACF Support

**Selectable sources:**

* Core product fields: ID, SKU, name, short/long descriptions, prices, stock, type
* Taxonomies: categories, tags, **product attributes** (global & custom)
* SEO (Yoast/RankMath): titles/descriptions (optional)
* **Custom Meta** (discoverable; `_`-prefixed keys excluded by default)
* **ACF** field groups targeting `product`/**`product_variation`**

**ACF field handling:**

* Field tree by **Group → Field** with type badges (Text, WYSIWYG, Select, Relationship, Repeater, Flexible, Gallery, etc.)
* Per-field render mode: **Text** (flattened human-readable) or **JSON** (compact)
* **Live Preview** shows exactly what will be embedded for a sample product.
* Hooks **`acf/save_post`** to reindex on ACF changes (SHA-aware to avoid unnecessary re-embeds).

**Normalization (high level):**

* Text/WYSIWYG → plain text (HTML stripped by default)
* Select/Checkbox/Radio → labels joined with commas
* Relationship/Post Object → post titles (optionally SKUs for product refs)
* Repeater/Flexible → flattened lines (Text) or compact JSON
* Image/Gallery/File → alt text & captions (optionally URLs)
* Taxonomy fields → term names (optionally slugs/IDs)
* Dates/Times/Booleans/Numbers → safe string forms

---

## Sync, Scheduling, and Triggers

* **Auto-sync** (default 15 min): Action Scheduler scans modified products and enqueues jobs.
* **Real-time hooks**:
  `save_post_product`, `woocommerce_update_product`, `woocommerce_trash_product`, `woocommerce_delete_product`, and **`acf/save_post`** for ACF changes.
* **Manual**:

  * Products → Bulk actions: **Index to Vector Stores** / **Remove from Vector Stores**
  * Products → Row action: **Index now** / **Remove from indexes**
  * Settings → Sync: **Index All** (full rebuild) and **Reconcile/Repair**

---

## Data & Storage

**Options (encrypted where possible):**

* OpenAI & Pinecone keys and config
* Selected model + embedding **dimension**
* Selected fields (incl. ACF mappings & modes)
* Chunk size & overlap; scheduler cadence; flags (include drafts/variations)
* About page content (company info, products list)

**Custom table**: `wp_wcvec_objects`
Tracks product/vector relationships per target (remote IDs, hashes, last sync, status, error).

> No local vector store—only metadata and remote IDs are stored.

---

## Admin UI

* **Connections**: API keys, model selection, Vector Store, Pinecone index; **Validate** button.
* **Fields**: Select fields (Core, Taxonomies, SEO, Custom Meta, **ACF**), choose text/JSON per field, **Live Preview**, chunking options.
* **Sync**: Auto-sync toggle + cadence; **Index All**; **Reconcile/Repair**; stats (last run, pending, errors).
* **Logs**: Status table (filterable), details on successes/errors, CSV export.
* **Advanced**: Concurrency & batch sizes, include drafts/private, variation strategy, **Danger Zone** (purge all vectors for this site).
* **About**: Your **company** info and **other plugins/software** (see below).

---

## About Page (Your Company & Products)

Configure in **WooCommerce → Vector Indexing → About** (stored as options).

Example content model:

```json
{
  "company_name": "Your Company",
  "company_blurb": "We build tools that make commerce smarter.",
  "company_logo_url": "https://example.com/logo.svg",
  "products": [
    {"title": "Plugin A", "desc": "Short description…", "url": "https://example.com/a", "icon": "https://example.com/a.svg"},
    {"title": "App B", "desc": "Another thing…", "url": "https://example.com/b"}
  ],
  "support_url": "https://example.com/support",
  "contact_email": "hello@example.com"
}
```

---

## WP-CLI

```
# Status overview
wp wcvec status

# Index one product
wp wcvec index --product=123

# Index everything (respecting selected fields & chunking)
wp wcvec index --all

# Remove vectors for a product
wp wcvec delete --product=123

# Remove ALL vectors for this site (dangerous)
wp wcvec delete --all
```

---

## Security & Privacy

* API keys stored via `update_option` and **encrypted at rest** (libsodium; WP salts fallback).
* Capability checks (`manage_woocommerce`) and nonces across all admin actions.
* **Data sharing notice** in Fields tab clarifies what content leaves your site.
* `_`-prefixed meta keys **excluded by default** (can be explicitly included).
* Logs avoid secrets; include request IDs when available.

---

## Developer Notes

**Project structure**

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
      class-admin-page-about.php      # ← About page
    /views
      connections.php
      fields.php
      sync.php
      logs.php
      advanced.php
      about.php
  /assets
    admin.css
    admin.js
    /img
  /languages
  uninstall.php
  composer.json
```

**Coding standards & testing**

* PSR-4 autoload; PHPCS (WP standards); PHPStan recommended.
* PHPUnit tests for chunker, field mapping, adapters (HTTP mocked).

**Filters & actions (early draft)**

* `wcvec_selected_fields` — filter the resolved field map before indexing.
* `wcvec_chunk_text` — filter normalized text before chunking.
* `wcvec_embedding_metadata` — add/modify per-chunk metadata.
* `wcvec_before_upsert` / `wcvec_after_upsert` — wrap vector upserts.
* `wcvec_before_delete` / `wcvec_after_delete` — wrap vector deletions.

**REST (read-only status)**

* `GET /wp-json/wcvec/v1/status` — basic health, pending jobs, last run.

---

## Troubleshooting

* **Validation fails (Pinecone dimension)**
  Ensure your Pinecone index dimension **equals** the selected model’s dimension (or your override).
* **429 / rate limits**
  Lower concurrency/batch size in **Advanced**; Auto-sync will retry with backoff.
* **Nothing reindexes after ACF edits**
  Confirm ACF group targets **product** or **product_variation**; ensure ACF fields are **selected** in Fields tab.
* **Costs**
  Prefer `text-embedding-3-small` for cost/perf; limit selected fields; review chunk size/overlap.

---

## Uninstall

* Removing the plugin via the **Plugins** screen runs `uninstall.php`:

  * Deletes plugin options & custom tables.
  * Clears scheduled hooks.
  * **Optional**: Remote purge (Danger Zone) to delete all vectors for this site (uses metadata filters like `site_id`/`product_id`).

---

## License

MIT — see `LICENSE`.

---

## Changelog

* **Unreleased**

  * Initial MVP: Pinecone + OpenAI Vector Store, model selector (3-large 1536 / 3-small 3072 / ada-002 1536), ACF/custom field embedding, auto-sync, manual indexing, logs, health checks, About page.

---

## Support

* Docs: (add link)
* Issues: (add link)
* Email: (add email)

---

**Happy indexing!**