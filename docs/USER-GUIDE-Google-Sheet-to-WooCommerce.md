# SheetSync for WooCommerce
## User Guide: Upload Products from Google Sheets

**Version:** 1.0 (User documentation)  
**Plugin:** SheetSync for WooCommerce  
**Audience:** Shop owners — no coding required

---

## Table of Contents

1. [What This Plugin Does](#1-what-this-plugin-does)
2. [What You Need Before You Start](#2-what-you-need-before-you-start)
3. [One-Time Setup (About 15 Minutes)](#3-one-time-setup-about-15-minutes)
4. [Daily Workflow: Sheet → WooCommerce](#4-daily-workflow-sheet--woocommerce)
5. [Simple Products (One Row = One Product)](#5-simple-products-one-row--one-product)
6. [Variable Products (Parent + Variations)](#6-variable-products-parent--variations)
7. [Product Images (Featured + Gallery)](#7-product-images-featured--gallery)
8. [Sync Buttons Explained](#8-sync-buttons-explained)
9. [Check Sheet (Validate Before Sync)](#9-check-sheet-validate-before-sync)
10. [Troubleshooting](#10-troubleshooting)
11. [Quick Reference Checklist](#11-quick-reference-checklist)
12. [WooCommerce → Google Sheets (Export Catalog)](#12-woocommerce--google-sheets-export-catalog)
13. [Two-Way Sync](#13-two-way-sync)
14. [Large Catalogs (1,000+ Products)](#14-large-catalogs-1000-products)
15. [Background Sync & Cron (Important)](#15-background-sync--cron-important)
16. [Variable Products (Detailed)](#16-variable-products-detailed)

**More documentation:** See **[DOCUMENTATION-INDEX.md](DOCUMENTATION-INDEX.md)** in the plugin `docs/` folder.

---

## 1. What This Plugin Does

SheetSync connects **Google Sheets** to your **WooCommerce** store.

```
You edit products in Google Sheets
        ↓
SheetSync reads each row
        ↓
Products are created or updated in WooCommerce
```

**You manage products in a spreadsheet.**  
**Customers still shop on your normal WooCommerce website.**

---

## 2. What You Need Before You Start

| Requirement | Notes |
|-------------|--------|
| WordPress 6.0+ | Your site must be up to date |
| WooCommerce | Installed and active |
| Google account | For Google Sheets |
| Google Cloud project | Free tier is enough for most shops |
| SheetSync plugin | Installed and activated in WordPress |

---

## 3. One-Time Setup (About 15 Minutes)

### Step A — Create a Google Service Account

1. Open [Google Cloud Console](https://console.cloud.google.com/)
2. Create or select a project
3. Enable **Google Sheets API** (APIs & Services → Library)
4. Go to **IAM & Admin → Service Accounts**
5. Create a service account
6. Create a **JSON key** and download the file
7. Open the JSON file and copy the **client_email** (looks like:  
   `something@your-project.iam.gserviceaccount.com`)

### Step B — Share Your Google Sheet

1. Open your product Google Sheet
2. Click **Share**
3. Paste the **service account email**
4. Set permission to **Editor**
5. Click **Send / Share**

> **Important:** Without this step, SheetSync cannot read or write your sheet.

### Step C — Add JSON to WordPress

1. In WordPress admin, go to **SheetSync → Settings**
2. Paste the **entire JSON key** into the Service Account field
3. Click **Save**
4. (Optional) Use **Test image URL** with any image link to confirm your server can download images

### Step D — Create a Product Connection

1. Go to **SheetSync → Connections**
2. Click **Add Connection**
3. Fill in:

| Field | What to enter |
|-------|----------------|
| Connection name | Any friendly name (e.g. "My Product Sheet") |
| Connection type | **Products** (not Orders) |
| Spreadsheet ID | From your sheet URL (see below) |
| Sheet tab name | Exact tab name at the bottom (e.g. `Sheet1`) |
| Header row | Usually `1` if row 1 has column titles |
| Sync direction | **Sheets → WooCommerce** |

**Finding the Spreadsheet ID**

From a URL like:

`https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms/edit`

Copy the middle part: `1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms`

4. Click **Test Connection** — you should see success
5. **Save** the connection

### Step E — Write the Product Template to Your Sheet

1. Open your connection → **Sync** tab
2. Click **Write template to Google Sheet**
3. Your sheet will receive:
   - Styled column headers (row 1)
   - Three example product rows (rows 2–4)
   - A **SheetSync Help** tab with instructions
4. Field mapping is applied automatically (Recommended columns)

### Step F — WooCommerce Attributes (For Variable Products)

If you sell products with **Color** and **Size** (or similar):

1. Go to **Products → Attributes**
2. Add attribute **Color** → add terms: `red`, `blue`, `black` (lowercase)
3. Add attribute **Size** → add terms: `s`, `m`, `l` (lowercase)

You only do this once.

---

## 4. Daily Workflow: Sheet → WooCommerce

```
┌─────────────────────────────────────────────────────────┐
│  1. Edit your Google Sheet (prices, stock, images…)     │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  2. WordPress → SheetSync → Connections → Edit → Sync   │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  3. Click "Check sheet" — fix any errors shown          │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  4. Run the right sync button (see Section 8)           │
└───────────────────────────┬─────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────┐
│  5. WooCommerce → Products — verify your products       │
└─────────────────────────────────────────────────────────┘
```

**First time importing?** Use **Full sync (recommended first run)**.  
**Only changed prices/stock later?** Use **Update existing products**.

---

## 5. Simple Products (One Row = One Product)

Use **one row** per simple product.

| Column | Example | Notes |
|--------|---------|--------|
| SKU | `TSH-001` | Required — unique ID |
| Product Title | `Blue T-Shirt` | Shown in your store |
| Regular Price | `29.99` | Numbers only |
| Stock Qty | `50` | |
| Status | `publish` | or `draft` |
| Product Type | `simple` | |
| Featured Image URL | `https://…` | Main product photo |
| Gallery Image URLs | `url1, url2` | Optional — comma-separated |
| Parent SKU | *(empty)* | Leave blank |
| Color / Size | *(empty)* | Leave blank for simple |

---

## 6. Variable Products (Parent + Variations)

A variable product uses **multiple sheet rows**:

| Row type | Product Type | Parent SKU | Color | Size | Price |
|----------|--------------|------------|-------|------|-------|
| **Parent** | `variable` | empty | `red,black` | `s,m` | empty |
| **Variation** | *(empty)* | `HOODIE-01` | `red` | `s` | `59.99` |
| **Variation** | *(empty)* | `HOODIE-01` | `red` | `m` | `59.99` |

### Rules

1. **Parent row must come before** variation rows in the sheet
2. Each variation needs its **own SKU** (e.g. `HOODIE-01-RED-S`)
3. Use **Color** and **Size** columns — you do **not** need to type `pa_color` or `pa_size`
4. On the parent row, list all options: `red,black` and `s,m` (comma-separated)
5. On each variation row, use **one** color and **one** size

### Why sync says "3 rows updated" when you edited one row

SheetSync counts **sheet rows**, not "product families":

- 1 simple product = **1 row**
- 1 variable product with 1 variation = **2 rows** (parent + variation)
- Demo template with 1 simple + 1 variable + 1 variation = **3 rows**

If three rows still differ from WooCommerce (e.g. missing images), all three may update until they match.

---

## 7. Product Images (Featured + Gallery)

### Featured Image URL (main photo)

You can use any of these in the sheet:

| Format | Example |
|--------|---------|
| Full URL | `https://yoursite.com/wp-content/uploads/2026/05/shirt.jpg` |
| Media Library URL | Copy from **Media → Library → image → Copy URL** |
| Attachment ID | `1234` (the number from the media item) |
| External image | `https://example.com/photo.jpg` |

### Gallery Image URLs

Enter **comma-separated** URLs or IDs:

```
https://yoursite.com/.../image1.jpg, https://yoursite.com/.../image2.jpg
```

Or: `1234, 1235`

### Tips

- WordPress **Media Library** images are **reused** (not uploaded twice)
- Test your server: **SheetSync → Settings → Test image URL**
- If images fail, your hosting may block outbound downloads — contact your host or use images already in your Media Library

---

## 8. Sync Buttons Explained

| Button | When to use |
|--------|-------------|
| **Import new products only** | Only creates products whose **SKU does not exist** yet in WooCommerce |
| **Update existing products** | Updates price, stock, images, etc. for SKUs that **already exist** |
| **Full sync (recommended first run)** | Imports or refreshes **every row** — best after template setup or when images did not apply |
| **Sync Now** (Connections list) | Quick full pull from sheet (same as full sync on the connection) |

**Sync Strategy (Smart Diff vs Full)**

- **Smart Diff** — Skips rows that are already identical in WooCommerce (faster)
- **Full Sync** — Processes every row (slower, use when fixing issues)

---

## 9. Check Sheet (Validate Before Sync)

Before syncing, click **Check sheet** on the Sync tab.

It checks for:

- Duplicate SKUs in the sheet
- Missing parent rows for variations
- Color/Size values not set up in WooCommerce
- Products blocking variations (wrong product type with same SKU)
- Missing image column mapping

| Result | Meaning |
|--------|---------|
| Green / Ready | Safe to sync |
| Warnings | Fix recommended — may still sync |
| Errors | Fix before sync |

---

## 10. Troubleshooting

| Problem | Solution |
|---------|----------|
| Cannot connect to sheet | Share sheet with service account email as **Editor** |
| Check sheet: term not found | Add terms under **Products → Attributes → Configure terms** |
| Duplicate SKU error | Delete or trash the old WooCommerce product with that SKU |
| Variation created as simple | Map **Parent SKU**, **Color**, **Size**; parent row must be above variations — see [VARIABLE-PRODUCTS-GUIDE.md](VARIABLE-PRODUCTS-GUIDE.md) |
| Only 1 variation, expected 2 | Check **Sync Logs** for row errors; run **Sync Now**; see [VARIABLE-PRODUCTS-GUIDE.md](VARIABLE-PRODUCTS-GUIDE.md) |
| “No price” warning on parent row (row 3) | **Normal** for variable parents — prices go on variation rows |
| Sheet updated, WooCommerce not | Smart Poll = ~3 min wait, or **Sync Now** — see [BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md) |
| “Background tasks overdue” | **Settings → Background cron URL** + [BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md) |
| Product has title but no price/SKU | **Sync Now** with tab open; check Field Mapping; fix cron |
| No images on products | Map **Featured Image URL** and **Gallery** columns; run **Full sync**; test URL in Settings |
| Test image: HTTP 405 | Normal for some sites (e.g. picsum) — sync uses GET and may still work |
| "3 synced" but I edited 1 row | See Section 6 — count is **sheet rows**, not single products |
| Sync says success but no change | Use **Update existing** if SKU exists; use **Full sync** if first import |
| Large export stopped early | Fix **Action Scheduler** (Section 15); run **Export to sheet (Full)** again |
| Two-Way first run confused me | First **Full Sync** only exports WC → sheet; use **Smart Diff** after |

**View detailed errors:** **SheetSync → Sync Logs** (or **Logs** on the connection card)

---

## 11. Quick Reference Checklist

### One-time setup

- [ ] Google Sheets API enabled
- [ ] Service account JSON downloaded
- [ ] Sheet shared with service account (Editor)
- [ ] JSON saved in SheetSync Settings
- [ ] Product connection created and tested
- [ ] Template written to sheet
- [ ] Attributes created (if using variable products)

### Each import / update

- [ ] Sheet row 1 = headers (do not delete)
- [ ] Product data from row 2 downward
- [ ] SKUs are unique
- [ ] Check sheet — no errors
- [ ] Correct sync button (Full / New / Update)
- [ ] Verify in **Products → All Products**

---

## 12. WooCommerce → Google Sheets (Export Catalog)

Use this when your products **already live in WooCommerce** and you want a spreadsheet to edit prices, stock, or images.

### Connection settings

| Field | Value |
|-------|--------|
| Connection type | **Products** |
| Sync direction | **WooCommerce → Google Sheets** |

### Steps

1. **Field Mapping** — save Recommended columns (SKU, Title, Price, Stock, Images, Color, Size).
2. **Sync tab → Sync Strategy** — choose **Full Sync** for the first export.
3. Click **Export to sheet (Full — first run)**.
4. Watch the **progress bar** (exported rows vs total). Large stores continue in the background.
5. Open Google Sheet and confirm rows match your catalog (products **and** variations each get a row).

### After the first export

- Edit the sheet, then either:
  - Change this connection to **Sheets → WooCommerce** and use **Update existing**, or
  - Use a **Two-Way** connection with **Smart Diff** (see Section 13).
- For day-to-day WC-only changes, use **Sync changes only (Smart Diff)** on the same export connection.

---

## 13. Two-Way Sync

**Two-Way** keeps the sheet and WooCommerce in sync over time.

| When | What happens |
|------|----------------|
| **First run + Full Sync** | Exports WooCommerce → sheet only (bootstrap). Does **not** import blank sheet rows into WooCommerce. |
| **Later + Smart Diff** | **Pull:** sheet changes → WooCommerce. **Push:** WooCommerce changes → sheet. |
| **Auto Sync on Product Save** | Optional — pushes a product to the sheet when you save it in WooCommerce. |

### Recommended setup

1. Create connection with direction **Two-Way**.
2. **Full Sync** + **Export to sheet** once (populate the sheet).
3. Edit products in the sheet or in WooCommerce.
4. Run **Sync changes only (Smart Diff)** or enable scheduled sync.

> **Tip:** For new users, two separate connections (one export-only, one import-only) are easier than one Two-Way connection.

---

## 14. Large Catalogs (1,000+ Products)

SheetSync does **not** write all rows in one click. It uses **batches** and **background jobs** (Action Scheduler).

```
Sync Now
  → First batches run immediately (progress bar)
  → Remaining batches queue in the background
  → Each batch exports ~50–100 rows
```

### Before a large export

1. Fix **Action Scheduler** if WooCommerce shows overdue tasks:  
   **WooCommerce → Status → Scheduled Actions** — run pending actions.
2. Ensure hosting allows **WP-Cron** or a real server cron.
3. Share the sheet with the service account as **Editor**.

### Row count vs product count

| Store type | Sheet rows (approx.) |
|------------|----------------------|
| 1,000 simple products | ~1,000 rows |
| Variable products | **More rows** — each variation is its own row |
| Example: 1,036 parents only | Export may be **more than 1,036** if variations are included |

The sync message counts **sheet rows updated**, not “product families.” One variable product can be 3+ rows (parent + variations).

### If export stops early

- Check **SheetSync → Sync Logs**
- Fix Action Scheduler backlog — see [BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md)
- Run **Full Sync** again (Export to sheet)

---

## 15. Background Sync & Cron (Important)

**Read the full guide:** [BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md)  
**বাংলা:** [ব্যাকগ্রাউন্ড-সিঙ্ক-ও-ক্রন-গাইড.md](ব্যাকগ্রাউন্ড-সিঙ্ক-ও-ক্রন-গাইড.md)

### Short summary

| You want… | Do this |
|-----------|---------|
| Import now | **Sync Now** — keep browser tab open |
| Sheet edits auto-update | Enable **real-time** (Smart Poll, ~3 min) + working cron |
| Fix “overdue tasks” warning | **SheetSync → Settings → Background cron URL** — schedule every 5 min |
| Instant updates (seconds) | Apps Script webhook (Pro) on connection Sync tab |

### External Cron URL (production stores)

1. **SheetSync → Settings** → **Background cron URL**
2. Click **Run queue now (test)**
3. Copy **Cron URL**
4. Enable **Allow background cron URL** → Save
5. Add to server cron every 5 minutes:

```bash
*/5 * * * * curl -fsS -m 60 "YOUR_CRON_URL_FROM_SETTINGS" >/dev/null 2>&1
```

---

## 16. Variable Products (Detailed)

**Full guide:** [VARIABLE-PRODUCTS-GUIDE.md](VARIABLE-PRODUCTS-GUIDE.md)

Quick rules:

- One **parent** row (`Product Type` = `variable`) + one row per **variation**
- Parent: Color = `red,black` · Size = `s,m` · **no price**
- Variation: **Parent SKU** filled · one Color · one Size · **price required**
- Run **Check sheet** before sync
- “No price on row 3” warning on parent = **OK**

---

## Support Notes for Shop Owners

- Keep a **backup** of your sheet before large imports
- Delete or replace the **demo rows** (rows 2–4) after testing with your real products
- Do not put order-related columns (Order ID, Customer Name, etc.) on a **product** sheet — leave them empty
- For help, check the **SheetSync Help** tab in your Google Sheet after writing the template

---

*SheetSync for WooCommerce — Google Sheet to WooCommerce product sync*  
*This document may be printed or saved as PDF for your customers.*
