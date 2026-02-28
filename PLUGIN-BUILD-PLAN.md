# SEV WooCommerce Product Sync — Plugin Build Plan

**Plugin name:** SEV WC Product Sync
**Plugin slug:** `sev-wc-sync`
**Version:** 1.0.0
**Goal:** Scrape sev-stromerzeuger.com (Shopify) → import into WooCommerce, automatically via cron 3× daily. No external servers. No JSON files. No manual steps.

---

## 1. What This Plugin Does (and Does NOT Do)

**Does:**
- Fetches all ~1069 products from sev-stromerzeuger.com's public Shopify JSON API
- Parses spec tables from product HTML
- Creates/updates WooCommerce products (price, stock, images, attributes, categories)
- Deletes (trash) products removed from the source site
- Runs automatically 3× per day via WP Cron
- Detects unchanged products via MD5 hash — skips them (fast subsequent runs)
- Shows progress in WP Admin during manual sync

**Does NOT:**
- Use any external server or JSON file
- Require Python or any server-side CLI tools
- Handle product variations (all products are simple)
- Store descriptions (not available meaningfully from SEV's API)
- Need authentication (uses public Shopify API)

---

## 2. High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      WP Cron (3× daily)                         │
│                   OR Admin "Sync Now" button                    │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                    SEV_Sync_Handler                             │
│  Orchestrates the full pipeline. Two modes:                     │
│  • Full sync  (cron — single PHP execution, no AJAX)            │
│  • Batch sync (AJAX — browser button, batched to avoid timeout)  │
└──────┬───────────────────┬───────────────────┬──────────────────┘
       │                   │                   │
       ▼                   ▼                   ▼
┌──────────────┐  ┌────────────────┐  ┌──────────────────────┐
│ SEV_Api      │  │ SEV_Transformer│  │  SEV_Product_Mapper  │
│ _Client      │  │                │  │                      │
│              │  │ SEV_Html_Parser│  │  Creates/updates WC  │
│ Fetches      │  │                │  │  products. Handles   │
│ Shopify API  │  │ Transforms raw │  │  images, categories, │
│ 5 pages      │  │ Shopify data   │  │  attributes, SKU     │
└──────────────┘  └────────────────┘  └──────────────────────┘
       │                   │
       └─────────┬─────────┘
                 │ raw product data (in-memory array)
                 │ no JSON file written to disk
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                     SEV_State_Manager                           │
│  Per-product MD5 hash stored in WP post meta (_sev_hash)        │
│  If hash unchanged → skip product (no WC write)                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. Plugin File Structure

```
sev-wc-sync/
│
├── sev-wc-sync.php                        ← Main plugin file. Defines constants,
│                                             loads classes, registers AJAX hooks,
│                                             activates/deactivates plugin.
│
├── includes/
│   │
│   ├── class-sev-api-client.php           ← Calls Shopify JSON API.
│   │                                         Paginates through all 5 pages.
│   │                                         Returns raw array of Shopify products.
│   │
│   ├── class-sev-html-parser.php          ← Parses body_html to extract spec
│   │                                         tables. PHP port of html_parser.py.
│   │                                         Uses DOMDocument. Returns Features[].
│   │
│   ├── class-sev-transformer.php          ← Maps raw Shopify product to internal
│   │                                         format. Contains category map array.
│   │                                         PHP port of transformer.py +
│   │                                         category_map.py combined.
│   │
│   ├── class-sev-product-mapper.php       ← Creates/updates WC_Product_Simple.
│   │                                         Sets SKU, price, stock, images,
│   │                                         attributes, categories, meta.
│   │                                         Adapted from existing plugin.
│   │
│   ├── class-sev-sync-handler.php         ← Core orchestrator.
│   │                                         run_full_sync() for cron.
│   │                                         init_batch() / process_batch() for AJAX.
│   │                                         Handles create/update/delete logic.
│   │
│   ├── class-sev-state-manager.php        ← MD5-based change detection.
│   │                                         get_product_hash() / is_changed().
│   │                                         Adapted from existing plugin.
│   │
│   ├── class-sev-cron.php                 ← Registers WP Cron schedule.
│   │                                         Hooks into wcps_sync_cron action.
│   │                                         Configurable interval (default 8h = 3×/day).
│   │
│   ├── class-sev-admin.php                ← Registers WP Admin page under WooCommerce.
│   │                                         Settings form. Manual sync button.
│   │                                         Progress bar. Last sync stats.
│   │
│   └── class-sev-logger.php               ← Writes to /logs/sev-sync-YYYY-MM-DD.log.
│                                             info(), warning(), error(), success().
│
├── assets/
│   ├── js/admin.js                        ← jQuery AJAX for manual sync button.
│   │                                         Calls init_batch → loops process_batch.
│   │                                         Updates progress bar. No JS timeout.
│   └── css/admin.css                      ← Admin page styles.
│
├── logs/
│   ├── .htaccess                          ← deny from all
│   └── index.php                          ← silence is golden
│
└── cache/
    ├── .htaccess                          ← deny from all
    └── index.php                          ← silence is golden
```

**Total: 9 PHP classes + 1 JS file + 1 CSS file**

---

## 4. Data Flow (Step by Step)

### 4a. Cron Sync (main production path)

```
1. WP Cron fires action 'sev_sync_cron'
   │
2. SEV_Sync_Handler::run_full_sync()
   │  @set_time_limit(0)
   │  @ini_set('memory_limit', '512M')
   │  wp_suspend_cache_addition(true)
   │
3. SEV_Api_Client::fetch_all()
   │  GET /collections/all/products.json?limit=250&page=1  (~250 products)
   │  GET /collections/all/products.json?limit=250&page=2  (~250 products)
   │  GET /collections/all/products.json?limit=250&page=3  (~250 products)
   │  GET /collections/all/products.json?limit=250&page=4  (~250 products)
   │  GET /collections/all/products.json?limit=250&page=5  (~19 products)
   │  Returns: array of ~1069 raw Shopify product arrays (in memory)
   │
4. For each raw product → SEV_Transformer::transform($raw)
   │  → SEV_Html_Parser::parse_features($body_html)
   │  Returns: internal product array (Id, Name, Price, Stock, SKU, etc.)
   │
5. For each transformed product:
   │
   ├── SEV_State_Manager: compute MD5 hash of product data
   ├── Look up existing WC product by _sev_external_id meta
   │
   ├── IF product exists AND hash matches stored _sev_hash:
   │     → SKIP (no DB write, no image check)
   │
   ├── IF product exists AND hash changed:
   │     → SEV_Product_Mapper::update($product_data, $wc_product_id)
   │
   └── IF product does not exist:
         → SEV_Product_Mapper::create($product_data)
   │
6. Cleanup: get all WC products with _sev_synced meta
   │  For each one NOT found in current Shopify data:
   │    → wp_trash_post($product_id)
   │
7. Log stats: created, updated, skipped, deleted, errors, duration
8. update_option('sev_last_sync', ...)
```

### 4b. Manual Sync via AJAX (for browser button)

```
Click "Sync Now"
│
├─ AJAX: sev_init_batch
│    → fetch all Shopify pages (fast, ~3-8 seconds)
│    → store all product IDs in transient (2 hour TTL)
│    → store raw product data in transient
│    → return { status: 'running', total: 1069, offset: 0 }
│
├─ AJAX loop: sev_process_batch (called repeatedly, each ~15-30 seconds)
│    → read next batch of N products from transient
│    → transform + import each
│    → update offset in state
│    → return { status: 'running', offset: 50, total: 1069, progress: 5% }
│    → JS calls sev_process_batch again after 200ms
│
├─ (when offset >= total) AJAX: sev_process_batch returns phase 'cleanup'
│    → trash products not in current data
│    → return { status: 'complete', stats: {...} }
│
└─ JS shows success message, reloads page after 3 seconds
```

**Batch size for AJAX: 10 products** (smaller than 50 — because scraping + importing in one step is heavier than import-only)

---

## 5. Module Specifications

### 5a. SEV_Api_Client

**Purpose:** Fetches product data from Shopify's public JSON API.

**Key method:**
```php
fetch_all(): array   // returns flat array of all raw Shopify products
```

**How it works:**
- Base URL: `https://sev-stromerzeuger.com/collections/all/products.json`
- Parameters: `?limit=250&page=N`
- Loops pages 1→N until response returns empty `products` array
- Returns array of all products combined
- Uses `wp_remote_get()` with `timeout: 30` per page request (each page is fast, <250 products)
- If a page request fails → logs warning, stops pagination, uses what was fetched so far

**No state stored** — purely fetches and returns.

---

### 5b. SEV_Html_Parser

**Purpose:** Parses product spec tables from HTML. PHP port of `html_parser.py`.

**Key method:**
```php
parse_features(string $body_html): array   // returns Features[] array
```

**How it works:**
- Uses PHP `DOMDocument` with `@$dom->loadHTML()` (@ suppresses HTML warnings)
- Finds all `<table>` elements, iterates `<tr>` rows
- Each row: cell[0] = feature name, cell[1] = feature value
- Skips empty rows, header rows (both cells same value), rows where name > 80 chars
- Deduplicates by lowercase feature name
- Slug generation: lowercase, strip non-alphanumeric (keep German letters äöüß), spaces to underscores, cap at 40 chars

**Output format:**
```php
[
    ['Id' => 'nennleistung_kva', 'Name' => 'Nennleistung (kVA)', 'Value' => [['Name' => '5.5 kVA']]],
    ['Id' => 'kraftstoff',       'Name' => 'Kraftstoff',         'Value' => [['Name' => 'Benzin']]],
]
```

---

### 5c. SEV_Transformer

**Purpose:** Maps raw Shopify product array to internal product format. Also contains the category map. PHP port of `transformer.py` + `category_map.py`.

**Key method:**
```php
transform(array $shopify_product): array   // returns internal product array
```

**Field mapping (exact rules):**

| Internal field | Source | Rule |
|---|---|---|
| `id` | `product.id` | Cast to string |
| `name` | `product.title` | trim() |
| `vendor` | `product.vendor` | trim() |
| `sku` | `variants[0].sku` | Use if non-empty, else `''` (empty string — no fallback) |
| `price` | `variants[0].price` | floatval(), 0.0 if missing |
| `stock_status` | `variants[0].available` | `true` → `'instock'`, `false` → `'outofstock'` |
| `stock_qty` | `variants[0].inventory_quantity` | Use if > 0, else `null` (let WC use stock_status only) |
| `photos` | `product.images[].src` | Array of URLs, strip query string (remove `?v=...`) |
| `category` | `product.product_type` | Run through category map (see Section 7) |
| `features` | `product.body_html` | Run through SEV_Html_Parser |

**Stock behavior** (matches source site, user requirement):
```
available = true  AND inventory_quantity > 0  → manage_stock=true,  qty=N,    status=instock
available = true  AND inventory_quantity <= 0 → manage_stock=false, qty=null, status=instock
available = false                             → manage_stock=false, qty=null, status=outofstock
```

**Category map:** Full PHP array with 47 entries (direct port from `category_map.py`).
- Direct match first
- Case-insensitive fallback
- Unknown types → use as-is (top-level category)

---

### 5d. SEV_Product_Mapper

**Purpose:** Creates or updates a `WC_Product_Simple` from internal product data.

**Key method:**
```php
map_product(array $product, int $wc_id = null): int|WP_Error
```

**What it sets:**
```php
$wc->set_name($product['name']);
$wc->set_sku($product['sku']);         // '' if no SKU — WC allows empty SKU
$wc->set_regular_price($product['price']);
$wc->set_status('publish');
$wc->set_catalog_visibility('visible');

// Stock — matches source site
if ($product['stock_qty'] !== null) {
    $wc->set_manage_stock(true);
    $wc->set_stock_quantity($product['stock_qty']);
}
$wc->set_stock_status($product['stock_status']);   // 'instock' or 'outofstock'

// Categories (auto-create hierarchy)
$wc->set_category_ids($this->get_or_create_categories($product['category']));

// Attributes (Producer + spec features)
$wc->set_attributes($this->build_attributes($product));
```

**Post meta stored:**
```
_sev_external_id   = product['id']       ← used to find product on next sync
_sev_synced        = 'yes'               ← marks plugin-managed products
_sev_hash          = md5(...)            ← change detection
_sev_last_sync     = current datetime
```

**SKU note:** `set_sku('')` is valid in WooCommerce — it stores an empty SKU. If Shopify has a SKU like `SEV-1234` in `variants[0].sku`, that value is used directly. No `SYNC-` prefix, no product ID fallback.

**Images:**
- Check if image already in media library via `_sev_source_url` meta (cache lookup)
- If not found: `download_url($url, 60)` → `media_handle_sideload()`
- First image = featured image, rest = gallery
- Store `_sev_source_url` meta on attachment for cache

**Adapted from:** `class-wcps-product-mapper.php` (existing plugin). Changes: SKU logic, stock logic, meta key prefixes `_sev_` instead of `_wcps_`.

---

### 5e. SEV_Sync_Handler

**Purpose:** Core orchestrator. Manages the full pipeline for both cron and AJAX modes.

**Key methods:**
```php
run_full_sync(): array          // for cron — blocking, set_time_limit(0)
init_batch(): array             // for AJAX — fetch all data, store transient
process_batch(): array          // for AJAX — process N products, return progress
reset_batch(): void             // clears batch state (error recovery / reset cache button)
get_all_synced_products(): array  // [external_id => wc_product_id]
```

**Batch state** (stored in WP option `sev_batch_state`):
```php
[
    'status'    => 'running',   // 'running' | 'complete' | 'error'
    'phase'     => 'products',  // 'products' | 'cleanup' | 'done'
    'offset'    => 0,
    'total'     => 1069,
    'stats'     => ['created'=>0, 'updated'=>0, 'skipped'=>0, 'deleted'=>0, 'errors'=>0],
    'started_at'=> 1709000000,
]
```

**Transient keys** (2-hour TTL):
```
sev_batch_products     → array of all raw Shopify products (from API fetch)
sev_batch_product_ids  → array of all product IDs (for iteration)
sev_batch_existing     → [external_id => wc_product_id] (snapshot at sync start)
```

**Cleanup phase:**
- Iterate `sev_batch_existing` transient
- Any external_id NOT present in `sev_batch_product_ids` → `wp_trash_post($wc_id)`
- Skip products already in trash (avoid double-counting in stats)

---

### 5f. SEV_State_Manager

**Purpose:** MD5-based change detection. Determines if a product needs to be updated.

**Key methods:**
```php
get_hash(array $product): string    // returns MD5 of hashable fields
is_changed(string $ext_id, array $product): bool  // compare against stored hash
store_hash(int $wc_id, array $product): void
```

**Hashable fields** (fields that trigger an update if changed):
```php
$hashable = [
    'name'         => $product['name'],
    'price'        => $product['price'],
    'stock_status' => $product['stock_status'],
    'stock_qty'    => $product['stock_qty'],
    'category'     => $product['category'],
    'vendor'       => $product['vendor'],
    'sku'          => $product['sku'],
    'photos'       => $product['photos'],   // array of URLs
    'features'     => $product['features'], // array of feature objects
];
return md5(json_encode($hashable));
```

**Storage:** Hash stored as `_sev_hash` post meta on WC product post.

---

### 5g. SEV_Cron

**Purpose:** Registers WP Cron schedule and hooks the sync to it.

**Schedule:** Default every 8 hours (3× daily: 00:00, 08:00, 16:00).
Configurable in settings: 6h (4×/day), 8h (3×/day), 12h (2×/day), 24h (1×/day).

**How it works:**
```php
register_activation_hook  → wp_schedule_event(time(), 'sev_every_8h', 'sev_sync_cron')
register_deactivation_hook → wp_clear_scheduled_hook('sev_sync_cron')
add_action('sev_sync_cron', [SEV_Sync_Handler, 'run_full_sync'])
add_filter('cron_schedules', 'add_sev_schedules')  // adds 6h, 8h, 12h intervals
```

**Reschedule on settings change:** When admin saves new interval, clear old cron and re-schedule.

---

### 5h. SEV_Admin

**Purpose:** WP Admin page under WooCommerce menu.

**Admin page location:** WooCommerce → SEV Product Sync

**Settings stored as WP options:**
```
sev_enabled           'yes' | 'no'
sev_sync_interval     '6' | '8' | '12' | '24'  (hours)
sev_delete_behavior   'trash' | 'delete'
sev_source_url        'https://sev-stromerzeuger.com'  (default, can override)
```

**Admin page sections:**

```
┌────────────────────────────────────────────────────────────────┐
│  SEV WooCommerce Product Sync                                  │
├────────────────────────────────────────────────────────────────┤
│  STATUS                                                        │
│  Last sync:   2026-02-27 08:00:13                             │
│  Result:      Created 3 │ Updated 12 │ Skipped 1054 │ Trashed 0 │
│  Next sync:   2026-02-27 16:00:00                             │
├────────────────────────────────────────────────────────────────┤
│  MANUAL SYNC                                                   │
│  [▶ Sync Now]                                                  │
│  ████████████░░░░  67% — Processing product 712 of 1069       │
├────────────────────────────────────────────────────────────────┤
│  SETTINGS                                                      │
│  Enable sync:      [✓] Active                                  │
│  Schedule:         [ 8 hours ▾ ]  (3× daily)                  │
│  Delete behavior:  [● Move to Trash  ○ Delete permanently]    │
│  [Save Settings]                                               │
├────────────────────────────────────────────────────────────────┤
│  TOOLS                                                         │
│  [Reset Sync Cache]   Clears all hashes → forces full re-sync │
│  [View Logs]          Opens today's log file in browser        │
└────────────────────────────────────────────────────────────────┘
```

---

### 5i. SEV_Logger

**Purpose:** Writes dated log files.

**Log location:** `plugins/sev-wc-sync/logs/sev-sync-YYYY-MM-DD.log`

**Methods:** `info()`, `warning()`, `error()`, `success()`

**Format:** `[2026-02-27 08:00:14] [INFO] Created product | {"external_id":"9270157705563","wc_id":2145}`

**Log rotation:** Keep last 7 days, delete older files on activation/sync start.

---

## 6. AJAX Endpoints

Registered in main plugin file with `wp_ajax_` prefix:

| Action | Handler | Description |
|---|---|---|
| `sev_init_batch` | `ajax_init_batch()` | Fetch all Shopify pages, init batch state |
| `sev_process_batch` | `ajax_process_batch()` | Process next N products, return progress |
| `sev_reset_cache` | `ajax_reset_cache()` | Clear all hashes (force full re-sync) |
| `sev_get_status` | `ajax_get_status()` | Get last sync stats (for admin page load) |

**All endpoints:** require nonce `sev_admin_nonce`, capability `manage_woocommerce`.

**JavaScript flow (no timeout set):**
```javascript
$.ajax({ action: 'sev_init_batch', ... })
  .done(function(res) {
      // res.data = { total: 1069, ... }
      processBatch();   // start loop
  });

function processBatch() {
    $.ajax({ action: 'sev_process_batch', ... })
      .done(function(res) {
          updateProgressBar(res.data);
          if (res.data.status === 'running') {
              setTimeout(processBatch, 200);  // continue after 200ms
          } else {
              showComplete(res.data.stats);
          }
      });
}
// Note: no timeout: parameter → jQuery default = 0 (unlimited)
```

---

## 7. Data Mapping Reference

### 7a. Shopify API → Internal format (SEV_Transformer)

```
Shopify field                        → Internal field
─────────────────────────────────────────────────────
product.id                           → id           (string)
product.title                        → name         (string, trimmed)
product.vendor                       → vendor       (string, trimmed)
product.product_type                 → (input to category map)
product.body_html                    → (input to HTML parser)
product.images[].src                 → photos[]     (strip Shopify CDN ?v=XXXXX query param)

variants[0].sku                      → sku          (use if non-empty, else '' — NO fallback)
variants[0].price                    → price        (float, e.g. 29.16)
variants[0].available                → stock_status ('instock' | 'outofstock')
variants[0].inventory_quantity       → stock_qty    (int if > 0, else null)
```

### 7b. Internal format → WooCommerce (SEV_Product_Mapper)

```
Internal field   → WooCommerce
─────────────────────────────────────────────────────────────────────
id               → post meta: _sev_external_id (lookup key)
name             → wc_product->set_name()
vendor           → WC product attribute "Producer" (visible, non-variation)
sku              → wc_product->set_sku()  ← '' is valid, WC accepts it
price            → wc_product->set_regular_price()
stock_status     → wc_product->set_stock_status()
stock_qty        → wc_product->set_stock_quantity() if not null
                    wc_product->set_manage_stock(true) if not null
photos[]         → featured image (first) + gallery (rest)
                    cached by _sev_source_url attachment meta
category         → wc_product->set_category_ids() (auto-created hierarchy)
features[]       → WC global product attributes (pa_slug) visible on product page
```

### 7c. Category Map (47 entries → abbreviated sample)

```php
// Full array in class-sev-transformer.php
$category_map = [
    // Generators
    'Benzin Generator'    => 'Stromerzeuger - Portable Generators',
    'Benzingenerator'     => 'Stromerzeuger - Portable Generators',
    'Dieselgenerator'     => 'Stromerzeuger - Portable Generators',
    'Invertergenerator'   => 'Stromerzeuger - Inverter Generators',
    'Notstromaggregat'    => 'Stromerzeuger - Emergency Power',
    'Zapfwellenaggregat'  => 'Stromerzeuger - PTO Generators',
    // Energy Storage
    'Powerstation'        => 'Energy Storage - Portable Power Stations',
    'Balkonkraftwerk'     => 'Energy Storage - Balcony Solar',
    'Wallbox'             => 'Energy Storage - Wallbox & EV Charging',
    // Accessories
    'Batterieladegerät'   => 'Accessories - Battery Chargers',
    'Verlängerungskabel'  => 'Accessories - Cables',
    // ... all 47 entries from category_map.py
];
```

---

## 8. Storage Reference

All plugin data stored in WordPress. Nothing in flat files except logs.

### WP Options

| Option key | Type | Description |
|---|---|---|
| `sev_enabled` | string | `'yes'` or `'no'` |
| `sev_sync_interval` | string | Hours: `'6'`, `'8'`, `'12'`, `'24'` |
| `sev_delete_behavior` | string | `'trash'` or `'delete'` |
| `sev_source_url` | string | Base URL of source site (default: sev-stromerzeuger.com) |
| `sev_last_sync` | string | MySQL datetime of last completed sync |
| `sev_last_sync_stats` | array | `{created, updated, skipped, deleted, errors, duration_seconds}` |
| `sev_batch_state` | array | Current batch state (cleared after completion) |

### WP Transients (during AJAX batch sync only, 2-hour TTL)

| Transient key | Content |
|---|---|
| `sev_batch_products` | All raw Shopify products (in-memory during sync) |
| `sev_batch_product_ids` | All product ID strings |
| `sev_batch_existing` | `[external_id => wc_product_id]` at sync start |

### WP Post Meta (per WooCommerce product)

| Meta key | Value |
|---|---|
| `_sev_external_id` | Shopify product ID (lookup key for next sync) |
| `_sev_synced` | `'yes'` (marks this product as plugin-managed) |
| `_sev_hash` | MD5 hash of product data (change detection) |
| `_sev_last_sync` | MySQL datetime of last update for this product |

### WP Attachment Meta (per media library image)

| Meta key | Value |
|---|---|
| `_sev_source_url` | Original Shopify CDN URL (image cache lookup) |

---

## 9. Build Order

Build in this exact order. Each step is testable independently.

### Phase 1 — Foundation (no UI, no imports)

**Step 1.1** — `sev-wc-sync.php` (main plugin file)
- Plugin header, constants, `includes()`, `init_hooks()`
- Empty AJAX stubs
- Activation: create logs/ and cache/ dirs, set default options
- Deactivation: clear cron

**Step 1.2** — `class-sev-logger.php`
- No dependencies
- `info()`, `warning()`, `error()`, `success()` methods
- Test: activate plugin → check logs/ dir created

**Step 1.3** — `class-sev-api-client.php`
- No dependencies (uses `wp_remote_get`)
- `fetch_all()` — paginate Shopify API
- Test: call `fetch_all()` in a wp-admin hook → should return ~1069 products

**Step 1.4** — `class-sev-html-parser.php`
- No dependencies
- `parse_features($html)` — DOMDocument table parsing
- Test: pass a sample product's `body_html` → check features array

**Step 1.5** — `class-sev-transformer.php`
- Depends on: `SEV_Html_Parser`
- `transform($shopify_product)` method
- Full category map array (port from `category_map.py`)
- Test: transform one raw product → inspect output array

### Phase 2 — Importer

**Step 2.1** — `class-sev-state-manager.php`
- Depends on nothing
- `get_hash()`, `is_changed()`, `store_hash()`
- Port directly from existing `class-wcps-state-manager.php`, rename methods

**Step 2.2** — `class-sev-product-mapper.php`
- Depends on: `SEV_State_Manager`, WooCommerce
- Port from existing `class-wcps-product-mapper.php`
- Key changes: SKU logic (use variant SKU or empty), stock logic (use available field), meta prefix `_sev_`
- Test: call `map_product()` manually with one product → check WC product created correctly

### Phase 3 — Sync Handler

**Step 3.1** — `class-sev-sync-handler.php`
- Depends on: all above classes
- Implement `run_full_sync()` first (simpler, used by cron)
- Then add `init_batch()` and `process_batch()` for AJAX
- Test: call `run_full_sync()` via a temporary wp-admin hook → watch logs

### Phase 4 — UI

**Step 4.1** — `class-sev-cron.php`
- Depends on: `SEV_Sync_Handler`
- Register schedules, hook to `run_full_sync()`
- Test: set interval to 1 min, wait, check if sync ran

**Step 4.2** — `class-sev-admin.php` + `assets/admin.js` + `assets/admin.css`
- Depends on: all above
- Admin page rendering
- Settings form save
- AJAX manual sync button (JS)
- Progress bar
- Test: click "Sync Now" → watch progress bar → check products created

---

## 10. Design Decisions & Constraints

### No intermediate JSON file
Data flows entirely in memory: API response array → transformer → product mapper → WooCommerce DB. This avoids all the file hosting / connectivity issues encountered with the previous approach.

### Batch size: 10 products per AJAX batch
Previously 50 products per batch was used for import-only. Now each batch includes scraping (HTML parsing) + import. Set to 10 to stay well under any server timeout even on slow hosting. With 1069 products ÷ 10 = ~107 AJAX calls × ~5 seconds each ≈ 9 minutes total. Each individual call is short (<30s).

### WC attribute slug limit: 28 characters
WooCommerce enforces 28-char max on taxonomy slugs. The `truncate_slug()` method handles this by truncating at a word boundary (hyphen) near the 28-char limit.

### Image caching by source URL
The `_sev_source_url` meta on each attachment prevents re-downloading the same image on subsequent syncs. Only new or changed products trigger new image downloads.

### Shopify inventory quantity unreliable without auth
The public Shopify API does not reliably return actual inventory counts. Strategy:
- `variants[0].available` (boolean) is reliable → use for stock_status
- `inventory_quantity` > 0 → use as quantity, set manage_stock=true
- `inventory_quantity` <= 0 + available=true → manage_stock=false, instock (quantity unknown)
- `inventory_quantity` <= 0 + available=false → manage_stock=false, outofstock

### Empty SKU is valid in WooCommerce
`WC_Product::set_sku('')` is accepted — WooCommerce does not require SKU. Only use the SKU value from `variants[0].sku` if it's non-empty. Do not generate a synthetic SKU from the product ID.

### AlternativeProducts not used for SEV
SEV products always have `AlternativeProducts: []` and `AlternativeProductIds: []` (no cross-sell data in Shopify). The `link_alternative_products()` step from the previous plugin is not needed and will not be included.

### Cron is the primary mode
The manual "Sync Now" button is for testing and manual triggers. Production automation is entirely via WP Cron. This is important: if the browser tab is closed during a manual sync, the cron will run the full sync at the next scheduled time anyway.

---

## 11. What We Reuse From the Existing Plugin

The existing `wc-product-import-plugin-main` has well-tested code. We port (not rewrite from scratch) these parts:

| Existing class | Used in new plugin as | Changes needed |
|---|---|---|
| `class-wcps-product-mapper.php` | `class-sev-product-mapper.php` | SKU logic, stock logic, meta prefix `_sev_`, remove alternative products linking |
| `class-wcps-sync-handler.php` | `class-sev-sync-handler.php` | Replace JSON fetcher call with API client + transformer, adjust batch sizes |
| `class-wcps-state-manager.php` | `class-sev-state-manager.php` | Rename methods, simplify (no NDJSON file, just hash per product) |
| `class-wcps-cron.php` | `class-sev-cron.php` | Rename hooks |
| `class-wcps-logger.php` | `class-sev-logger.php` | Rename constants |
| `assets/js/admin.js` | `assets/js/admin.js` | Update AJAX action names, no timeout |
| `assets/css/admin.css` | `assets/css/admin.css` | Minor updates |

**New code (no existing equivalent):**
- `class-sev-api-client.php` — PHP port of `fetcher.py`
- `class-sev-html-parser.php` — PHP port of `html_parser.py`
- `class-sev-transformer.php` — PHP port of `transformer.py` + `category_map.py`

---

## 12. File Checklist (Build Tracker)

```
[ ] sev-wc-sync.php                     — main plugin file
[ ] includes/class-sev-logger.php       — logging
[ ] includes/class-sev-api-client.php   — Shopify API fetcher
[ ] includes/class-sev-html-parser.php  — spec table parser
[ ] includes/class-sev-transformer.php  — field mapper + category map
[ ] includes/class-sev-state-manager.php — change detection (MD5 hash)
[ ] includes/class-sev-product-mapper.php — WC product create/update
[ ] includes/class-sev-sync-handler.php — batch orchestrator
[ ] includes/class-sev-cron.php         — WP cron scheduler
[ ] includes/class-sev-admin.php        — admin page + settings
[ ] assets/js/admin.js                  — progress bar JS
[ ] assets/css/admin.css                — styles
[ ] logs/.htaccess                      — deny from all
[ ] logs/index.php                      — silence
[ ] cache/.htaccess                     — deny from all
[ ] cache/index.php                     — silence
```

**Total: 16 files. Estimated build time: 2-3 days working sessions.**

---

*Document created: 2026-02-27*
*Status: Ready to build — start with Phase 1, Step 1.1*

---

## 13. Coding Segments (Session Workflow)

Each segment produces a fully activatable plugin. Stubs fill in for classes not yet implemented and get replaced as segments progress.

### Segment 1 — Plugin Skeleton + Scraper Foundation
**Files written (real):** `sev-wc-sync.php`, `class-sev-logger.php`, `class-sev-api-client.php`
**Files written (stubs):** All 7 remaining classes + `assets/js/admin.js` + `assets/css/admin.css` + directory protection files
**Result after segment:** Plugin can be activated in WordPress. API client can fetch all ~1069 products from Shopify. Logs are written. All other operations return stub responses.
**Status:** [ ] pending

### Segment 2 — Scraper Layer (HTML Parser + Transformer)
**Files replaced:** `class-sev-html-parser.php` (stub → real), `class-sev-transformer.php` (stub → real)
**Result after segment:** Products fetched from Shopify are fully parsed and transformed to internal format (features extracted, categories mapped, SKU/stock mapped correctly).
**Status:** [ ] pending

### Segment 3 — Importer Layer (State Manager + Product Mapper)
**Files replaced:** `class-sev-state-manager.php` (stub → real), `class-sev-product-mapper.php` (stub → real)
**Result after segment:** WooCommerce products can be created and updated from the internal format. Change detection works (skips unchanged products). Images are sideloaded.
**Status:** [ ] pending

### Segment 4 — Sync Handler (Full Pipeline)
**Files replaced:** `class-sev-sync-handler.php` (stub → real)
**Result after segment:** Full end-to-end sync works. Both `run_full_sync()` (for cron) and `init_batch()` / `process_batch()` (for AJAX) are implemented. Cleanup (trash removed products) works.
**Status:** [ ] pending

### Segment 5 — Admin UI + Cron + JS + CSS
**Files replaced:** `class-sev-cron.php` (stub → real), `class-sev-admin.php` (stub → real), `assets/js/admin.js` (stub → real), `assets/css/admin.css` (stub → real)
**Result after segment:** Complete plugin. Admin page shows status, manual sync button with live progress bar, settings form, cron automation active.
**Status:** [ ] pending

---

### Segment Status Tracker
```
[x] Segment 1 — Skeleton + API Client + Logger       (16 files)
[ ] Segment 2 — HTML Parser + Transformer            (2 files replaced)
[ ] Segment 3 — State Manager + Product Mapper       (2 files replaced)
[ ] Segment 4 — Sync Handler                         (1 file replaced)
[ ] Segment 5 — Admin + Cron + JS + CSS              (4 files replaced)
```
