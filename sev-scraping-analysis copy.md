# SEV-Stromerzeuger.com → WooCommerce Migration
## Full Analysis & End-to-End Plan

---

## 1. WEBSITE OVERVIEW (Target Site)

**URL:** https://sev-stromerzeuger.com/
**Platform:** SHOPIFY (not WordPress — this is important, explained below)
**Language:** German (primary), French, English
**Company:** SEV GmbH, Schopfheim, Germany
**Niche:** Power generators, energy storage, accessories — B2B/B2C

---

## 2. WHAT CLIENT WANTS TO SCRAPE

### INCLUDE:
| Section | URL Pattern | Product Count |
|---------|------------|---------------|
| Products (all) | `/collections/all` | ~1,068 products |
| Manufacturers | `/collections/[brand-name]` | 70+ brands |
| Accessories | `/collections/zubehoer`, `/collections/tanks-kanister`, etc. | Multiple sub-categories |

### EXCLUDE (client said skip these):
- FAQ
- Rental
- Applications
- Jobs

---

## 3. WHAT DATA EXISTS PER PRODUCT

Each product page has the following fields to scrape:

| Field | Example |
|-------|---------|
| Product Name | Tragbarer Stromerzeuger ENDRESS ESE 6000 DBS |
| SKU | SEV-00167 |
| Manufacturer/Brand | Endress |
| Price (Net) | €1,017.45 |
| List Price / Compare Price | €1,094.80 |
| Discount % | 7.1% |
| Currency | EUR |
| Stock Status | In stock |
| Delivery Time | ~3-6 days |
| GTIN-13 (barcode) | 4005995027212 |
| Product Type | Benzin Generator |
| Short Description | Yes |
| Full Specifications | Power, engine, fuel, dimensions, weight, noise |
| Images (multiple) | 6 images per product avg |
| Variants (if any) | Some products have VAT variants |
| Categories | Yes (multiple) |

### Sample Spec Fields (for generators):
- Max Output (kVA/kW)
- Continuous Output
- Voltage (230V / 400V)
- Fuel Type (Diesel, Benzin, Gas, Dual Fuel)
- Engine Brand
- Tank Capacity
- Runtime
- Start System (electric / manual)
- Weight & Dimensions
- Noise Level (dB)
- Phase (single/3-phase)

---

## 4. SITE STRUCTURE & FILTERS (to replicate in WooCommerce)

### Current Filters on Target Site:
- Brand (70+ manufacturers)
- Price Range (min/max slider)
- Delivery Time
- Fuel Type (Diesel, Benzin, Gas, Dual Fuel)
- Power Rating (kVA classes)
- Phase Configuration (1-phase / 3-phase)
- Starting System (electric / manual)
- Engine Manufacturer
- Voltage Regulation (AVR / other)

### Product Category Tree:
```
Products
├── Stromerzeuger (Generators)
│   ├── Portable Generators
│   ├── Inverter Generators (~70 products)
│   ├── Emergency Power (Notstromaggregate)
│   ├── PTO Generators (Zapfwelle)
│   ├── Light Towers (Lichtmasten)
│   ├── Construction Lights
│   ├── Welding Generators
│   ├── Gas Generators
│   ├── Hybrid Generators
│   └── Motor Pumps
├── Energy Storage
│   ├── Commercial Storage
│   ├── Solar Storage
│   ├── Portable Power Stations
│   └── Balcony Solar Systems
└── Accessories (Zubehör)
    ├── Tanks & Canisters
    ├── Oils & Additives
    ├── Cables
    ├── Power Distributors
    ├── Battery Chargers
    ├── Solar Panels
    └── Safety Equipment

Manufacturers (70+ brands)
├── Endress (134 products)
├── Pramac (145 products)
├── HIMOINSA
├── SDMO Kohler
├── Atlas Copco
├── Mosa
├── Champion
└── ... 60+ more
```

---

## 5. IS IT POSSIBLE? YES — HERE'S THE TRUTH

**Yes, this is absolutely doable.** Here's why the client might think it's not:

1. The site is on Shopify — but that actually makes scraping EASIER (Shopify has predictable URL patterns)
2. 1,068 products sounds like a lot — but with the right script it's automated
3. Replicating filters in WooCommerce — 100% possible with the right plugins

**The realistic challenges:**
- Time: Scraping + cleaning + importing = significant work
- Product specs are in HTML tables — needs careful parsing
- Images need to be downloaded and re-hosted
- German content may need translation (optional)
- WooCommerce product attributes need to be mapped manually first time

---

## 6. TECH STACK & TOOLS NEEDED

### For Scraping:
| Tool | Purpose |
|------|---------|
| Python + BeautifulSoup / Scrapy | Crawl and parse product pages |
| Selenium (optional) | If any JS-rendered content |
| Requests library | HTTP requests |
| Pandas | Organize data into CSV/Excel |
| PIL / requests | Download product images |

### Since it's Shopify — BONUS TRICK:
Shopify exposes a JSON API for collections. We can use:
- `https://sev-stromerzeuger.com/collections/all/products.json?limit=250`
- `https://sev-stromerzeuger.com/collections/all/products.json?limit=250&page=2`

This gives clean structured JSON with all product data — **much easier than HTML scraping!**

### For WooCommerce Import:
| Tool | Purpose |
|------|---------|
| WooCommerce CSV Importer | Built-in product import |
| WP All Import (plugin) | Advanced XML/CSV import with field mapping |
| WooCommerce Product CSV | Map fields from scraped data |
| FTP / Media Upload | Bulk upload images |

### For Filters in WooCommerce:
| Plugin | Purpose |
|--------|---------|
| WOOF – WooCommerce Product Filters | Advanced filter sidebar like target site |
| FiboSearch | Fast search with filters |
| WooCommerce Attribute Manager | Create custom attributes (Brand, Fuel Type, Power, etc.) |

---

## 7. END-TO-END PROCESS (Step by Step)

### PHASE 1: Setup & Planning (1-2 days)
- [ ] Set up WordPress + WooCommerce on hosting
- [ ] Install WP All Import plugin
- [ ] Plan product attributes (fuel type, power, brand, phase, etc.)
- [ ] Set up category structure in WooCommerce

### PHASE 2: Scraping the Data (2-3 days)
- [ ] Write Python script to hit Shopify JSON API
  - `GET /collections/all/products.json?limit=250&page=N`
  - Loop through all pages (1,068 products ÷ 250 = ~5 pages)
- [ ] Collect for each product:
  - Title, handle, vendor (brand), type, tags
  - Price, compare_at_price
  - Description (HTML)
  - Images (URLs)
  - Variants (SKU, price, stock)
  - Metafields (specs — may need separate requests)
- [ ] Download all product images to local folder
- [ ] Export to CSV / Excel file

### PHASE 3: Data Cleaning & Mapping (1-2 days)
- [ ] Clean product descriptions (remove Shopify-specific HTML)
- [ ] Map categories to WooCommerce category structure
- [ ] Map brands to WooCommerce product attributes
- [ ] Map spec fields to custom attributes (fuel, power, phase, etc.)
- [ ] Format CSV for WP All Import

### PHASE 4: WooCommerce Import (1-2 days)
- [ ] Upload images via FTP or media library
- [ ] Run WP All Import with CSV
- [ ] Map all fields in the import wizard
- [ ] Test 10-20 products first (pilot import)
- [ ] Full import of all 1,068 products
- [ ] Verify categories, images, prices, descriptions

### PHASE 5: Filters & Shop Setup (1-2 days)
- [ ] Install WOOF or similar filter plugin
- [ ] Configure filter sidebar:
  - Brand filter (dropdown / checkbox)
  - Price range slider
  - Fuel type filter
  - Power rating filter
  - Phase filter
- [ ] Set up shop page layout (grid/list toggle)
- [ ] Set up manufacturer pages (one page per brand)

### PHASE 6: Testing & QA (1 day)
- [ ] Check 20+ random products for accuracy
- [ ] Test all filters work correctly
- [ ] Test mobile responsiveness
- [ ] Test search functionality
- [ ] Fix any broken images or missing data

---

## 8. SAMPLE SCRAPER CODE (Python - Shopify JSON API)

```python
import requests
import json
import pandas as pd
import os
import time

BASE_URL = "https://sev-stromerzeuger.com"
OUTPUT_FILE = "products.csv"
IMAGE_DIR = "product_images"

os.makedirs(IMAGE_DIR, exist_ok=True)

all_products = []
page = 1

while True:
    url = f"{BASE_URL}/collections/all/products.json?limit=250&page={page}"
    response = requests.get(url, headers={"User-Agent": "Mozilla/5.0"})
    data = response.json()

    products = data.get("products", [])
    if not products:
        break

    for product in products:
        # Get first variant
        variant = product["variants"][0] if product["variants"] else {}

        row = {
            "id": product["id"],
            "title": product["title"],
            "handle": product["handle"],
            "vendor": product["vendor"],        # Brand/Manufacturer
            "product_type": product["product_type"],
            "tags": ", ".join(product["tags"]),
            "price": variant.get("price", ""),
            "compare_at_price": variant.get("compare_at_price", ""),
            "sku": variant.get("sku", ""),
            "barcode": variant.get("barcode", ""),
            "available": variant.get("available", ""),
            "description": product["body_html"],
            "images": " | ".join([img["src"] for img in product["images"]]),
            "image_count": len(product["images"]),
        }
        all_products.append(row)

    print(f"Page {page}: collected {len(products)} products (total: {len(all_products)})")
    page += 1
    time.sleep(0.5)  # Be polite, don't hammer the server

df = pd.DataFrame(all_products)
df.to_csv(OUTPUT_FILE, index=False, encoding="utf-8-sig")
print(f"Done! Saved {len(all_products)} products to {OUTPUT_FILE}")
```

---

## 9. KEY NUMBERS & ESTIMATES

| Item | Count/Amount |
|------|-------------|
| Total Products | ~1,068 |
| Manufacturers | 70+ brands |
| Product Images | ~6,000+ (6 per product avg) |
| Product Categories | ~20+ |
| Accessory Sub-categories | ~10 |
| Scraping Time (with script) | 5-10 minutes |
| Data Cleaning Time | 1-2 days |
| WooCommerce Import | 1-2 days |
| Filter Setup | 1 day |
| **Total Project Time** | **~7-12 working days** |

---

## 10. PLUGINS RECOMMENDED FOR WORDPRESS

| Plugin | Cost | Purpose |
|--------|------|---------|
| WooCommerce | Free | Core shop |
| WP All Import Pro | ~$99 | Bulk product import from CSV |
| WOOF Product Filters | Free/Pro | Filter sidebar |
| WooCommerce Brands | Free/Pro | Manufacturer pages |
| Smush / ShortPixel | Free | Image optimization |
| FiboSearch | Free/Pro | AJAX search |
| Rank Math SEO | Free | SEO for product pages |

---

## 11. POTENTIAL CHALLENGES & SOLUTIONS

| Challenge | Solution |
|-----------|---------|
| Product specs in HTML table | Parse HTML with BeautifulSoup, extract to columns |
| 6,000+ images to download | Python script to batch download |
| German language content | Keep as-is OR use DeepL API for translation |
| VAT variants (some products have 2 price variants) | Import as separate attributes or ignore second variant |
| Shopify metafields (extra specs) | Use Shopify JSON API `/products/{id}/metafields.json` |
| 70+ manufacturer pages to create | Script to auto-create WooCommerce brand pages |

---

## 12. FINAL ANSWER: CAN YOU DO THIS?

**YES. Absolutely yes.** Here's the summary:

- The site runs on **Shopify** which has a public JSON API — scraping is straightforward
- **1,068 products** can be scraped in minutes with a Python script
- **WooCommerce import** with WP All Import is a standard professional workflow
- **Filters** can be replicated 100% with WOOF plugin
- **Manufacturer pages** are standard WooCommerce brand taxonomy

**What makes it doable:**
1. Shopify JSON API = clean structured data, no messy HTML scraping needed for product data
2. WP All Import = visual field mapper, no custom coding needed for import
3. WooCommerce attributes = exact same filter system as the target site
4. This is a known, documented workflow used by professionals regularly

**Realistic timeline:** 7-12 working days for a clean, complete setup.

---

*Document created: 2026-02-23*
*Target site: https://sev-stromerzeuger.com/*
*Purpose: Migration analysis for WooCommerce rebuild*
