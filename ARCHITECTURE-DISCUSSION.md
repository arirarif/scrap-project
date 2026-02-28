# Architecture Discussion: SEV Scraper → WooCommerce Automation

**Date:** 2026-02-27
**Status:** Discussion / Planning
**Project:** generaatorid.ee — automated product sync from sev-stromerzeuger.com

---

## Where We Are Now

What works today:
- Python scraper runs locally → writes `products.json` (1069 products, 2.4 MB)
- `wc-product-import-plugin-main` reads `{uploads}/products.json` from the WordPress server
- Plugin creates/updates/deletes WooCommerce products based on JSON diff (MD5 hash per product)
- The plugin correctly handles: images, categories, attributes (spec features), pricing, stock

The remaining problem: the scraper only runs when you manually run it on your PC. The goal is full automation — no human action needed for daily updates.

---

## The Three Paths Forward

### Path 1 — GitHub Actions (Recommended for most people)

**How it works:**
1. Push the `sev-scraper/` folder to a GitHub repository
2. A GitHub Actions workflow runs the scraper automatically 3× per day (free)
3. After each run, the Action commits the updated `products.json` back to the repo
4. A second step in the Action deploys the JSON file to your WordPress server via FTP/SFTP
5. WordPress plugin reads the local file → syncs changes

```
[GitHub Actions cron]
       ↓ runs
[Python scraper] → products.json
       ↓ FTP upload
[generaatorid.ee server] /wp-content/uploads/products.json
       ↓ WP cron (daily)
[wc-product-import-plugin] → WooCommerce products
```

**Cost:** Free (GitHub Actions free tier = 2000 min/month; our scraper runs in ~2 min)
**Effort to set up:** Medium — requires writing a `.github/workflows/scrape.yml` file and setting FTP credentials as GitHub Secrets
**Ongoing maintenance:** Zero — fully hands-off once running

**Pros:**
- Zero monthly cost
- Entire scraper history tracked in git (you see what changed each run)
- Scraper runs on GitHub's fast servers (not your laptop)
- No server to maintain

**Cons:**
- Requires FTP/SFTP access to your WordPress hosting (most hosting provides this)
- If GitHub is unreachable from the WP server, FTP push from GitHub → server solves it (GitHub pushes TO the server, server doesn't pull from GitHub)

---

### Path 2 — Render.com Free Cron (Simplest cloud option)

**How it works:**
1. Push the `sev-scraper/` folder to a GitHub repo
2. Create a free Render.com account → add a "Cron Job" service
3. Render runs the scraper on your schedule (e.g., `0 6,12,18 * * *` = 3× daily)
4. After the run, the scraper FTPs the JSON to your WordPress server OR pushes to a public URL
5. Plugin reads the file

**Cost:** Free (Render free tier includes cron jobs)
**Effort to set up:** Low — connect GitHub repo, set environment variables, done
**Ongoing maintenance:** Zero

**Pros:**
- Easiest cloud setup (no YAML files, just a web UI)
- Built-in logs and run history
- Can set environment variables (FTP credentials, etc.) securely in the Render dashboard

**Cons:**
- Free tier has cold start limits (fine for cron jobs that run fully then stop)
- Need to add FTP upload logic to the Python scraper

---

### Path 3 — All-in-One WordPress Plugin (Most integrated)

**How it works:**
Everything lives inside WordPress. The plugin has two modules:

1. **Scraper module (new — built in PHP):**
   - Hits the Shopify API (`/collections/all/products.json?limit=250&page=N`)
   - Parses `body_html` for spec features (PHP DOMDocument instead of Python BeautifulSoup)
   - Applies the category map
   - Stores raw scraped data in a WordPress option or transient

2. **Importer module (existing — what we built):**
   - Takes the scraped data
   - Creates/updates/deletes WooCommerce products
   - Handles images, attributes, categories

The flow becomes: one plugin, one WP Admin page, one button ("Scrape & Import") — or runs fully automatically via WP Cron.

```
[WP Cron / Admin Button]
       ↓
[PHP Scraper] → fetches Shopify API → parses HTML → stores raw data
       ↓
[PHP Importer] → creates/updates WooCommerce products
```

**No external servers, no JSON files, no FTP, no GitHub.**

**Cost:** Zero (runs on the existing WordPress server)
**Effort to build:** High — requires rewriting the scraper in PHP (~300 lines, doable)
**Ongoing maintenance:** Zero once built — all inside WordPress

**Pros:**
- Single system — everything in WP Admin
- No dependency on external services (GitHub, Render, VPS)
- Works even if server can't reach GitHub (server CAN reach Shopify's API — it's a different domain)
- Updates are instant — scrape + import in one action
- No JSON file to manage
- Non-technical staff can run it from the WordPress dashboard
- Plugin can show scrape logs AND import logs in the same UI

**Cons:**
- Requires building the PHP scraper (1-2 days of development)
- PHP scraping has timeout risks (same ones we've been fighting) — must use batched approach
- HTML parsing in PHP (DOMDocument) is more verbose than Python BeautifulSoup
- Harder to debug than a standalone Python script

---

## Comparison Table

| Aspect | Path 1: GitHub Actions | Path 2: Render.com | Path 3: WP Plugin |
|---|---|---|---|
| **Cost** | Free | Free | Free |
| **Setup time** | ~2 hours | ~1 hour | ~2 days |
| **External dependency** | GitHub | Render.com | Shopify API only |
| **JSON file needed?** | Yes (local on WP server) | Yes (local on WP server) | No |
| **Runs on** | GitHub's servers | Render's servers | Your WP server |
| **Scraper language** | Python (existing) | Python (existing) | PHP (needs rewrite) |
| **WP Admin integration** | Partial (import only) | Partial (import only) | Full |
| **Maintenance** | Zero | Zero | Zero |
| **If Shopify changes HTML** | Edit Python file, push | Edit Python file, push | Edit PHP file |
| **Audit trail / history** | Git commits | Render logs | WP logs |
| **Risk level** | Low | Low | Medium (new code) |

---

## Recommendation

**Short term (this week):** Go with Path 1 or Path 2.

The scraper is already built and working. Adding a GitHub Actions workflow or Render cron takes a few hours. The JSON gets updated automatically, pushed to your server, and the WordPress plugin runs daily. Done.

**Long term (next month):** Build Path 3 — the all-in-one WordPress plugin.

Once the scraping is stable and running, converting it to an all-in-one WP plugin is the cleanest end state. No moving parts, no external services, everything in one place. The scraper logic is ~300 lines of PHP, and we already have the importer working.

**Suggested milestone plan:**

| Week | Action |
|---|---|
| This week | Set up GitHub Actions or Render cron → daily automation running |
| Next 2 weeks | Run and verify: products stay in sync, new products appear, removed products get trashed |
| Month 2 | Build the all-in-one WP plugin (PHP scraper + existing importer in one plugin) |
| Month 2 end | Switch to all-in-one plugin, decommission the Python scraper |

---

## What the All-in-One WP Plugin Would Look Like

### Admin UI (single page)

```
WooCommerce → Product Sync

┌─────────────────────────────────────────────────┐
│  Source: sev-stromerzeuger.com (Shopify)        │
│  Last scraped: Today 06:15 — 1069 products      │
│  Last imported: Today 06:17 — 3 updated, 0 new  │
├─────────────────────────────────────────────────┤
│  [▶ Scrape Now]  [⟳ Import Now]  [▶ Scrape & Import] │
├─────────────────────────────────────────────────┤
│  Schedule: 3x daily at 06:00, 12:00, 18:00      │
│  Delete behavior: Move to Trash                 │
├─────────────────────────────────────────────────┤
│  Progress:  ████████████░░░░  67%               │
│  Fetching page 3 of 5...                        │
└─────────────────────────────────────────────────┘
```

### Plugin structure (new plugin)

```
wc-sev-sync/
├── wc-sev-sync.php                    ← main plugin file
├── includes/
│   ├── class-scraper.php              ← NEW: PHP Shopify scraper
│   ├── class-html-parser.php          ← NEW: PHP spec table parser
│   ├── class-category-map.php         ← NEW: PHP category mapping
│   ├── class-product-mapper.php       ← existing (from wc-product-import-plugin-main)
│   ├── class-sync-handler.php         ← existing (with minor changes)
│   ├── class-json-fetcher.php         ← REMOVED (no JSON file needed)
│   ├── class-admin-settings.php       ← extended with scraper settings
│   ├── class-cron.php                 ← extended with scraper cron
│   └── class-logger.php               ← existing
└── assets/
    ├── js/admin.js                    ← extended with scrape progress
    └── css/admin.css                  ← existing
```

### Scraping flow (batched, safe for PHP)

```
Step 1: Fetch all product IDs
  → GET /collections/all/products.json?limit=250&page=1 → page 2 → ... page 5
  → Store all 1069 product IDs in WordPress transient (expires in 2h)
  → AJAX returns: "Fetched 1069 product IDs from 5 pages"

Step 2–N: Process each product (batch of 50)
  → Read each product's full data from the already-fetched API response
  → Parse body_html for spec features (PHP DOMDocument)
  → Apply category map
  → Immediately create/update the WooCommerce product
  → Store MD5 hash to skip unchanged products next time
  → AJAX returns: "Processed 50/1069, 3 updated, 47 skipped"

Final: Cleanup
  → Products in WooCommerce but not in Shopify → trash
  → AJAX returns: "Complete. Created 3, Updated 41, Skipped 1025, Trashed 0"
```

---

## Key Technical Decisions

### 1. JSON file: keep it or drop it?

- **Path 1 & 2:** Keep it. It's a useful intermediate layer — you can inspect it, version it, restore it.
- **Path 3 (WP plugin):** Drop it. The plugin writes directly to WooCommerce, no intermediate file.

### 2. Image handling

Currently the plugin downloads all images during import. This is the slowest part.

Future improvement (regardless of path): **Lazy image import**
- First import: create products WITHOUT images (fast, <2 min for 1069 products)
- Background job: download images in batches of 10 (runs via WP Cron over the next hour)
- Result: products appear in the store immediately, images fill in over the next hour

This is a ~50 line PHP change to the product mapper.

### 3. Category handling

The `category_map.py` has 47 entries. In the WP plugin, this becomes a PHP array in `class-category-map.php`. Identical logic, different language.

### 4. Spec features / attributes

The `html_parser.py` uses BeautifulSoup to extract `<table>` → spec rows from `body_html`. In PHP, the equivalent is:

```php
$dom = new DOMDocument();
@$dom->loadHTML($body_html);
$tables = $dom->getElementsByTagName('table');
```

More verbose than Python but 100% equivalent functionality.

---

## Next Steps (Decide Today)

**Question 1:** For the short-term fix (this week), do you prefer:
- `[ ]` **GitHub Actions** — scraper runs on GitHub, pushes JSON to your server via FTP
- `[ ]` **Render.com** — scraper runs on Render's free cron, pushes JSON to your server
- `[ ]` **Same server** — if your WordPress hosting allows Python + cron (SSH access needed)

**Question 2:** For the long-term (next month), do you want to build the all-in-one WP plugin?
- `[ ]` Yes — build `wc-sev-sync` as a dedicated all-in-one plugin
- `[ ]` No — keep the Python scraper + WP importer plugin as separate tools

**Question 3:** For images, do you want lazy loading (products appear instantly, images fill in later)?
- `[ ]` Yes — faster initial import, better UX
- `[ ]` No — keep current behavior (products + images imported together, slower)

---

*Discussion document created: 2026-02-27*
*Next: finalize decisions → build automation*
