# PROJECT DOCUMENTATION
## SEV-Stromerzeuger → WooCommerce Product Sync System

**Version:** 1.0
**Date:** 2026-02-25
**Prepared for:** Client Review
**Project Type:** Web Scraping + JSON Data Pipeline + WooCommerce Sync

---

## TABLE OF CONTENTS

1. [Project Overview](#1-project-overview)
2. [Source Website Analysis](#2-source-website-analysis)
3. [What We Scrape (Scope)](#3-what-we-scrape-scope)
4. [How Scraping Works (No API Key Needed)](#4-how-scraping-works)
5. [Complete Data Schema](#5-complete-data-schema)
6. [JSON Output Format](#6-json-output-format)
7. [Daily Sync Logic](#7-daily-sync-logic)
8. [Technical Architecture](#8-technical-architecture)
9. [File & Folder Structure](#9-file--folder-structure)
10. [Script Workflow (Step by Step)](#10-script-workflow)
11. [Deliverables](#11-deliverables)
12. [Timeline](#12-timeline)
13. [Risks & Edge Cases](#13-risks--edge-cases)

---

## 1. PROJECT OVERVIEW

### What This Project Does

This project builds an **automated daily scraper** that:

1. Visits the Shopify store at `https://sev-stromerzeuger.com/`
2. Collects ALL product data (prices, stock, SKUs, descriptions, images, categories)
3. Saves the data into a **structured JSON file**
4. The JSON file is then read by the client's **custom WordPress/WooCommerce plugin** via cron job
5. The plugin **auto-updates** products in WooCommerce — new products added, prices updated, out-of-stock flagged, removed products deleted

### One-Line Summary

> Scrape → Clean → Save as JSON → WooCommerce plugin reads JSON → Store stays updated 24/7

### The Key Discovery (Important for Client)

The target site runs on **Shopify**, which exposes **public JSON endpoints** — no API key or login is required. This means we can retrieve clean, structured product data directly without complex HTML scraping. This makes the scraper:
- More **reliable** (less chance of breaking if the website design changes)
- More **accurate** (structured data, no parsing errors)
- More **complete** (every field, every product)

---

## 2. SOURCE WEBSITE ANALYSIS

| Property | Details |
|----------|---------|
| **URL** | https://sev-stromerzeuger.com/ |
| **Platform** | Shopify |
| **Language** | German (primary) |
| **Total Products** | ~1,068 products |
| **Total Brands/Manufacturers** | 70+ brands |
| **Total Collections (Categories)** | 30+ collections |
| **Product Types** | Generators, Energy Storage, Accessories, Solar |
| **Pricing Currency** | EUR (€) |
| **Images per Product** | Average 6 images |
| **Variant Type** | Tax variants only (19% vs 0% VAT) — NOT size/color |

### Available Public JSON Endpoints (No Auth Required)

| Endpoint | Data Returned |
|----------|--------------|
| `/collections/all/products.json?limit=250&page=N` | All products, paginated |
| `/collections/[handle]/products.json` | Products in a specific category |
| `/collections.json` | All categories with product counts |
| `/products/[handle].json` | Single product full detail |

---

## 3. WHAT WE SCRAPE (SCOPE)

### INCLUDE — Scrape These

| Section | URL | Approx Count |
|---------|-----|-------------|
| All Products | `/collections/all` | ~1,068 products |
| Manufacturers | `/collections/endress`, `/collections/pramac`, etc. | 70+ brands |
| Accessories | `/collections/zubehoer`, `/collections/kabel`, etc. | Multiple sub-cats |

### EXCLUDE — Do NOT Scrape These

| Section | Reason |
|---------|--------|
| FAQ | Client instruction — not needed |
| Rental | Client instruction — not needed |
| Applications | Client instruction — not needed |
| Jobs | Client instruction — not needed |

### Product Categories to Include

```
Generators (Stromerzeuger)
├── Benzin Stromerzeuger bis 12 kVA      (272 products)
├── Diesel Stromerzeuger bis 12 kVA      (164 products)
├── Diesel Stromerzeuger 12 bis 3000 kVA (399 products)
├── Inverter Generators                   (~70 products)
├── Gas-Stromerzeuger                     (31 products)
├── Hybrid Generators                     (separate)
├── Welding Generators                    (separate)
├── Motor Pumps                           (separate)
├── Emergency Power (Notstrom)            (separate)
├── PTO Generators (Zapfwelle)            (separate)
├── Light Towers (Lichtmasten)            (separate)
└── Construction Lights (Baustrahler)     (18 products)

Energy Storage (Energiespeicher)
├── Commercial Storage (Gewerbespeicher)
├── Solar Storage (Solarspeicher)
├── Portable Power Stations
└── Balcony Solar (Balkonkraftwerk)       (13 products)

Accessories (Zubehör)
├── Tanks and Canisters                   (separate)
├── Oils (Öle)                            (separate)
├── Additives (Additive)                  (7 products)
├── Specialty Fuels                       (separate)
├── Cables (Kabel)                        (12 products)
├── Power Distributors (Stromverteiler)   (separate)
├── Battery Chargers (Batterieladegerät)  (21 products)
├── Solar Panels (Solarpanele)            (separate)
└── Safety Equipment (Arbeitssicherheit)  (5 products)

Manufacturers (as separate pages/filters)
├── ENDRESS          (152 products)
├── Pramac           (145 products)
├── Atlas Copco      (32 products)
├── Champion         (26 products)
├── GEKO Eisemann    (29 products)
├── Ferbo            (27 products)
├── Foxtheon         (17 products)
├── BLUETTI          (21 products)
├── BYD              (14 products)
└── ... 60+ more brands
```

---

## 4. HOW SCRAPING WORKS

### Method: Shopify Public JSON API (No Login, No API Key)

Shopify stores have public-facing JSON endpoints. No authentication is needed.

**Step 1 — Get all products (paginated):**
```
GET https://sev-stromerzeuger.com/collections/all/products.json?limit=250&page=1
GET https://sev-stromerzeuger.com/collections/all/products.json?limit=250&page=2
GET https://sev-stromerzeuger.com/collections/all/products.json?limit=250&page=3
... continue until empty page
```

**Step 2 — Get all collections (categories):**
```
GET https://sev-stromerzeuger.com/collections.json
```

**Step 3 — Map products to their categories:**
```
GET https://sev-stromerzeuger.com/collections/[handle]/products.json?limit=250
(for each relevant collection)
```

**Step 4 — Transform and save as JSON file**

### Why This Method Is Reliable

| Risk | With HTML Scraping | With JSON API |
|------|-------------------|---------------|
| Site redesign breaks scraper | HIGH | LOW (JSON structure rarely changes) |
| Missing data | MEDIUM | LOW (all fields in structured format) |
| Speed | Slow | Fast (direct data, no parsing) |
| Accuracy | MEDIUM | HIGH |
| Bot detection/blocking | MEDIUM | LOW |

---

## 5. COMPLETE DATA SCHEMA

### Every Field Available Per Product

#### Product Level
| Field Name | Type | Example | Notes |
|-----------|------|---------|-------|
| `id` | integer | `8657963090267` | Shopify product ID |
| `title` | string | `"Tragbarer Stromerzeuger ENDRESS ESE 6000 DBS"` | Full product name |
| `handle` | string | `"tragbarer-stromerzeuger-endress-ese-6000-dbs"` | URL slug |
| `vendor` | string | `"Endress"` | Brand/manufacturer name |
| `product_type` | string | `"Benzin Generator"` | Product type category |
| `tags` | string | `"Benzin, ENDRESS, Stromerzeuger"` | Comma-separated |
| `body_html` | string | `"<p>Description...</p>"` | Full HTML description |
| `created_at` | datetime | `"2023-11-16T15:32:56+01:00"` | When added to store |
| `updated_at` | datetime | `"2026-02-25T11:18:19+01:00"` | Last modification |
| `published_at` | datetime | `"2023-11-16T15:32:56+01:00"` | When published |

#### Variants (nested array per product)
| Field Name | Type | Example | Notes |
|-----------|------|---------|-------|
| `id` | integer | `47565740867931` | Shopify variant ID |
| `sku` | string | `"SEV-00167"` | Product SKU code |
| `price` | string | `"1017.45"` | Current price (EUR) |
| `compare_at_price` | string/null | `"1094.80"` | Original price (strikethrough) |
| `available` | boolean | `true` | In stock = true |
| `barcode` | string/null | `"4005995027212"` | EAN barcode |
| `weight` | float | `93.0` | Product weight |
| `weight_unit` | string | `"kg"` | Weight unit |
| `taxable` | boolean | `true` | Taxable product |
| `requires_shipping` | boolean | `true` | Physical product |
| `title` | string | `"Default Title"` | Variant name |

#### Images (nested array per product)
| Field Name | Type | Example | Notes |
|-----------|------|---------|-------|
| `id` | integer | `54544501768539` | Image ID |
| `position` | integer | `1` | Display order |
| `src` | string | `"https://cdn.shopify.com/..."` | Full image URL |
| `width` | integer/null | `500` | Image width px |
| `height` | integer/null | `500` | Image height px |
| `alt_text` | string/null | `null` | Alt text (often empty) |

---

## 6. JSON OUTPUT FORMAT

### File Name
```
sev_products.json
```

### File Structure (Schema)

This is the JSON format the scraper will output. Design it to match what the WordPress plugin expects:

```json
{
  "metadata": {
    "source": "sev-stromerzeuger.com",
    "scraped_at": "2026-02-25T08:00:00Z",
    "total_products": 1068,
    "version": "1.0"
  },
  "products": [
    {
      "shopify_id": 8657963090267,
      "sku": "SEV-00167",
      "barcode": "4005995027212",
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
        "ENDRESS",
        "Stromerzeuger",
        "SONDERANGEBOTE"
      ],
      "description_html": "<p>Full product description HTML...</p>",
      "images": [
        {
          "position": 1,
          "url": "https://cdn.shopify.com/s/files/1/0792/6284/3227/files/ESE6000DBS.jpg",
          "width": 500,
          "height": 500
        },
        {
          "position": 2,
          "url": "https://cdn.shopify.com/s/files/1/0792/6284/3227/files/ESE6000DBS_angle2.jpg",
          "width": null,
          "height": null
        }
      ],
      "source_url": "https://sev-stromerzeuger.com/products/tragbarer-stromerzeuger-endress-ese-6000-dbs",
      "last_updated": "2026-02-25T11:18:19+01:00",
      "scraped_at": "2026-02-25T08:00:00Z"
    }
  ]
}
```

### Notes on JSON Fields for Plugin Integration

| Field | Purpose in WooCommerce |
|-------|----------------------|
| `sku` | Match/update existing products by SKU |
| `shopify_id` | Backup unique identifier |
| `price` | Update WooCommerce sale price |
| `compare_price` | Update WooCommerce regular price |
| `in_stock` | Set stock status (instock / outofstock) |
| `categories` | Assign WooCommerce categories |
| `brand` | Assign WooCommerce brand/manufacturer |
| `images` | Sync product gallery |
| `last_updated` | Know if product changed since last run |
| `scraped_at` | Audit trail — when was this data collected |

### Variant Handling Rule

Since 90%+ of products have only one variant with "Default Title":
- Use the **first variant's** price, SKU, stock status
- For products with VAT variants (Mehrwertsteuer): use the **19% VAT variant** as the default

---

## 7. DAILY SYNC LOGIC

### How the Scraper Handles Updates

The scraper runs once daily (via cron job or scheduled task) and handles three scenarios:

#### Scenario A — New Product Added (on source site)
```
Scraper finds product SKU not in JSON file
→ Adds full product data to JSON
→ Plugin reads JSON → creates new WooCommerce product
```

#### Scenario B — Price or Stock Changed
```
Scraper finds product SKU already in JSON, but price/stock differs
→ Updates that product's price + stock in JSON
→ Plugin reads JSON → updates existing WooCommerce product
```

#### Scenario C — Product Removed (discontinued)
```
Product SKU was in previous JSON, not found in today's scrape
→ Marks product as "status": "removed" in JSON
→ Plugin reads JSON → sets WooCommerce product to draft/deleted
```

### Change Detection Logic (Per Product)

```
For each scraped product:
  IF sku not in existing JSON:
    → ADD as new product
  ELSE IF price changed OR stock changed OR updated_at changed:
    → UPDATE that product entry
  ELSE:
    → No change, skip (save time)

For each product in existing JSON:
  IF sku not found in today's scrape:
    → Mark as "status": "removed"
```

### What Gets Updated Every Run

| Data Point | Updated Daily |
|-----------|--------------|
| Price (current) | YES |
| Compare price (original) | YES |
| Stock status (in_stock) | YES |
| Product name | YES |
| Description | YES |
| Images | YES (if changed) |
| SKU | YES (if changed) |
| Categories | YES |
| Removed products flagged | YES |

---

## 8. TECHNICAL ARCHITECTURE

```
┌─────────────────────────────────────────────────────────┐
│              SOURCE: sev-stromerzeuger.com               │
│                   (Shopify Store)                        │
│         Public JSON API - No auth required               │
└──────────────────────┬──────────────────────────────────┘
                       │  GET /collections/all/products.json
                       │  GET /collections.json
                       ▼
┌─────────────────────────────────────────────────────────┐
│                  PYTHON SCRAPER                          │
│                                                          │
│  1. Fetch all products (paginated, 250/page)             │
│  2. Fetch all collections (categories)                   │
│  3. Map products → categories                            │
│  4. Load previous JSON (for change detection)            │
│  5. Compare: new / updated / removed products            │
│  6. Build updated JSON output                            │
│  7. Save sev_products.json                               │
└──────────────────────┬──────────────────────────────────┘
                       │  Saves file
                       ▼
┌─────────────────────────────────────────────────────────┐
│                 sev_products.json                        │
│              (Structured JSON File)                      │
│         ~1,068 products, all fields                      │
└──────────────────────┬──────────────────────────────────┘
                       │  Read by plugin
                       ▼
┌─────────────────────────────────────────────────────────┐
│           WORDPRESS / WOOCOMMERCE                        │
│         (Client's Custom Import Plugin)                  │
│                                                          │
│  Cron Job: reads JSON → sync WooCommerce products        │
│  - Create new products                                   │
│  - Update prices & stock                                 │
│  - Remove discontinued products                          │
└─────────────────────────────────────────────────────────┘
```

### System Components

| Component | Technology | Who Manages |
|-----------|-----------|-------------|
| Scraper Script | Python 3 | Developer (us) |
| JSON output file | JSON file | Shared (scraper writes, plugin reads) |
| Scheduling (daily run) | Cron job / Windows Task Scheduler | Developer or hosting |
| WordPress Plugin | PHP (already exists) | Client |
| WooCommerce | WordPress plugin | Client |

---

## 9. FILE & FOLDER STRUCTURE

```
scrapProject/
│
├── scraper/
│   ├── scraper.py          ← Main scraper script
│   ├── config.py           ← Settings (URL, output path, etc.)
│   └── requirements.txt    ← Python dependencies
│
├── data/
│   ├── sev_products.json   ← OUTPUT: latest full product JSON
│   ├── sev_products_prev.json  ← BACKUP: previous run (for diff)
│   └── scrape_log.txt      ← Log file (date, counts, errors)
│
├── PROJECT-DOCUMENTATION.md   ← This file
└── sev-scraping-analysis.md   ← Initial analysis
```

---

## 10. SCRIPT WORKFLOW

### Scraper Script — Step-by-Step Logic

```
START
│
├── STEP 1: Load previous JSON (if exists) → store in memory
│
├── STEP 2: Fetch all collections from /collections.json
│          → filter out: Rental, FAQ, Applications, Jobs collections
│          → keep: Products, Accessories, Manufacturers
│
├── STEP 3: Fetch all products — paginated loop
│          Page 1: /collections/all/products.json?limit=250&page=1
│          Page 2: /collections/all/products.json?limit=250&page=2
│          Page N: ... continue until empty response
│          → Total: ~5 pages for 1,068 products
│
├── STEP 4: For each product → map to categories
│          (which collections does this product belong to?)
│
├── STEP 5: Compare with previous JSON
│          → New products: add to output
│          → Changed products: update in output
│          → Missing products: mark status = "removed"
│
├── STEP 6: Build final JSON structure
│          → metadata block (timestamp, total count)
│          → products array (all 1,068 products)
│
├── STEP 7: Save files
│          → Copy current JSON → sev_products_prev.json (backup)
│          → Save new data → sev_products.json
│          → Append to scrape_log.txt
│
└── END
    → Log: "Scraped 1068 products. 3 new, 12 updated, 0 removed."
```

### Python Libraries Required

```
requests       ← HTTP requests to Shopify JSON API
json           ← Parse and write JSON
pandas         ← Data manipulation (optional)
datetime       ← Timestamps
logging        ← Log file management
time           ← Delay between requests (polite scraping)
```

Install command:
```bash
pip install requests pandas
```

---

## 11. DELIVERABLES

| # | Deliverable | Description |
|---|------------|-------------|
| 1 | `scraper.py` | Main Python script — fetches all products, outputs JSON |
| 2 | `config.py` | Config file (base URL, excluded collections, output paths) |
| 3 | `requirements.txt` | Python dependency list |
| 4 | `sev_products.json` | First full scrape output (sample/test run) |
| 5 | `scrape_log.txt` | Log file showing each run's results |
| 6 | Setup instructions | How to run the script + set up cron job |
| 7 | JSON schema doc | Exact field definitions for plugin developer |

### What Is NOT in Scope
- WordPress plugin development (client already has this)
- WooCommerce setup or theme (client handles this)
- Image downloading/re-hosting (images stay on Shopify CDN via URL)
- Translation (content remains in German unless specified)

---

## 12. TIMELINE

| Phase | Task | Days |
|-------|------|------|
| **Phase 1** | Write scraper script + test on 50 products | 2 days |
| **Phase 2** | Full scrape of all 1,068 products + verify JSON | 1 day |
| **Phase 3** | Test change detection logic (new/updated/removed) | 1 day |
| **Phase 4** | Set up cron job / scheduler for daily runs | 0.5 day |
| **Phase 5** | Deliver all files + JSON schema to client/plugin dev | 0.5 day |
| **TOTAL** | | **~5 working days** |

---

## 13. RISKS & EDGE CASES

| Risk | Likelihood | Impact | Solution |
|------|-----------|--------|---------|
| Shopify blocks the scraper | LOW | HIGH | Add request delays (0.5s between requests), rotate User-Agent headers |
| Shopify changes JSON endpoint | VERY LOW | HIGH | Monitor weekly, update URL if needed |
| Product count grows past 1,068 | MEDIUM | LOW | Pagination loop handles any count automatically |
| Product has no SKU | LOW | MEDIUM | Use `shopify_id` as fallback unique key |
| Image URL expires (CDN) | LOW | MEDIUM | Note in plugin: fetch fresh images each sync |
| Description has complex HTML | MEDIUM | LOW | Pass HTML as-is — WooCommerce handles it |
| VAT variant confusion | MEDIUM | LOW | Always use first variant (19% VAT) as default |
| Script crashes mid-run | LOW | MEDIUM | Add try/catch, partial save, log errors |

---

## APPENDIX A — Sample JSON Output (2 Products)

```json
{
  "metadata": {
    "source": "sev-stromerzeuger.com",
    "scraped_at": "2026-02-25T08:00:00Z",
    "total_products": 1068,
    "new_products": 0,
    "updated_products": 0,
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
        "ENDRESS (YAMAHA Motor)",
        "SONDERANGEBOTE",
        "Stromerzeuger"
      ],
      "description_html": "<p>6.9 kVA / 5.5 kW max output. Yamaha engine. 30L tank...</p>",
      "images": [
        {
          "position": 1,
          "url": "https://cdn.shopify.com/s/files/1/0792/6284/3227/files/ESE6000DBS_2_500x.jpg",
          "width": 500,
          "height": 500
        }
      ],
      "source_url": "https://sev-stromerzeuger.com/products/tragbarer-stromerzeuger-endress-ese-6000-dbs",
      "last_updated": "2026-02-25T11:18:19+01:00",
      "scraped_at": "2026-02-25T08:00:00Z"
    },
    {
      "shopify_id": 8658325274971,
      "sku": "SEV-00231",
      "barcode": "4005995027212",
      "status": "active",
      "name": "Stromerzeuger RID RCS 3001 l Inverter Generator",
      "slug": "stromerzeuger-rid-rcs-3001i-inverter-generator",
      "brand": "RID",
      "product_type": "Inverter",
      "price": "827.05",
      "compare_price": "1142.40",
      "currency": "EUR",
      "in_stock": true,
      "weight": 45.0,
      "weight_unit": "kg",
      "categories": [
        "Inverter Stromerzeuger"
      ],
      "tags": ["RID", "Inverter", "Stromerzeuger"],
      "description_html": "<p>Quiet inverter generator, ideal for camping...</p>",
      "images": [
        {
          "position": 1,
          "url": "https://cdn.shopify.com/s/files/1/0792/6284/3227/files/RID_RCS3001.jpg",
          "width": 500,
          "height": 500
        }
      ],
      "source_url": "https://sev-stromerzeuger.com/products/stromerzeuger-rid-rcs-3001i-inverter-generator",
      "last_updated": "2026-02-25T11:18:19+01:00",
      "scraped_at": "2026-02-25T08:00:00Z"
    }
  ]
}
```

---

## APPENDIX B — Removed Product Example

When a product disappears from the source site, it will appear in the JSON like this:

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

The WordPress plugin will see `"status": "removed"` and handle it accordingly (set to draft, delete, or mark as out-of-stock).

---

*Document Version: 1.0 | Created: 2026-02-25*
*For questions about this document, contact the developer.*
