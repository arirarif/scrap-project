# AGENT TASK: Build Python Scraper for SEV-Stromerzeuger → JSON

## YOUR JOB

Build a complete, production-ready Python scraper that:
1. Scrapes all products from `https://sev-stromerzeuger.com/`
2. Saves everything into a structured `sev_products.json` file
3. Runs daily and keeps the JSON updated (new products, price changes, stock changes, removed products)

Deliver these files:
- `scraper/scraper.py` — main script
- `scraper/config.py` — all settings/config
- `scraper/requirements.txt` — dependencies
- `data/sev_products.json` — output file (created on first run)
- `data/scrape_log.txt` — log file (appended on every run)

---

## IMPORTANT TECHNICAL DISCOVERY

The target site runs on **Shopify**. Shopify exposes **public JSON endpoints — no API key or login needed**.

Use these endpoints instead of HTML scraping:

```
# All products, paginated (250 per page)
GET https://sev-stromerzeuger.com/collections/all/products.json?limit=250&page=1
GET https://sev-stromerzeuger.com/collections/all/products.json?limit=250&page=2
... loop until response returns empty products array

# All collections/categories
GET https://sev-stromerzeuger.com/collections.json

# Products within a specific collection
GET https://sev-stromerzeuger.com/collections/[handle]/products.json?limit=250

# Single product detail
GET https://sev-stromerzeuger.com/products/[handle].json
```

This gives clean structured JSON. DO NOT scrape HTML pages. Use the JSON API only.

---

## WHAT TO SCRAPE

### INCLUDE
- All products from `/collections/all`
- Map each product to its correct categories (by checking which collections it belongs to)
- Manufacturers (vendor field already exists on each product)
- Accessories (already part of all products)

### EXCLUDE — Skip these collections entirely
| Collection to Skip | Handle to filter out |
|--------------------|---------------------|
| FAQ | `faq` |
| Rental / Miete | `miete`, `rental`, `vermietung` |
| Applications | `anwendungen`, `applications` |
| Jobs | `jobs` |

Filter these out when building the category list. Products that ONLY belong to these collections should still be included if they appear in `/collections/all` — just don't assign those excluded collection names as categories.

---

## SOURCE DATA SCHEMA

Each product from the Shopify JSON API has this structure:

```json
{
  "id": 8657963090267,
  "title": "Tragbarer Stromerzeuger ENDRESS ESE 6000 DBS",
  "handle": "tragbarer-stromerzeuger-endress-ese-6000-dbs",
  "vendor": "Endress",
  "product_type": "Benzin Generator",
  "body_html": "<p>Full HTML description...</p>",
  "tags": "Benzin Stromerzeuger bis 12 kVA, ENDRESS, SONDERANGEBOTE",
  "created_at": "2023-11-16T15:32:56+01:00",
  "updated_at": "2026-02-25T11:18:19+01:00",
  "published_at": "2023-11-16T15:32:56+01:00",
  "variants": [
    {
      "id": 47565740867931,
      "product_id": 8657963090267,
      "title": "Default Title",
      "sku": "SEV-00167",
      "price": "1017.45",
      "compare_at_price": "1094.80",
      "barcode": "4005995027212",
      "weight": 93.0,
      "weight_unit": "kg",
      "available": true,
      "taxable": true,
      "requires_shipping": true
    }
  ],
  "images": [
    {
      "id": 54544501768539,
      "position": 1,
      "src": "https://cdn.shopify.com/s/files/1/...",
      "width": 500,
      "height": 500,
      "alt_text": null
    }
  ],
  "options": [
    {
      "name": "Title",
      "values": ["Default Title"]
    }
  ]
}
```

### Variant Handling Rules
- 90%+ of products have only ONE variant with title "Default Title" — treat as simple product
- Some products have 2 variants for VAT: "Mehrwertsteuer: Standardsatz 19%" and "Mehrwertsteuer: reduzierter Mehrwertsteuersatz 0%"
- **Rule:** Always use the FIRST variant as the primary. If variant title contains "19%" use it. If variant title contains "0%" skip it as secondary.
- Use `shopify_id` as fallback unique key if SKU is empty

---

## REQUIRED JSON OUTPUT FORMAT

Output file: `data/sev_products.json`

```json
{
  "metadata": {
    "source": "sev-stromerzeuger.com",
    "scraped_at": "2026-02-27T08:00:00Z",
    "total_products": 1068,
    "new_products": 5,
    "updated_products": 12,
    "removed_products": 0,
    "version": "1.0"
  },
  "products": [
    {
      "shopify_id": 8657963090267,
      "sku": "SEV-00167",
      "barcode": "4005995027212",
      "status": "active",
      "name": "Tragbarer Stromerzeuger ENDRESS ESE 6000 DBS",
      "slug": "tragbarer-stromerzeuger-endress-ese-6000-dbs",
      "brand": "Endress",
      "product_type": "Benzin Generator",
      "price": "1017.45",
      "compare_price": "1094.80",
      "currency": "EUR",
      "in_stock": true,
      "weight": 93.0,
      "weight_unit": "kg",
      "categories": [
        "Benzin Stromerzeuger bis 12 kVA",
        "ENDRESS",
        "Tragbare Stromerzeuger"
      ],
      "tags": [
        "Benzin Stromerzeuger bis 12 kVA",
        "Einfamilienhaus",
        "ENDRESS",
        "SONDERANGEBOTE"
      ],
      "description_html": "<p>Full product description HTML as-is from source...</p>",
      "images": [
        {
          "position": 1,
          "url": "https://cdn.shopify.com/s/files/1/0792/6284/3227/files/ESE6000DBS.jpg",
          "width": 500,
          "height": 500
        },
        {
          "position": 2,
          "url": "https://cdn.shopify.com/s/files/1/0792/6284/3227/files/ESE6000DBS_2.jpg",
          "width": null,
          "height": null
        }
      ],
      "source_url": "https://sev-stromerzeuger.com/products/tragbarer-stromerzeuger-endress-ese-6000-dbs",
      "last_updated": "2026-02-25T11:18:19+01:00",
      "scraped_at": "2026-02-27T08:00:00Z"
    }
  ]
}
```

### When a product is removed (no longer on source site):
```json
{
  "shopify_id": 8657963090267,
  "sku": "SEV-00167",
  "status": "removed",
  "name": "Tragbarer Stromerzeuger ENDRESS ESE 6000 DBS",
  "removed_at": "2026-03-01T08:00:00Z",
  "last_seen": "2026-02-28T08:00:00Z"
}
```

---

## DAILY SYNC LOGIC — CRITICAL

The scraper must handle 3 scenarios on every run:

### Scenario A — New Product
```
SKU found in today's scrape but NOT in existing sev_products.json
→ Add full product entry with status: "active"
→ Count in metadata.new_products
```

### Scenario B — Updated Product
```
SKU found in both today's scrape AND existing JSON
→ Compare: price, compare_price, in_stock, name, updated_at
→ If ANY field changed: update the entry, update scraped_at
→ Count in metadata.updated_products
→ If nothing changed: keep entry as-is (don't update scraped_at)
```

### Scenario C — Removed Product
```
SKU was in existing JSON but NOT found in today's scrape
→ Change status from "active" to "removed"
→ Set removed_at = now
→ Set last_seen = previous scraped_at
→ Keep in JSON (do NOT delete the entry — plugin needs to see it to remove from WooCommerce)
→ Count in metadata.removed_products
```

### Unique Key for Matching
Primary key = `sku` (e.g. "SEV-00167")
Fallback key = `shopify_id` (if SKU is empty string or null)

---

## CATEGORY MAPPING LOGIC

The Shopify API gives product tags, but NOT which collections a product belongs to.
To get accurate categories, you must:

1. First fetch all collections: `GET /collections.json`
2. For each relevant collection, fetch its product handles:
   `GET /collections/[handle]/products.json?limit=250&page=N`
3. Build a lookup map: `{ product_handle: [collection_title, ...] }`
4. When building the output JSON, look up each product's handle to get its categories array

**Collections to exclude from the category list** (filter these handles):
```python
EXCLUDED_COLLECTION_HANDLES = [
    "miete", "rental", "vermietung",          # Rental
    "faq",                                     # FAQ
    "anwendungen", "applications",             # Applications
    "jobs",                                    # Jobs
    "kommunen", "landwirtschaft", "baustelle", # Application pages
    "referenzen",                              # References
]
```

---

## SCRAPER WORKFLOW (exact order)

```
1. Load existing sev_products.json into memory (if file exists)
   → Build dict: { sku: product_entry } for fast lookup

2. Fetch collections.json
   → Filter out excluded handles
   → Keep list of valid collection handles + titles

3. For each valid collection:
   → Fetch all product handles in that collection (paginated)
   → Build map: { product_handle: [collection_title, ...] }

4. Fetch all products from /collections/all/products.json (paginated)
   → Loop pages 1, 2, 3... until empty response
   → Collect all ~1,068 products

5. For each scraped product:
   → Extract first variant (apply VAT variant rule)
   → Look up categories from the map built in step 3
   → Parse tags string into array
   → Build output product object (per JSON schema above)
   → Compare with existing JSON entry:
      - New? → add with status: "active"
      - Changed? → update fields
      - Same? → keep unchanged

6. For each product in existing JSON not found in today's scrape:
   → Set status: "removed", removed_at: now, last_seen: previous scraped_at

7. Build final output:
   → metadata block with counts
   → products array (all active + all removed)

8. Backup: rename sev_products.json → sev_products_prev.json
9. Write new sev_products.json
10. Append run summary to scrape_log.txt
```

---

## CONFIG FILE (config.py)

```python
# scraper/config.py

BASE_URL = "https://sev-stromerzeuger.com"
OUTPUT_FILE = "data/sev_products.json"
BACKUP_FILE = "data/sev_products_prev.json"
LOG_FILE = "data/scrape_log.txt"
REQUEST_DELAY = 0.5  # seconds between requests (be polite)
REQUEST_TIMEOUT = 30  # seconds

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
}

EXCLUDED_COLLECTION_HANDLES = [
    "miete", "rental", "vermietung",
    "faq",
    "anwendungen", "applications",
    "jobs",
    "kommunen", "landwirtschaft", "baustelle",
    "referenzen",
]
```

---

## LOG FILE FORMAT

Append one line per run to `data/scrape_log.txt`:

```
[2026-02-27 08:00:01] RUN COMPLETE | Total: 1068 | New: 3 | Updated: 12 | Removed: 0 | Duration: 47s
[2026-02-27 08:00:01] ERROR: Failed to fetch page 3 - retrying...
[2026-02-28 08:00:03] RUN COMPLETE | Total: 1071 | New: 3 | Updated: 5 | Removed: 0 | Duration: 52s
```

---

## ERROR HANDLING REQUIREMENTS

- Wrap all HTTP requests in try/except
- On connection error: retry up to 3 times with 5s delay between retries
- If a page fails after 3 retries: log the error, skip that page, continue
- If the entire run fails: do NOT overwrite the existing sev_products.json — keep old data safe
- Print progress to console: `Fetching page 1... (250 products)`, `Fetching page 2... (250 products)` etc.

---

## REQUIREMENTS.TXT

```
requests>=2.31.0
```

No other third-party libraries needed. Use only Python standard library + requests.

---

## EXPECTED PERFORMANCE

- Total products: ~1,068
- Pages to fetch for products: ~5 (250 per page)
- Collections: ~30
- Total HTTP requests per run: ~40-50
- Estimated run time: 30-60 seconds (with 0.5s delays)

---

## EXAMPLE: How to run

```bash
# Install dependency
pip install requests

# First run (creates sev_products.json from scratch)
python scraper/scraper.py

# Daily run (updates existing sev_products.json)
python scraper/scraper.py

# Set up daily cron job (Linux/Mac)
0 8 * * * /usr/bin/python3 /path/to/scraper/scraper.py

# Set up daily Task Scheduler (Windows)
# Run: python scraper/scraper.py at 8:00 AM every day
```

---

## SUMMARY OF WHAT TO DELIVER

| File | Description |
|------|-------------|
| `scraper/scraper.py` | Full working scraper script |
| `scraper/config.py` | Config with all settings |
| `scraper/requirements.txt` | Just: `requests>=2.31.0` |
| `data/sev_products.json` | Run the script and include the output |
| `data/scrape_log.txt` | Include the log from the test run |

The scraper must be **runnable immediately** after `pip install requests`.
All logic — pagination, category mapping, change detection, removed product flagging, logging — must be in `scraper.py`.
