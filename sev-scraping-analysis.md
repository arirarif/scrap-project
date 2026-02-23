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



*Document created: 2026-02-23*
*Target site: https://sev-stromerzeuger.com/*
*Purpose: Migration analysis for WooCommerce rebuild*
