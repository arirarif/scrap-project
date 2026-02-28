# SEV → WooCommerce Scraper: Full Engineering Roadmap
**Target:** sev-stromerzeuger.com → Your WooCommerce via `wc-product-sync` plugin
**Analysis date:** 2026-02-27
**Status:** Pre-build planning

---

## 1. ARCHITECTURE OVERVIEW

```
[SEV Shopify JSON API]
        │
        ▼
[Python Scraper]          ← runs daily (cron / Task Scheduler)
   ├─ fetches all pages   ← GET /collections/all/products.json?limit=250&page=N
   ├─ parses body_html    ← BeautifulSoup → spec tables → Features[]
   ├─ maps fields         ← Shopify format → plugin JSON contract
   └─ writes output
        │
        ▼
[products.json]           ← hosted at a public URL your server
        │
        ▼
[wc-product-sync plugin]  ← reads URL, runs batch sync (50/batch)
   ├─ create new products
   ├─ update changed products  (MD5 hash comparison per product)
   ├─ delete removed products  (trash or permanent — your choice)
   └─ download images          (sideloads to WP media library)
        │
        ▼
[WooCommerce Products]
```

---

## 2. PLUGIN JSON CONTRACT (EXACT FORMAT REQUIRED)

After reading all plugin source code, here is the **exact structure** the plugin expects.

### 2a. Top-level: Dictionary keyed by product ID (NOT an array)

```json
{
  "PRODUCT_ID_1": { ...product object... },
  "PRODUCT_ID_2": { ...product object... }
}
```

> **Critical:** The plugin iterates as `foreach ($json_data as $external_id => $product_data)`.
> If you send an array `[{...}, {...}]` it will break. Must be a dictionary.

### 2b. Product Object (all fields)

```json
{
  "Id": "5642318798989",
  "Name": "Tragbarer Stromerzeuger ENDRESS ESE 6000 DBS",
  "Producer": "Endress",
  "ProducerCode": "SEV-ESE6000DBS",
  "Price": 1017.45,
  "Category": "Stromerzeuger - Portable Generators",
  "Stock": 1,
  "Photos": [
    "https://cdn.shopify.com/s/files/1/product_img1.jpg",
    "https://cdn.shopify.com/s/files/1/product_img2.jpg"
  ],
  "Features": [
    {
      "Id": "fuel_type",
      "Name": "Fuel Type",
      "Value": [{ "Name": "Benzin" }]
    },
    {
      "Id": "max_power",
      "Name": "Max Power Output",
      "Value": [{ "Name": "5.5 kVA" }]
    },
    {
      "Id": "phase",
      "Name": "Phase",
      "Value": [{ "Name": "Single Phase" }]
    }
  ],
  "AlternativeProducts": [],
  "AlternativeProductIds": []
}
```

### 2c. What each field does in the plugin

| Plugin Field | WooCommerce Effect | Notes |
|---|---|---|
| `Id` (key + field) | `_external_id` meta + SKU | Used for all create/update/delete matching |
| `Name` | Product title | Producer prepended if not already in name |
| `Producer` | WC attribute "Producer" | Also shown as product attribute |
| `ProducerCode` | `_producer_code` meta | Stored but not displayed by default |
| `Price` | Regular price | Float. No sale/compare price field |
| `Stock` | Stock quantity | Plugin enforces minimum 1 even if you pass 0 |
| `Category` | WC categories | `" - "` delimiter = hierarchy. Auto-created |
| `Photos[]` | Featured + gallery images | Sideloaded to WP media. Cached by URL |
| `Features[]` | WC product attributes | Each Feature → global attribute taxonomy |
| `AlternativeProducts` | Cross-sells + frontend buttons | Optional. Empty array = fine |
| `AlternativeProductIds` | Cross-sell linking | Optional. Empty array = fine |

### 2d. Plugin DOES NOT support (known gaps)

| Missing Field | Impact | Workaround |
|---|---|---|
| No `Description` | Long description lost | Could add to plugin later (minor code change) |
| No `ShortDescription` | Short desc lost | Same — not in plugin today |
| No `CompareAtPrice` | Sale price lost | Only regular price imported |
| No `GTIN` / barcode | EAN-13 lost | Store as Feature instead |
| No `DeliveryTime` | Not shown | Store as Feature instead |
| No `Tags` | WC tags not set | Can encode in Category or Feature |
| No SEO meta | Meta title/desc lost | Set manually or via Rank Math after import |

---

## 3. SHOPIFY API → PLUGIN FIELD MAPPING

### 3a. Shopify API endpoint structure

```
GET https://sev-stromerzeuger.com/collections/all/products.json?limit=250&page=1
```

Returns:
```json
{
  "products": [
    {
      "id": 5642318798989,
      "title": "Tragbarer Stromerzeuger ENDRESS ESE 6000 DBS",
      "vendor": "Endress",
      "product_type": "Benzin Generator",
      "handle": "endress-ese-6000-dbs",
      "body_html": "<table>...</table>",
      "images": [
        { "src": "https://cdn.shopify.com/..." },
        { "src": "https://cdn.shopify.com/..." }
      ],
      "variants": [
        {
          "sku": "SEV-00167",
          "price": "1017.45",
          "compare_at_price": "1094.80",
          "inventory_quantity": 0,
          "title": "Default Title"
        }
      ],
      "tags": "brand_endress, diesel, 3-phase"
    }
  ]
}
```

### 3b. Field-by-field translation table

| Shopify field | Plugin field | Transformation |
|---|---|---|
| `product.id` (int) | Dict key + `Id` | Convert to string: `str(product['id'])` |
| `product.title` | `Name` | Direct — strip extra whitespace |
| `product.vendor` | `Producer` | Direct |
| `product.variants[0].sku` | `ProducerCode` | Use first variant SKU; fallback to `handle` |
| `product.variants[0].price` | `Price` | Cast to float |
| `product.variants[0].compare_at_price` | ❌ Not supported | Skip (plugin has no sale price field) |
| `product.variants[0].inventory_quantity` | `Stock` | See note below |
| `product.images[].src` | `Photos[]` | Array of URL strings |
| Parsed from `product.body_html` | `Features[]` | BeautifulSoup HTML table parsing |
| `product.product_type` + collection | `Category` | See category strategy below |
| — | `AlternativeProducts` | Always `[]` |
| — | `AlternativeProductIds` | Always `[]` |

### 3c. Stock quantity decision

Shopify's public `/products.json` API returns `inventory_quantity` in variants, but for
many stores it either returns 0 (hidden) or the real number. Since this is a competitor
site (no auth), you cannot rely on it. **Recommended approach:** always pass `Stock: 1`.

The plugin already enforces `max(stock, 1)` so a product is always shown as "In Stock".
This is acceptable for a catalog-style shop. If stock accuracy matters later, this would
require a more complex scraping approach (not possible without Shopify API access).

### 3d. Category building strategy

The SEV site has a 3-level category structure. Shopify's `product_type` gives the leaf.
The collection hierarchy gives the parent. **Recommended approach:**

```python
# Predefined mapping: product_type → Category string for plugin
CATEGORY_MAP = {
    "Benzin Generator":      "Stromerzeuger - Portable Generators",
    "Inverter Generator":    "Stromerzeuger - Inverter Generators",
    "Notstromaggregat":      "Stromerzeuger - Emergency Power",
    "Zapfwellenaggregat":    "Stromerzeuger - PTO Generators",
    "Lichtmast":             "Stromerzeuger - Light Towers",
    "Schweißgenerator":      "Stromerzeuger - Welding Generators",
    "Gas Generator":         "Stromerzeuger - Gas Generators",
    "Hybrid Generator":      "Stromerzeuger - Hybrid Generators",
    "Motorpumpe":            "Stromerzeuger - Motor Pumps",
    "Energiespeicher":       "Energy Storage - Commercial",
    "Solarspeicher":         "Energy Storage - Solar Storage",
    "Powerstation":          "Energy Storage - Portable Power Stations",
    "Balkonkraftwerk":       "Energy Storage - Balcony Solar",
    "Zubehör":               "Accessories",
    "Tanks & Kanister":      "Accessories - Tanks & Canisters",
    # ... add all types seen during scraping
}

# Fallback: if not in map, use product_type directly
category = CATEGORY_MAP.get(product_type, product_type)
```

Plugin will auto-create the hierarchy: `"Stromerzeuger - Portable Generators"` creates
`Stromerzeuger` (parent) and `Portable Generators` (child) automatically.

### 3e. Features/specs parsing from body_html

Shopify `body_html` contains HTML spec tables like:
```html
<table>
  <tbody>
    <tr><td>Nennleistung (kVA)</td><td>5.5 kVA</td></tr>
    <tr><td>Kraftstoff</td><td>Benzin</td></tr>
    <tr><td>Motormarke</td><td>Honda</td></tr>
  </tbody>
</table>
```

Python parser logic:
```python
from bs4 import BeautifulSoup

def parse_features(body_html: str) -> list:
    features = []
    if not body_html:
        return features

    soup = BeautifulSoup(body_html, "html.parser")

    for table in soup.find_all("table"):
        for row in table.find_all("tr"):
            cells = row.find_all(["td", "th"])
            if len(cells) >= 2:
                name = cells[0].get_text(strip=True)
                value = cells[1].get_text(strip=True)

                if name and value:
                    # Feature Id: slugified name (for deduplication)
                    feature_id = name.lower().replace(" ", "_")[:40]
                    features.append({
                        "Id": feature_id,
                        "Name": name,
                        "Value": [{"Name": value}]
                    })

    return features
```

> **Important:** WooCommerce attribute slug limit is **28 characters**.
> The plugin handles truncation automatically in `truncate_slug()`.

---

## 4. PYTHON SCRAPER DESIGN

### 4a. Project structure

```
sev-scraper/
├── scraper.py          ← main entry point
├── fetcher.py          ← Shopify API pagination
├── transformer.py      ← Shopify → plugin JSON mapping
├── html_parser.py      ← body_html spec table parsing
├── category_map.py     ← product_type → WC category mapping
├── uploader.py         ← upload JSON to server (FTP/SFTP/S3)
├── config.py           ← settings (URL, paths, credentials)
├── requirements.txt    ← beautifulsoup4, requests, lxml
└── output/
    └── products.json   ← generated output file
```

### 4b. Scraper execution flow

```python
# scraper.py — high-level flow

def run():
    # 1. Fetch all products from Shopify API
    all_products = fetch_all_products()   # paginated, 250/page
    # Result: list of ~1068 raw Shopify product dicts

    # 2. Transform each product to plugin format
    plugin_json = {}
    for product in all_products:
        external_id = str(product['id'])
        plugin_json[external_id] = transform_product(product)
    # Result: { "5642318798989": {...}, "5642318798990": {...} }

    # 3. Write to output file
    with open('output/products.json', 'w', encoding='utf-8') as f:
        json.dump(plugin_json, f, ensure_ascii=False, indent=2)

    # 4. Upload to your server (the URL the plugin reads from)
    upload_to_server('output/products.json')

    print(f"Done: {len(plugin_json)} products exported")
```

### 4c. Pagination logic

```python
def fetch_all_products() -> list:
    all_products = []
    page = 1

    while True:
        url = f"https://sev-stromerzeuger.com/collections/all/products.json"
        params = {"limit": 250, "page": page}

        resp = requests.get(url, params=params, timeout=30)
        resp.raise_for_status()

        data = resp.json()
        products = data.get("products", [])

        if not products:
            break  # no more pages

        all_products.extend(products)
        print(f"Page {page}: fetched {len(products)} products")

        if len(products) < 250:
            break  # last page

        page += 1
        time.sleep(0.5)  # polite delay between pages

    return all_products
```

### 4d. Dependencies

```
requests>=2.31
beautifulsoup4>=4.12
lxml>=4.9
```

---

## 5. HOSTING THE JSON FILE

The plugin needs a **public HTTPS URL** to fetch the JSON from.

### Option A: Host on WordPress server itself (Recommended)

Upload `products.json` directly to your WordPress server via SFTP/FTP:

```
/wp-content/uploads/sync/products.json
```

Access URL: `https://yoursite.com/wp-content/uploads/sync/products.json`

- Free — uses your existing hosting
- Plugin fetches from same server (fast)
- Scraper uploads via SFTP after generating the file

### Option B: Host on separate VPS / object storage

- Upload to DigitalOcean Spaces, AWS S3, or Cloudflare R2
- Good if your WordPress hosting has upload size limits
- More reliable for large files (1068 products ≈ 3–5MB JSON)

### Option C: Serve via PHP endpoint on WordPress

Create a small PHP file that generates/serves the JSON:

```
/wp-content/uploads/sync/products.php
```

This adds complexity — Option A is preferred.

---

## 6. RUNNING THE SCRAPER DAILY

### Option A: Windows Task Scheduler (if running on Windows PC)

```
Action: Run Python
Program: C:\Python311\python.exe
Arguments: C:\path\to\sev-scraper\scraper.py
Trigger: Daily at 03:00 AM
```

### Option B: Linux cron (if on VPS or shared hosting with SSH)

```bash
# Run scraper daily at 3am
0 3 * * * /usr/bin/python3 /home/user/sev-scraper/scraper.py >> /home/user/logs/scraper.log 2>&1
```

### Option C: GitHub Actions (free, no server needed)

```yaml
# .github/workflows/scrape.yml
name: Daily SEV Scrape

on:
  schedule:
    - cron: '0 3 * * *'  # 3am UTC daily

jobs:
  scrape:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: pip install requests beautifulsoup4 lxml
      - run: python scraper.py
      - name: Upload to server via SFTP
        run: sftp user@yourserver.com <<< "put output/products.json /wp-content/uploads/sync/"
```

---

## 7. PLUGIN CONFIGURATION CHECKLIST

After scraper runs and JSON is hosted, configure plugin at:
`WooCommerce → Product Sync`

| Setting | Value |
|---|---|
| Enable Sync | ✅ Yes |
| JSON URL | `https://yoursite.com/wp-content/uploads/sync/products.json` |
| Sync Interval | `24 hours` (or 12 hours for more frequent) |
| Delete Behavior | `Move to Trash` (safe — can recover) |

---

## 8. KNOWN RISKS & MITIGATIONS

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Shopify rate limiting on scraper | Low | Medium | 0.5s delay between pages; 5 pages total = negligible |
| SEV blocks scraper IP | Low | High | Use residential proxy if needed; 5 pages/day is very light |
| `body_html` spec table structure changes | Medium | Low | Features gracefully degrade to empty if parse fails |
| Plugin JSON URL times out (large file) | Medium | Medium | Plugin has 120s timeout; file ~3-5MB should be fine |
| WordPress memory exhaustion on sync | Low | Medium | Plugin already sets `memory_limit = 512M` during sync |
| Images fail to download (CDN blocks WP) | Low | Low | Plugin skips failed images; products still created |
| `product_type` not in CATEGORY_MAP | Medium | Low | Fallback uses raw `product_type` string as category |
| Shopify pagination changes | Low | High | Monitor on first run; add cursor-based pagination if needed |
| Stock always shown as "In Stock" | High (intentional) | Low | Plugin enforces min stock=1; acceptable for catalog |

---

## 9. SPRINT PLAN

### Sprint 1 — Core Scraper (2-3 days)
- [ ] Set up Python project structure
- [ ] Write `fetcher.py` — paginated Shopify API fetch
- [ ] Write `transformer.py` — field mapping (no HTML parsing yet)
- [ ] Test with 1 page (250 products), validate output JSON against plugin contract
- [ ] Verify plugin accepts output: manual import test with 10 products

### Sprint 2 — Spec Parsing & Category Map (1-2 days)
- [ ] Write `html_parser.py` — BeautifulSoup spec table → Features[]
- [ ] Build `category_map.py` — survey all `product_type` values, create full map
- [ ] Re-test full import with Features and Categories correct
- [ ] Check WooCommerce attribute slugs don't exceed 28 chars

### Sprint 3 — Full Run & Hosting (1 day)
- [ ] Run scraper on all 1,068 products
- [ ] Validate output JSON structure (count, spot-check 10 products)
- [ ] Upload JSON to WordPress server
- [ ] Run full sync through plugin — verify stats (created/updated/deleted/errors)
- [ ] Spot-check 20 products in WooCommerce (images, prices, specs, categories)

### Sprint 4 — Automation & Monitoring (1 day)
- [ ] Set up daily cron / Task Scheduler / GitHub Actions
- [ ] Add upload step (SFTP/FTP to server)
- [ ] Add basic error alerting (email if scraper fails)
- [ ] Set plugin to sync every 24 hours
- [ ] Test end-to-end: modify a product on SEV → confirm WC updates next day
- [ ] Test deletion: confirm removed SEV products go to WC trash

### Sprint 5 — Gaps & Polish (optional, 1-2 days)
- [ ] Add `Description` support to plugin (minor PHP code change in mapper)
- [ ] Add `CompareAtPrice` support to plugin (sale price field)
- [ ] Add GTIN as a Feature for EAN barcode
- [ ] Add delivery time parsing from body_html

---

## 10. WHAT TO BUILD FIRST (RECOMMENDED ORDER)

```
Week 1:
  Day 1: Build fetcher.py — just get all products from Shopify API
  Day 1: Build basic transformer.py — no HTML parsing, just main fields
  Day 2: Test plugin import with 10 products manually
  Day 2: Fix any JSON structure issues, verify categories/attributes
  Day 3: Add html_parser.py for spec Features
  Day 3: Build full category_map.py

Week 2:
  Day 1: Full 1068-product run + plugin sync
  Day 1: QA — spot-check products, fix issues
  Day 2: Set up automation (cron + SFTP upload)
  Day 2: Final end-to-end test (scrape → JSON → sync → WooCommerce)
```

---

## 11. QUICK REFERENCE: MINIMAL VALID JSON EXAMPLE

The absolute minimum the plugin accepts (will validate OK):

```json
{
  "12345": {
    "Id": "12345",
    "Name": "Test Generator 5000W",
    "Price": 999.00
  }
}
```

A realistic SEV product entry:

```json
{
  "5642318798989": {
    "Id": "5642318798989",
    "Name": "Tragbarer Stromerzeuger ENDRESS ESE 6000 DBS",
    "Producer": "Endress",
    "ProducerCode": "SEV-00167",
    "Price": 1017.45,
    "Category": "Stromerzeuger - Portable Generators",
    "Stock": 1,
    "Photos": [
      "https://cdn.shopify.com/s/files/1/0523/img1.jpg",
      "https://cdn.shopify.com/s/files/1/0523/img2.jpg"
    ],
    "Features": [
      { "Id": "fuel_type",     "Name": "Fuel Type",         "Value": [{"Name": "Benzin"}] },
      { "Id": "max_power",     "Name": "Max Power (kVA)",   "Value": [{"Name": "5.5 kVA"}] },
      { "Id": "phase",         "Name": "Phase",             "Value": [{"Name": "Single Phase"}] },
      { "Id": "engine_brand",  "Name": "Engine Brand",      "Value": [{"Name": "Honda"}] },
      { "Id": "start_system",  "Name": "Start System",      "Value": [{"Name": "Electric Start"}] },
      { "Id": "noise_level",   "Name": "Noise Level",       "Value": [{"Name": "68 dB(A)"}] },
      { "Id": "tank_capacity", "Name": "Tank Capacity",     "Value": [{"Name": "25 L"}] },
      { "Id": "weight",        "Name": "Weight",            "Value": [{"Name": "95 kg"}] }
    ],
    "AlternativeProducts": [],
    "AlternativeProductIds": []
  }
}
```

---

*Roadmap version: 1.0 — 2026-02-27*
*Based on: full source code analysis of wc-product-sync plugin + productjsonformat.md*
*Plugin author: Mahfuz Ahmed | Plugin version: 1.0.0*
