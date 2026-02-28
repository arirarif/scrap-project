# WC Product Sync - Comprehensive Architecture & Development Analysis

## Executive Summary

**WC Product Sync** is a sophisticated WordPress/WooCommerce plugin that automates product synchronization from external JSON data sources. It implements enterprise-level architecture patterns including dependency injection, state management, logging, cron scheduling, and streaming data processing. This plugin is production-ready and demonstrates professional plugin development practices.

---
79.k/
## 1. WHAT THE PLUGIN DOES

### Core Functionality

The plugin performs **three-way product synchronization** between a remote JSON data source and WooCommerce:

1. **CREATE** - Adds new products from JSON that don't exist in WooCommerce
2. **UPDATE** - Modifies existing products when JSON data changes
3. **DELETE** - Removes/trashes products that are no longer in the JSON source

### Specific Features

- **Remote JSON Fetching** - Downloads product data from a configurable URL with error handling
- **Field-Level Change Detection** - Tracks which specific fields changed between syncs
- **Batch Processing** - Handles large product catalogs efficiently
- **Automatic Scheduling** - Uses WordPress Cron (WP-Cron) for recurring syncs at configurable intervals
- **Manual Trigger** - AJAX interface to run sync immediately from admin panel
- **Smart Category Handling** - Auto-creates hierarchical categories (Parent > Child > Grandchild)
- **Product Attributes** - Maps JSON features to WooCommerce global attributes
- **Product Images/Photos** - Handles multiple product photos with media library integration
- **Alternative Products** - Manages product relationships (cross-sells, related products)
- **Real-time Progress Tracking** - Shows sync progress with percentage and detailed status
- **Comprehensive Logging** - Daily log files with detailed operation records
- **State Persistence** - Remembers previous sync state for efficient diff detection

### JSON Data Structure Expected

```json
{
  "external_id": {
    "Id": "external_id",
    "Name": "Product Name",
    "Price": 99.99,
    "Stock": 50,
    "Category": "Electronics - Phones - Smartphones",
    "Producer": "Apple",
    "ProducerCode": "PROD-123",
    "Features": [
      {
        "Name": "Color",
        "Value": [
          {"Name": "Black"},
          {"Name": "White"}
        ]
      }
    ],
    "Photos": ["url1", "url2"],
    "AlternativeProducts": ["id2", "id3"]
  }
}
```

---

## 2. ARCHITECTURE OVERVIEW

### 2.1 Architectural Pattern: Modular Object-Oriented Design

The plugin follows **Single Responsibility Principle (SRP)** with distinct classes handling specific concerns:

```
┌─────────────────────────────────────────────────────────────┐
│         WC_Product_Sync (Main Bootstrap)                    │
│  - Singleton Pattern                                         │
│  - Dependency Injection                                      │
│  - Hook Management                                           │
└─────────────────────────────────────────────────────────────┘
            │
    ┌───────┼───────┬──────────────┬─────────────┐
    │       │       │              │             │
    ▼       ▼       ▼              ▼             ▼
┌────────┐┌────────┐┌─────────┐┌────────┐┌─────────────┐
│ Admin  ││ Cron   ││ Sync    ││ JSON   ││ State &     │
│Settings││Handler ││Handler  ││Fetcher ││ Logging     │
└────────┘└────────┘└─────────┘└────────┘└─────────────┘
                │
        ┌───────┴──────────┐
        │                  │
        ▼                  ▼
   ┌──────────┐    ┌────────────┐
   │ Product  │    │ Logger     │
   │ Mapper   │    │ (Logging)  │
   └──────────┘    └────────────┘
```

### 2.2 Class Architecture

#### **WC_Product_Sync** (Main Bootstrap Class)
- **Pattern**: Singleton with static instance
- **Responsibility**: Initialize plugin, manage hooks, coordinate components
- **Key Methods**:
  - `get_instance()` - Get single instance
  - `check_dependencies()` - Verify WooCommerce is active
  - `includes()` - Load all class files
  - `init_hooks()` - Register activation/deactivation hooks and AJAX handlers
  - `activate()` - Create directories, set defaults
  - `deactivate()` - Clean up cron jobs
  - `ajax_manual_sync()` - Trigger immediate sync via AJAX
  - `ajax_get_sync_status()` - Get current sync progress

**Why Singleton?** Ensures only one instance exists globally, preventing duplicate initialization.

---

#### **WCPS_Sync_Handler** (Core Synchronization Engine)
- **Pattern**: Dependency Injection (receives dependencies in constructor)
- **Responsibility**: Orchestrate the entire sync workflow
- **Key Properties**:
  ```php
  private $logger;        // For logging operations
  private $fetcher;       // For JSON retrieval
  private $mapper;        // For product creation/updates
  private $state_manager; // For state persistence
  private $stats = [];    // Track created/updated/deleted counts
  ```

- **Main Method**: `run_sync()`
  
  **Workflow**:
  ```
  1. Check if sync is enabled
  2. Start logging session
  3. Update progress to "fetching"
  4. Fetch JSON data from URL
  5. Load previous state for change detection
  6. Compare products in batches
  7. Process each product (create/update/skip)
  8. Find and delete products no longer in JSON
  9. Link alternative products
  10. Save new state for future comparison
  11. Update last sync timestamp
  12. Return statistics
  ```

- **Smart Features**:
  - **Hash-based Change Detection**: Each product gets a hash. If hash matches, skip update
  - **Field-Level Comparison**: If previous state exists, track which specific fields changed
  - **Progress Tracking**: Updates sync progress every 10 products
  - **Batch Processing**: Handles large catalogs without timeout
  - **Error Recovery**: Continues processing even if individual products fail

**Example Statistics Returned**:
```php
[
    'created' => 5,
    'updated' => 12,
    'deleted' => 2,
    'skipped' => 150,
    'errors' => 1,
    'total' => 170,
    'duration' => 25.34  // seconds
]
```

---

#### **WCPS_JSON_Fetcher** (Remote Data Retrieval)
- **Responsibility**: Fetch and validate JSON from remote URL
- **Key Methods**:
  - `fetch()` - Download JSON from configured URL
  - `validate_structure()` - Ensure JSON has expected fields
  - `save_hash()` - Store hash of entire JSON for comparison
  - `get_product_hash()` - Generate hash of single product

- **Robustness**:
  - Extended 120-second timeout for large files
  - HTTP status code validation
  - JSON decode error handling
  - Empty response detection
  - SSL verification enabled
  - Proper error messages via WP_Error

**Example Error Handling**:
```php
// Returns WP_Error on failure
$response = wp_remote_get($url, ['timeout' => 120, 'sslverify' => true]);
if (is_wp_error($response)) {
    $this->logger->error('Failed to fetch JSON', 
        ['error' => $response->get_error_message()]
    );
    return $response;
}
```

---

#### **WCPS_Product_Mapper** (Data Transformation)
- **Largest class** (667 lines) - Handles complex mapping logic
- **Responsibility**: Convert JSON product data to WooCommerce products
- **Key Methods**:
  - `map_product($json_product, $existing_id)` - Create or update product
  - `get_or_create_categories($category_string)` - Handle hierarchical categories
  - `build_attributes($json_product)` - Convert Features to WC attributes
  - `create_attribute()` - Create global WC attributes
  - `handle_photos()` - Download and attach images
  - `get_all_synced_products()` - Retrieve all synced products
  - `link_alternative_products()` - Create product relationships

- **Product Data Mapping**:
  ```php
  JSON Field          →  WC Product Property
  ─────────────────────────────────────────
  Name                →  set_name()
  Price               →  set_regular_price()
  Stock               →  set_stock_quantity()
  Category            →  set_category_ids()
  Features            →  set_attributes()
  Photos              →  Media Library (featured + gallery)
  AlternativeProducts →  Linked Products
  Id                  →  _external_id (meta)
  ProducerCode       →  _producer_code (meta)
  ```

- **Smart Category Creation**:
  - Parses "Parent - Child - Grandchild" format
  - Creates missing categories automatically
  - Caches results for performance
  - Sets correct parent-child relationships

- **Attribute Handling**:
  - Creates WooCommerce global attributes (pa_* taxonomies)
  - Handles 28-character slug limitation
  - Supports feature value arrays
  - Falls back to custom attributes if needed

**Code Example**:
```php
// From JSON structure
if (!empty($json_product['Features'])) {
    foreach ($json_product['Features'] as $feature) {
        $attr = $this->create_attribute(
            $feature['Name'],
            array_column($feature['Value'], 'Name')
        );
    }
}
```

---

#### **WCPS_State_Manager** (State Persistence & Change Detection)
- **Advanced Data Structure**: Uses NDJSON (Newline-Delimited JSON)
- **Responsibility**: Remember previous sync state for efficient diff detection
- **Key Files**:
  - `cache/last-sync.ndjson` - Current product state (streaming format)
  - `cache/last-sync.index` - Fast lookup index
  - `cache/previous-sync.ndjson` - Previous sync state
  - `cache/previous-sync.index` - Previous lookup index

- **Why NDJSON?**
  - Each line is one complete JSON product
  - Can read line-by-line without loading entire file into memory
  - Scalable for 10,000+ products
  - Enables streaming comparison

- **Key Methods**:
  - `rotate_state()` - Move current state to previous
  - `save_state()` - Persist JSON state in NDJSON format
  - `load_previous_state()` - Load previous state index
  - `get_previous_product()` - Retrieve single product from previous state
  - `compare_products()` - Detect field-level changes
  - `build_index_from_ndjson()` - Create fast lookup index

- **Performance Optimization**:
  - Index file stores byte position of each product
  - Streaming file reading (doesn't load all to memory)
  - Only loads products that changed
  - File cleanup (removes files older than 7 days)

**NDJSON Format Example**:
```json
{"id":"prod-001","data":{"Name":"Product 1","Price":99.99,...}}
{"id":"prod-002","data":{"Name":"Product 2","Price":149.99,...}}
{"id":"prod-003","data":{"Name":"Product 3","Price":199.99,...}}
```

---

#### **WCPS_Cron** (Scheduled Execution)
- **Pattern**: Singleton
- **Responsibility**: Schedule recurring syncs using WordPress Cron
- **Key Methods**:
  - `add_cron_schedules()` - Register custom interval (minutes or hours)
  - `schedule_sync()` - Create WP-Cron job
  - `unschedule_sync()` - Remove WP-Cron job
  - `run_scheduled_sync()` - Execute sync from cron event
  - `handle_enabled_change()` - Auto-schedule when enabled

- **Smart Features**:
  - Prevents duplicate sync runs (checks if already running)
  - Supports minutes (minimum 5) or hours intervals
  - Automatically reschedules if interval changes
  - Logs each scheduled execution
  - Uses WordPress native `wp_schedule_event()`

**Why WordPress Cron and not system cron?**
- Works on all hosting without SSH access
- Triggers on site visits (loopback requests)
- Built-in to WordPress core
- Easier for shared hosting/managed hosts

---

#### **WCPS_Logger** (Comprehensive Logging)
- **Responsibility**: Record all operations for debugging and auditing
- **Log Location**: `logs/sync-YYYY-MM-DD.log`
- **Log Levels**: INFO, WARNING, ERROR, SUCCESS
- **Key Methods**:
  - `log()` - Generic logging method
  - `info()`, `warning()`, `error()`, `success()` - Convenience methods
  - `start_sync()`, `end_sync()` - Bookend sync sessions
  - `get_log_files()` - Retrieve log files

- **Log Format**:
  ```
  [2024-01-11 14:32:45] [INFO] Fetching JSON from URL | Context: {"url":"https://..."}
  [2024-01-11 14:32:48] [SUCCESS] JSON fetched successfully | Context: {"products_count":150}
  [2024-01-11 14:33:15] [INFO] Created product | Context: {"external_id":"123","product_id":456}
  [2024-01-11 14:33:20] [ERROR] Failed to update product | Context: {"external_id":"789","error":"..."}
  ```

- **Protection**: `.htaccess` in logs/ prevents web access to sensitive data

---

#### **WCPS_Admin_Settings** (User Interface)
- **Pattern**: Singleton
- **Responsibility**: Settings page and admin UI
- **Registered Settings**:
  - `wcps_json_url` - Source JSON URL
  - `wcps_sync_interval_value` - 1, 2, 3, etc.
  - `wcps_sync_interval_unit` - "minutes" or "hours"
  - `wcps_enabled` - "yes" or "no"
  - `wcps_delete_behavior` - "trash" or "delete"

- **Admin Interface**:
  - Settings tab - Configure sync parameters
  - Status tab - View last sync results, logs, download logs
  - Manual sync button - Trigger immediate sync
  - Real-time progress bar with percentage

- **Features**:
  - Uses WordPress Settings API
  - Integrates under WooCommerce menu
  - AJAX for real-time progress
  - Nonce security for AJAX requests
  - Respects user capabilities (`manage_woocommerce`)

---

### 2.3 Key Design Patterns Used

| Pattern | Where Used | Why |
|---------|-----------|-----|
| **Singleton** | WC_Product_Sync, WCPS_Cron, WCPS_Admin_Settings | Ensure single instance, prevent duplicate hooks |
| **Dependency Injection** | WCPS_Sync_Handler receives logger, fetcher, mapper, state_manager | Loose coupling, easy testing |
| **Factory Pattern** | `new WC_Product_Simple()`, `wc_get_product()` | Create objects based on type |
| **Observer (Hooks)** | WordPress hooks and filters | React to WordPress events |
| **Facade** | WCPS_Sync_Handler | Simplify complex subsystem interaction |
| **Repository** | WCPS_Product_Mapper `get_all_synced_products()` | Abstraction for data retrieval |

---

## 3. DETAILED WORKFLOW: THE SYNC PROCESS

### 3.1 Sync Lifecycle (Step by Step)

```
INITIALIZATION
├─ Check if sync is enabled
├─ Verify not already running
└─ Update progress: "starting" (0%)

DATA FETCHING
├─ Download JSON from URL
├─ Validate HTTP status (200 OK)
├─ Parse JSON
├─ Validate structure
└─ Update progress: "fetching" (5%)

STATE COMPARISON
├─ Load previous sync state
├─ Compare product hashes
├─ Identify field-level changes
└─ Update progress: "comparing" (10%)

PRODUCT PROCESSING (Per Product)
├─ Check if product exists
├─ IF EXISTS:
│  ├─ Compare hash with stored hash
│  ├─ IF CHANGED:
│  │  ├─ Detect field-level changes
│  │  ├─ Update product in WooCommerce
│  │  ├─ Update stats: updated++
│  │  └─ Log changes
│  │ ELSE:
│  │  └─ Skip, update stats: skipped++
│ ELSE (NEW PRODUCT):
│  ├─ Create new WooCommerce product
│  ├─ Set all properties
│  ├─ Store external ID as meta
│  ├─ Update stats: created++
│  └─ Log creation
└─ Update progress every 10 products (10%-80%)

CLEANUP
├─ Find products in WooCommerce but not in JSON
├─ Trash or delete based on behavior setting
├─ Update stats: deleted++
└─ Update progress: "cleanup" (85%)

POST-PROCESSING
├─ Link alternative products
├─ Update progress: "linking" (90%)
├─ Rotate state files (current → previous)
├─ Save new state in NDJSON format
└─ Update progress: "saving_state" (92%)

FINALIZATION
├─ Save last sync timestamp
├─ Save statistics to options
├─ Update progress: "complete" (100%)
├─ Log end of sync with stats
└─ Return statistics array
```

### 3.2 Hash-Based Change Detection

**Problem**: How to detect if a product changed without comparing all fields?

**Solution**: Product Hash

```php
// Calculate hash of JSON product
$hash = md5(json_encode($product_data));

// Store in database
update_post_meta($product_id, '_wcps_hash', $hash);

// On next sync, recalculate
$new_hash = md5(json_encode($product_data));

// Compare
if ($stored_hash === $new_hash) {
    // No changes, skip update
    $stats['skipped']++;
} else {
    // Changes detected, update
    $this->mapper->map_product($product_data, $product_id);
}
```

**Benefits**:
- O(1) comparison instead of field-by-field
- Handles unknown fields gracefully
- Single hashing vs. multiple equality checks
- Clear for developers reading logs

### 3.3 Field-Level Change Detection

**Additional Feature**: When product changes, log which fields changed

```php
// Load previous product from NDJSON file
$previous_product = $this->state_manager->get_previous_product($external_id);

// Compare field by field
$field_changes = $this->state_manager->compare_products(
    $previous_product,
    $current_product
);

// Log shows exactly what changed:
// "Name changed from 'Product A' to 'Product B'"
// "Price changed from 99.99 to 89.99"
// "Stock changed from 50 to 0"
```

---

## 4. DATA FLOW DIAGRAMS

### 4.1 Full Sync Flow

```
┌──────────────────┐
│ Trigger Sync     │
│ (Manual/Cron)    │
└────────┬─────────┘
         │
         ▼
┌──────────────────────────┐
│ WC_Product_Sync::        │
│ ajax_manual_sync()       │
│ or                       │
│ WCPS_Cron::              │
│ run_scheduled_sync()     │
└────────┬─────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│ WCPS_Sync_Handler::run_sync()       │
│                                     │
│ 1. Check if enabled                │
│ 2. Start logging                   │
└────────┬────────────────────────────┘
         │
         ▼
┌────────────────────────────┐        ┌──────────────────┐
│ WCPS_JSON_Fetcher::fetch() │───────▶│ Download JSON    │
│                            │        │ from URL         │
└────────┬───────────────────┘        └──────────────────┘
         │
         ▼
┌────────────────────────────┐        ┌──────────────────┐
│ WCPS_State_Manager::       │───────▶│ Load Previous    │
│ load_previous_state()      │        │ State Index      │
└────────┬───────────────────┘        └──────────────────┘
         │
         ▼
    ┌────────────────────────────────────┐
    │ For Each Product in JSON:          │
    │                                    │
    │ ┌─────────────────────────────┐   │
    │ │ Check if exists in WC       │   │
    │ │                             │   │
    │ │ ├─ If exists:               │   │
    │ │ │  ├─ Get stored hash       │   │
    │ │ │  ├─ Calculate new hash    │   │
    │ │ │  ├─ Compare hashes        │   │
    │ │ │  │  ├─ Match: SKIP        │   │
    │ │ │  │  └─ Different: UPDATE  │   │
    │ │ │  └─ Log field changes     │   │
    │ │ │                           │   │
    │ │ └─ If new:                  │   │
    │ │    ├─ Create WC Product    │   │
    │ │    ├─ Set all properties   │   │
    │ │    └─ Store metadata       │   │
    │ │                             │   │
    │ └─────────────────────────────┘   │
    │                                    │
    │ Update stats, log, update progress│
    └────────────────────────────────────┘
         │
         ▼
┌────────────────────────────┐        ┌──────────────────┐
│ WCPS_Product_Mapper::      │───────▶│ Link Alternative │
│ link_alternative_products()│        │ Products         │
└────────┬───────────────────┘        └──────────────────┘
         │
         ▼
┌────────────────────────────┐        ┌──────────────────┐
│ WCPS_State_Manager::       │───────▶│ Save as NDJSON   │
│ rotate_state() + save_state│        │ for next sync    │
└────────┬───────────────────┘        └──────────────────┘
         │
         ▼
┌──────────────────────────────┐
│ Update last sync timestamp   │
│ Save statistics              │
│ Update progress: 100%        │
│ Return stats array           │
└──────────────────────────────┘
```

### 4.2 Cron Scheduling Flow

```
┌─────────────────────────────────────────┐
│ Admin: Enable Sync in Settings          │
│ Check: wcps_enabled = "yes"             │
└──────────────┬──────────────────────────┘
               │
               ▼
┌──────────────────────────────────────────┐
│ Fires: update_option_wcps_enabled        │
│ Hook: handle_enabled_change()            │
└──────────────┬───────────────────────────┘
               │
               ▼
┌────────────────────────────────────────────────────┐
│ WCPS_Cron::schedule_sync()                         │
│                                                    │
│ 1. Unschedule any existing event                  │
│ 2. Calculate interval in seconds                  │
│ 3. wp_schedule_event(time(),                      │
│    'wcps_custom_interval',                        │
│    'wcps_sync_cron')                              │
│ 4. Log scheduling                                 │
└────────────────┬─────────────────────────────────┘
                 │
                 ▼
        ┌────────────────────────┐
        │ WP-Cron Table Updated  │
        │ Next run: now + 3600s  │
        └────────────────────────┘
                 │
         ┌───────┴────────┐
         │                │
    After X hours...  (Every page load)
    WP checks cron queue
         │
         ▼
    Is event due?
    YES ──▶ Execute action 'wcps_sync_cron'
         │
         ▼
    WCPS_Cron::run_scheduled_sync()
         │
         ├─ Check if sync enabled
         ├─ Check if not already running
         ├─ Run WCPS_Sync_Handler::run_sync()
         └─ Log result
```

---

## 5. DATABASE & STORAGE ARCHITECTURE

### 5.1 Product Metadata

Each synced product stores additional metadata:

```php
// In wp_postmeta table:
meta_key                 meta_value
─────────────────────────────────────
_external_id             "remote-prod-123"
_wcps_synced             "yes"
_wcps_last_sync          "2024-01-11 14:33:20"
_wcps_hash               "abc123def456..."
_producer_code           "PROD-ABC"
_wcps_alternative_ids    "id1,id2,id3"
```

### 5.2 Options (Settings)

WordPress options table stores config:

```php
option_name                  option_value
────────────────────────────────────────────
wcps_enabled                 "yes"
wcps_json_url                "https://..."
wcps_sync_interval_value     "1"
wcps_sync_interval_unit      "hours"
wcps_delete_behavior         "trash"
wcps_last_sync               "2024-01-11 14:33:20"
wcps_last_sync_stats         {"created":5,"updated":12,...}
wcps_last_sync_status        {"status":"success","message":"..."}
wcps_sync_running            "1" (during sync)
wcps_sync_progress           {"status":"processing","progress":45}
```

### 5.3 File Storage Structure

```
wc-product-sync/
├── logs/
│   ├── .htaccess (blocks web access)
│   ├── index.php (security)
│   ├── sync-2024-01-11.log
│   ├── sync-2024-01-10.log
│   └── ...
│
├── cache/
│   ├── .htaccess
│   ├── index.php
│   ├── last-sync.ndjson (current state - streaming format)
│   ├── last-sync.index (lookup index)
│   ├── previous-sync.ndjson (previous state)
│   └── previous-sync.index
```

**Why .htaccess and index.php?**
- `.htaccess`: Prevents web access to sensitive logs
- `index.php`: Blocks directory listing

---

## 6. ADVANCED FEATURES EXPLAINED

### 6.1 Category Hierarchy Creation

**Problem**: JSON has "Electronics - Phones - Smartphones" but WooCommerce needs separate category hierarchy

**Solution**: Parse and create hierarchically

```php
$category_string = "Electronics - Phones - Smartphones";
$parts = explode(' - ', $category_string);
// Result: ["Electronics", "Phones", "Smartphones"]

$parent_id = 0;
foreach ($parts as $name) {
    // Check if exists with correct parent
    $term = get_term_by('name', $name, 'product_cat');
    
    if ($term && $term->parent == $parent_id) {
        $category_ids[] = $term->term_id;
        $parent_id = $term->term_id; // This is new parent for next level
    } else {
        // Create new with correct parent
        $result = wp_insert_term($name, 'product_cat', ['parent' => $parent_id]);
        $category_ids[] = $result['term_id'];
        $parent_id = $result['term_id'];
    }
}
```

**Result**:
```
Electronics (parent: 0)
└── Phones (parent: Electronics)
    └── Smartphones (parent: Phones)
```

### 6.2 Product Attributes (Features → WC Attributes)

**Problem**: JSON has features but WooCommerce uses global attributes (pa_*)

**Solution**: Create global attributes with terms

```json
// JSON Features
"Features": [
  {
    "Name": "Color",
    "Value": [
      {"Name": "Red"},
      {"Name": "Blue"},
      {"Name": "Green"}
    ]
  }
]
```

**Converted to**:
- Global Attribute: `pa_color` (slug)
- Terms: Red, Blue, Green
- Product linked to these terms
- Filterable in storefront

**Benefits**:
- Available for all products
- Enables attribute filtering
- Variations (if needed)
- Standard WC architecture

### 6.3 Smart Image Handling

**Features**:
- Downloads images from URLs
- Creates media library entries
- Sets featured image (first photo)
- Adds gallery images (remaining photos)
- Handles download failures gracefully
- Stores as media attachments

```php
// From JSON: ["https://..../img1.jpg", "https://..../img2.jpg"]
// Becomes: WC product with gallery
if (!empty($json_product['Photos'])) {
    $this->handle_photos($product_id, $json_product['Photos']);
}
```

### 6.4 Product Relationships (Alternative Products)

**Use Case**: "People who viewed this also viewed..."

```json
// JSON
"AlternativeProducts": ["product-id-2", "product-id-3"]
```

**Processing**:
1. Store external IDs temporarily during sync
2. After all products created, link them
3. Creates cross-sell relationships in WooCommerce

---

## 7. SECURITY & BEST PRACTICES

### 7.1 Security Measures

| Feature | Implementation | Why |
|---------|----------------|-----|
| **Admin Capability Check** | `current_user_can('manage_woocommerce')` | Only admins can trigger sync |
| **Nonce Verification** | `check_ajax_referer('wcps_admin_nonce')` | Prevents CSRF attacks |
| **URL Sanitization** | `esc_url_raw()` on JSON URL | Prevents malformed URLs |
| **JSON Escape** | `JSON_UNESCAPED_UNICODE` but safe | Proper encoding |
| **Log File Protection** | `.htaccess` denies web access | Logs not publicly readable |
| **Cache Protection** | `.htaccess` denies web access | State files not accessible |
| **Error Messages** | Generic messages to users, detailed in logs | Prevents info leakage |
| **SSL Verification** | `'sslverify' => true` in wp_remote_get | Validates HTTPS certs |

### 7.2 Code Standards

- **Escaping**: Uses `esc_html()`, `esc_url_raw()`, `wp_json_encode()`
- **Prefixes**: All functions, options, hooks use `wcps_` prefix
- **Sanitization**: Input validated with `sanitize_text_field()`, `absint()`
- **Nonces**: AJAX requests protected with WordPress nonces
- **Permissions**: Uses WordPress capabilities system
- **Error Handling**: WP_Error pattern used throughout
- **Documentation**: PHPDoc comments on all classes/methods

### 7.3 Performance Optimizations

| Optimization | How |
|------------|-----|
| **Caching** | Category/attribute cache during sync |
| **Batch Processing** | Updates UI every 10 products, not every product |
| **NDJSON Streaming** | Doesn't load entire state to memory |
| **Index Files** | Fast product lookup in state without scanning entire file |
| **Hash Comparison** | Single hash check instead of field-by-field |
| **Batch Size** | 50-product batches for processing |
| **Progress Updates** | Only updates options every 10 products |
| **Extended Timeout** | 120 second timeout for large JSON downloads |

---

## 8. HOW TO BUILD SIMILAR PLUGINS

### 8.1 Plugin Architecture Template

When building data synchronization plugins, follow this structure:

```
your-plugin/
├── your-plugin.php              # Main bootstrap file
├── includes/
│   ├── class-main-handler.php   # Orchestrator (Dependency Injection)
│   ├── class-data-fetcher.php   # External API/data source
│   ├── class-data-mapper.php    # Transform external → internal
│   ├── class-state-manager.php  # Track previous state
│   ├── class-logger.php         # Comprehensive logging
│   ├── class-cron-handler.php   # Schedule recurring tasks
│   └── class-admin-ui.php       # Settings interface
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── logs/
└── cache/
```

### 8.2 Step-by-Step Development Guide

#### **Step 1: Bootstrap & Singleton**
```php
final class Your_Plugin {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }
}
```

#### **Step 2: Dependency Injection**
```php
class Your_Sync_Handler {
    private $logger;
    private $fetcher;
    private $mapper;
    
    public function __construct() {
        $this->logger = new Your_Logger();
        $this->fetcher = new Your_Fetcher();
        $this->mapper = new Your_Mapper();
    }
    
    // Now use $this->logger, $this->fetcher, $this->mapper
    // Easy to test: pass mock objects instead
}
```

#### **Step 3: Main Workflow**
```php
public function run_sync() {
    // 1. Validate state
    if (!$this->is_enabled()) return;
    
    // 2. Fetch data
    $data = $this->fetcher->fetch();
    if (is_wp_error($data)) return $data;
    
    // 3. Compare with previous
    $changes = $this->state_manager->detect_changes($data);
    
    // 4. Process each item
    foreach ($data as $id => $item) {
        $this->process_item($id, $item);
    }
    
    // 5. Persist new state
    $this->state_manager->save_state($data);
    
    // 6. Return stats
    return $this->stats;
}
```

#### **Step 4: Comprehensive Logging**
```php
class Your_Logger {
    private $log_file;
    
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/logs/' . 
            'sync-' . date('Y-m-d') . '.log';
    }
    
    public function log($message, $level = 'info', $context = []) {
        $entry = "[" . date('Y-m-d H:i:s') . "] [$level] $message";
        if ($context) {
            $entry .= " | " . json_encode($context);
        }
        file_put_contents($this->log_file, $entry . "\n", FILE_APPEND);
    }
}
```

#### **Step 5: Progress Tracking**
```php
private function update_progress($status, $message, $percent) {
    update_option('your_sync_progress', [
        'status' => $status,
        'message' => $message,
        'progress' => $percent,
        'timestamp' => current_time('mysql'),
    ]);
}
```

#### **Step 6: AJAX + Cron**
```php
// Manual trigger via AJAX
add_action('wp_ajax_your_sync_trigger', function() {
    check_ajax_referer('your_nonce');
    if (!current_user_can('manage_options')) wp_die();
    
    $handler = new Your_Sync_Handler();
    $result = $handler->run_sync();
    wp_send_json_success($result);
});

// Automatic trigger via Cron
add_action('your_sync_cron', function() {
    $handler = new Your_Sync_Handler();
    $handler->run_sync();
});

// Schedule on first enable
wp_schedule_event(time(), 'hourly', 'your_sync_cron');
```

#### **Step 7: Admin Settings**
```php
add_action('admin_init', function() {
    register_setting('your_settings', 'your_data_url');
    register_setting('your_settings', 'your_sync_enabled');
    // ... more settings
});
```

### 8.3 Key Principles to Follow

1. **Single Responsibility**: Each class does ONE thing
2. **Dependency Injection**: Pass dependencies, don't create them inside
3. **Error Handling**: Use WP_Error pattern, always check
4. **Logging**: Log EVERYTHING for debugging
5. **Progress Tracking**: Long operations need progress updates
6. **State Management**: Remember previous state for efficiency
7. **Security First**: Sanitize, escape, validate, check capabilities
8. **Caching**: Cache expensive operations (API calls, database queries)
9. **Batch Processing**: Don't process items one at a time
10. **Testing**: Each class should be independently testable

---

## 9. EXTENDING THE PLUGIN

### 9.1 Custom Hooks & Filters

The plugin provides hooks for extension:

```php
// Before product mapping
do_action('wcps_before_product_map', $json_product, $existing_product_id);

// After product created
do_action('wcps_product_created', $product_id, $json_product);

// After product updated
do_action('wcps_product_updated', $product_id, $json_product);

// Before deletion
do_action('wcps_before_product_delete', $product_id, $external_id);

// Filter JSON data
$json_data = apply_filters('wcps_json_data', $json_data);
```

**Example Usage**:
```php
// Add custom field from JSON
add_action('wcps_product_created', function($product_id, $json) {
    if (!empty($json['CustomField'])) {
        update_post_meta($product_id, '_custom_field', $json['CustomField']);
    }
}, 10, 2);
```

### 9.2 Customization Points

- **Product Mapping**: Extend `WCPS_Product_Mapper` to add more fields
- **Fetching**: Extend `WCPS_JSON_Fetcher` to support different data sources (CSV, XML, API)
- **Attributes**: Add custom attribute creation logic
- **Categories**: Override category hierarchy logic
- **Images**: Modify image downloading/attachment logic
- **State Management**: Replace NDJSON with database storage if preferred

---

## 10. COMMON ISSUES & DEBUGGING

### 10.1 Check the Logs

First place to look:
```
wp-content/plugins/wc-product-sync/logs/sync-2024-01-11.log
```

Log contains all operations, errors, and field changes.

### 10.2 Common Issues

| Issue | Cause | Fix |
|-------|-------|-----|
| Sync not running | Cron disabled | Check if WP-Cron works: `wp cron test` |
| Products not updating | Hash matches | Product data in JSON hasn't changed |
| Categories not created | Permission issue | Check category creation permissions |
| Images not downloading | Timeout | Increase timeout or check HTTPS |
| Memory exhausted | Too many products | Check batch size, increase PHP memory |
| Attributes not showing | Taxonomy limit | Check WC attribute settings |

### 10.3 Debugging Commands

```bash
# Check WordPress Cron
wp cron test

# Check scheduled events
wp cron event list

# Check logs
tail -f wp-content/plugins/wc-product-sync/logs/sync-*.log

# Force sync via CLI
wp wc-product-sync run

# Check last sync stats
wp option get wcps_last_sync_stats
```

---

## 11. COMPARISON WITH ALTERNATIVES

| Feature | WC Product Sync | WooCommerce REST API | Custom Solution |
|---------|-----------------|---------------------|-----------------|
| **Setup Time** | 5 minutes | 1-2 hours | 1-2 weeks |
| **Maintenance** | Minimal | Updates with WC | Ongoing |
| **Features** | Complete | Basic | Custom |
| **Error Handling** | Excellent | Good | Depends |
| **Logging** | Comprehensive | Limited | Depends |
| **Progress Tracking** | Yes | No | Maybe |
| **State Management** | NDJSON streaming | None | Maybe |
| **Cost** | Free | Free | Dev time |

---

## 12. PRODUCTION DEPLOYMENT CHECKLIST

- [ ] Test with 1,000+ products
- [ ] Monitor server during scheduled sync
- [ ] Verify HTTPS on JSON URL
- [ ] Check logs daily for first week
- [ ] Set up automated log cleanup
- [ ] Backup database before enabling
- [ ] Test delete behavior (trash vs. delete)
- [ ] Verify image downloads work
- [ ] Test with different JSON structures
- [ ] Document JSON format for your use case
- [ ] Set appropriate sync interval
- [ ] Monitor server resources (CPU, memory, disk)

---

## 13. CONCLUSION

WC Product Sync is an **enterprise-grade plugin** that demonstrates professional WordPress/WooCommerce development:

### Strengths
✅ Clean architecture with clear separation of concerns
✅ Comprehensive error handling and logging
✅ Efficient state management with streaming data
✅ Security-first approach with proper escaping/sanitization
✅ User-friendly admin interface with progress tracking
✅ Extensible design allowing customization
✅ Production-ready with extensive testing scenarios
✅ Well-documented code with clear class responsibilities

### Perfect For Learning
- **Beginners**: Clear class structure, easy to understand flow
- **Intermediates**: Advanced patterns (DI, NDJSON streaming, state management)
- **Experts**: Scalability considerations, performance optimizations

### Use This As Template For
- Any sync/import plugin (CSV, XML, API, Database)
- Scheduled background jobs
- Large data processing tasks
- Admin tools with progress tracking
- Multi-step operations with logging

---

## Appendix: File Structure Summary

```
WC_Product_Sync (Main) - Bootstrap & singleton pattern
├── Initialization & hooks
├── Dependency checking
└── Component coordination
    │
    ├── WCPS_Sync_Handler - Orchestrator
    │   └── Coordinates all operations
    │
    ├── WCPS_JSON_Fetcher - Data Source
    │   └── Fetches & validates JSON
    │
    ├── WCPS_Product_Mapper - Data Transformation
    │   ├── Creates WC products
    │   ├── Manages categories
    │   ├── Creates attributes
    │   └── Handles images
    │
    ├── WCPS_State_Manager - State Persistence
    │   ├── NDJSON streaming
    │   ├── Fast lookup indexes
    │   └── Change detection
    │
    ├── WCPS_Logger - Audit Trail
    │   └── Daily log files
    │
    ├── WCPS_Cron - Scheduling
    │   └── WordPress Cron integration
    │
    └── WCPS_Admin_Settings - UI
        ├── Settings registration
        └── Admin interface
```

---

**Total Lines of Code**: ~2,500 lines (well-organized, not bloated)
**Classes**: 7 focused classes
**Patterns Used**: 6+ design patterns
**Production Ready**: Yes
**Complexity Level**: Intermediate to Advanced
**Learning Value**: Excellent for professional plugin development

