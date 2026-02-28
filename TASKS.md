# Work Task Checklist — SEV Scraper (Local First)
> Goal: Scrape sev-stromerzeuger.com → save `products.json` locally → validate → then push to WordPress
> Status legend: ⬜ todo | 🔄 in progress | ✅ done | ❌ blocked

---

## PHASE 1 — Local Setup (do this once)

- ⬜ **1.1** Install Python 3.11+ from https://www.python.org/downloads/
  - During install: check "Add Python to PATH" ✅
  - Verify: open CMD → type `python --version` → should show 3.x

- ⬜ **1.2** Install required Python packages
  ```
  pip install requests beautifulsoup4 lxml
  ```

- ⬜ **1.3** Create output folder
  - Folder already exists at: `scrapProject/sev-scraper/output/`

---

## PHASE 2 — Run Scraper Locally

- ⬜ **2.1** Open terminal in `scrapProject/sev-scraper/` folder

- ⬜ **2.2** Run a TEST scrape (1 page only = 250 products, fast)
  ```
  python scraper.py --test
  ```
  - Expected output: `output/products.json` with ~250 products
  - Check terminal for: `✅ Done: 250 products saved`

- ⬜ **2.3** Inspect the output JSON
  - Open `output/products.json` in VS Code
  - Verify structure: top-level is a `{}` object (not `[]` array)
  - Spot-check 2-3 products manually:
    - Does `Id` exist?
    - Does `Name` include brand name?
    - Does `Price` look correct (decimal number)?
    - Do `Photos` have valid URLs?
    - Do `Features` have Name/Value pairs?
    - Does `Category` use `" - "` separator?

- ⬜ **2.4** Run FULL scrape (all ~1068 products)
  ```
  python scraper.py
  ```
  - Expected: `output/products.json` with ~1068 products
  - Takes ~30-60 seconds
  - Check `output/scraper.log` for any errors

- ⬜ **2.5** Validate the full output
  - Run the validator:
    ```
    python validate.py
    ```
  - Should show: total products, how many have features, how many have photos, etc.
  - Fix any issues before moving to Phase 3

---

## PHASE 3 — Local WordPress Test (localhost)

- ⬜ **3.1** Set up local WordPress (if not already)
  - Use **LocalWP** (free): https://localwp.com/
  - Or use existing XAMPP / Laragon / DevKinsta
  - Install WooCommerce on it

- ⬜ **3.2** Install `wc-product-sync` plugin on local WordPress
  - Upload the `wc-product-sync` folder to `wp-content/plugins/`
  - Activate in WordPress admin → Plugins

- ⬜ **3.3** Host the JSON file locally
  - Copy `output/products.json` to your local WordPress:
    `wp-content/uploads/sync/products.json`
  - Access URL will be: `http://localhost/wp-content/uploads/sync/products.json`
  - Verify it's accessible in browser

- ⬜ **3.4** Configure the plugin
  - Go to: WooCommerce → Product Sync → Settings
  - JSON URL: `http://localhost/wp-content/uploads/sync/products.json`
  - Enable Sync: ✅
  - Delete Behavior: Move to Trash (safe)
  - Save settings

- ⬜ **3.5** Run PILOT import (small batch test)
  - Create a test JSON with only 5 products:
    ```
    python scraper.py --sample 5
    ```
  - Copy to local WP uploads
  - Click "Run Sync Now" in plugin
  - Check: WooCommerce → Products → verify 5 products created

- ⬜ **3.6** Run FULL import
  - Copy full `products.json` to local WP uploads
  - Click "Run Sync Now" in plugin (will batch in groups of 50)
  - Wait for completion (may take 10-30 min for images download)
  - Check final stats: Created / Updated / Deleted / Errors

---

## PHASE 4 — Quality Check (localhost)

- ⬜ **4.1** Check product count
  - WooCommerce → Products → should show ~1068 products

- ⬜ **4.2** Spot-check 10 random products
  - Product name correct?
  - Brand (Producer) attribute set?
  - Price correct?
  - Images showing (featured + gallery)?
  - Spec attributes showing (fuel type, power, etc.)?
  - Category assigned correctly?

- ⬜ **4.3** Check category tree
  - WooCommerce → Products → Categories
  - Should have: Stromerzeuger, Energy Storage, Accessories with sub-categories

- ⬜ **4.4** Check product attributes
  - WooCommerce → Attributes
  - Should have: Producer, Fuel Type, Max Power, Phase, Engine Brand, etc.

- ⬜ **4.5** Test UPDATE sync
  - Manually change one product's `Price` in `products.json`
  - Run sync again
  - Verify price updated in WooCommerce

- ⬜ **4.6** Test DELETE sync
  - Remove one product from `products.json`
  - Run sync again
  - Verify product moved to trash in WooCommerce

---

## PHASE 5 — Fix & Polish (after QA)

- ⬜ **5.1** Fix any category mapping issues
  - Edit `sev-scraper/category_map.py` to correct any wrong categories
  - Re-run scraper and re-sync

- ⬜ **5.2** Fix any spec parsing issues
  - Review `output/scraper.log` for parsing warnings
  - Adjust `html_parser.py` if needed

- ⬜ **5.3** Handle description (optional)
  - Decide: do you want product descriptions imported?
  - If yes: requires small PHP change in plugin (add description field support)

---

## PHASE 6 — Go Live (after localhost confirmed working)

- ⬜ **6.1** Upload `products.json` to live WordPress server
  - Via FTP/SFTP to: `/wp-content/uploads/sync/products.json`

- ⬜ **6.2** Update plugin JSON URL to live server URL

- ⬜ **6.3** Run first sync on live site

- ⬜ **6.4** Set up daily automation (cron / Task Scheduler)

---

## QUICK COMMANDS REFERENCE

```bash
# Test run (1 page, ~250 products)
python scraper.py --test

# Full run (all pages)
python scraper.py

# Generate 5-product sample for pilot test
python scraper.py --sample 5

# Validate output
python validate.py
```

---

## FILES CREATED

```
scrapProject/
└── sev-scraper/
    ├── scraper.py          ← main entry point (run this)
    ├── fetcher.py          ← Shopify API pagination
    ├── transformer.py      ← field mapping to plugin JSON format
    ├── html_parser.py      ← spec table parser (BeautifulSoup)
    ├── category_map.py     ← product_type → WC category mapping
    ├── validate.py         ← validates output JSON
    ├── config.py           ← settings
    ├── requirements.txt    ← pip packages
    └── output/
        ├── products.json   ← generated output (plugin reads this)
        └── scraper.log     ← run log with errors/warnings
```
