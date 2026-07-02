# SheetSync — Product Upload Guide (Google Sheet → WooCommerce)

This guide explains how to add and update **WooCommerce products** from a **Google Sheet** using SheetSync. It covers **simple products**, **variable products** (with variations), field meanings, and common mistakes.

> **New user?** Start here first: **[GETTING-STARTED.md](GETTING-STARTED.md)** (plugin activation → first upload).  
> **Getting started:** **[GETTING-STARTED.md](GETTING-STARTED.md)**

---

## Table of contents

1. [What you need before you start](#1-what-you-need-before-you-start)
2. [Quick start (5 steps)](#2-quick-start-5-steps)
3. [Sheet layout and headers](#3-sheet-layout-and-headers)
4. [Simple product — one row](#4-simple-product--one-row)
5. [Variable product — multiple rows](#5-variable-product--multiple-rows)
6. [Full field reference](#6-full-field-reference)
7. [Field mapping in WordPress](#7-field-mapping-in-wordpress)
8. [Running the import](#8-running-the-import)
9. [Sync instead of one-time import](#9-sync-instead-of-one-time-import)
10. [Troubleshooting](#10-troubleshooting)
11. [Sample file](#11-sample-file)

---

## 1. What you need before you start

| Requirement | Notes |
|-------------|--------|
| WordPress 6.0+ | |
| WooCommerce 7.0+ | Active and configured |
| SheetSync plugin | Activated |
| Google Service Account | JSON key pasted in **SheetSync → Settings** |
| Google Sheet | Shared with the service account email (Editor access) |
| Products connection | **SheetSync → Connections** — type **Products**, not Orders |

### Variable products only: create attributes first

For variable products (size, color, etc.), create attributes in WooCommerce **before** import:

1. Go to **Products → Attributes**
2. Add attributes (e.g. **Color**, **Size**)
3. Add terms for each (e.g. Color: `red`, `blue`; Size: `s`, `m`)

In the sheet, global attributes use the `pa_` prefix:

- Color → `pa_color`
- Size → `pa_size`

Use **lowercase slugs** in the sheet (`red`, `s`), not display labels (`Red`, `Small`).

---

## 2. Quick start (5 steps)

1. **Settings** — Paste Google Service Account JSON and save.
2. **Connections** — Create a **Products** connection (Spreadsheet ID, sheet tab name, header row number).
3. **Google Sheet** — Row 1 = headers (see [Section 3](#3-sheet-layout-and-headers)). Row 2+ = products.
4. **Import** — **SheetSync → Import / Export** → select connection → **View Import Headers** → map columns → **Start Import**.
5. **Check** — **Products → All Products** in WooCommerce and review the import log.

---

## 3. Sheet layout and headers

- **Row 1** = header row (column names).
- **Row 2 onward** = one or more product rows.
- Leave a row **completely empty** to skip it.
- **Do not fill order columns** on product sheets (Order ID, Customer Name, etc.). Those are for **Orders** connections only.

### Recommended product headers (in order)

| Column | Header name |
|--------|-------------|
| A | SKU (Product Key) |
| B | Product Title |
| C | Regular Price |
| D | Stock Quantity |
| E | Product Status (publish/draft) |
| F | Product Order |
| G | Sale Price |
| H | Short Description |
| I | Stock Status |
| J | Weight |
| K | Length |
| L | Width |
| M | Height |
| N | Long Description |
| O | Main Image (URL) |
| P | Gallery Images (comma-separated URLs) |
| Q | Product Type |
| R | Categories |
| S | Tags |
| T | Parent SKU (variable products) |
| U | Variation Attributes |
| V–AG | Order fields — **leave empty for products** |

You can import headers from SheetSync (**View Import Headers**) so column names match the plugin.

---

## 4. Simple product — one row

**One row in the sheet = one simple product.**

### Example row

| Field | Example value |
|--------|----------------|
| SKU | `TSH-001` |
| Product Title | `Blue Cotton T-Shirt` |
| Regular Price | `599` |
| Stock Quantity | `50` |
| Product Status | `publish` |
| Sale Price | `499` (optional) |
| Product Type | `simple` (or leave empty) |
| Categories | `T-Shirts` (comma-separated for multiple) |
| Tags | `cotton, summer` |
| Parent SKU | **empty** |
| Variation Attributes | **empty** |
| Order columns | **empty** |

### Rules

- **SKU** should be unique in your store.
- **Product Title** or **SKU** is required for new products.
- **Product Status**: `publish`, `draft`, or `private`.
- **Stock Status** (if used): `instock`, `outofstock`, or `onbackorder`.
- **Main Image**: full `https://` image URL (max ~5 MB; jpg, png, gif, webp).

---

## 5. Variable product — multiple rows

A variable product is **not** one row. You need:

1. **One parent row** — the main variable product  
2. **One row per variation** — each size/color combination (or other attribute combo)

### Row order (important)

Put rows in this order in the sheet:

1. Variable **parent** first  
2. Then all **variation** rows  

SheetSync sorts rows automatically when possible, but keeping parent first avoids failed imports.

---

### 5.1 Parent row

| Field | What to enter |
|--------|----------------|
| SKU | Parent SKU, e.g. `HOODIE-01` |
| Product Title | e.g. `Cotton Hoodie` |
| Product Type | `variable` |
| Variation Attributes | All options for this product — see format below |
| Parent SKU | **empty** |
| Regular Price / Stock | Usually **empty** on parent (set on variations) |
| Categories, Tags, Image | Set on parent as usual |
| Order columns | **empty** |

**Variation Attributes (parent)** — list every option, separated by `|`:

```text
pa_color:red,blue|pa_size:s,m
```

Meaning:

- Attribute `pa_color` has values `red` and `blue`
- Attribute `pa_size` has values `s` and `m`

Custom (non-global) attribute example:

```text
Brand:acme|Material:cotton
```

---

### 5.2 Variation row (one per variant)

| Field | What to enter |
|--------|----------------|
| SKU | Unique variation SKU, e.g. `HOODIE-01-RED-S` |
| Parent SKU | Parent’s SKU, e.g. `HOODIE-01` |
| Variation Attributes | **One value per attribute** — see format below |
| Regular Price | Variation price, e.g. `899` |
| Stock Quantity | e.g. `10` |
| Sale Price | optional |
| Product Type | **empty** |
| Product Title | optional (often empty) |
| Order columns | **empty** |

**Variation Attributes (variation row)** — one slug per attribute:

```text
pa_color:red|pa_size:s
```

Another variation:

```text
pa_color:red|pa_size:m
```

---

### 5.3 Full variable example (4 rows)

| Row | SKU | Title | Type | Parent SKU | Variation Attributes | Price | Stock |
|-----|-----|-------|------|------------|----------------------|-------|-------|
| 2 | TSH-001 | Blue T-Shirt | simple | | | 599 | 50 |
| 3 | HOODIE-01 | Cotton Hoodie | variable | | `pa_color:red,blue\|pa_size:s,m` | | |
| 4 | HOODIE-01-RED-S | | | HOODIE-01 | `pa_color:red\|pa_size:s` | 899 | 10 |
| 5 | HOODIE-01-RED-M | | | HOODIE-01 | `pa_color:red\|pa_size:m` | 899 | 8 |

---

## 6. Full field reference

| Sheet header | Required? | Simple product | Variable parent | Variation row |
|--------------|-------------|----------------|-----------------|---------------|
| **SKU (Product Key)** | Yes (recommended) | Unique SKU | Parent SKU | Variation SKU |
| **Product Title** | Yes for new* | Product name | Product name | Usually empty |
| **Regular Price** | No | Price | Usually empty | Variation price |
| **Stock Quantity** | No | Stock | Usually empty | Variation stock |
| **Product Status** | No | `publish` / `draft` | Same | Same |
| **Product Order** | No | Sort order number | Optional | Empty |
| **Sale Price** | No | Sale price | Empty | Optional |
| **Short Description** | No | Text | Text | Empty |
| **Stock Status** | No | `instock` etc. | Optional | `instock` etc. |
| **Weight / Length / Width / Height** | No | Dimensions | Optional | Empty |
| **Long Description** | No | HTML/text | Optional | Empty |
| **Main Image (URL)** | No | Image URL | Parent image | Empty |
| **Gallery Images** | No | `url1, url2` | Optional | Empty |
| **Product Type** | No | `simple` or empty | `variable` | **empty** |
| **Categories** | No | `Cat1, Cat2` | Categories | Empty |
| **Tags** | No | `tag1, tag2` | Tags | Empty |
| **Parent SKU** | No | **empty** | **empty** | **Parent SKU** |
| **Variation Attributes** | For variable | **empty** | All options | One combo |
| **Order ID → Customer Note** | No | **empty** | **empty** | **empty** |

\*New products need at least **SKU** or **Product Title**.

### Variation Attributes format (summary)

| Row type | Format | Example |
|----------|--------|---------|
| Parent | `attr:val1,val2\|attr2:val1,val2` | `pa_color:red,blue\|pa_size:s,m` |
| Variation | `attr:val\|attr2:val` | `pa_color:red\|pa_size:s` |

- Separator between attributes: `|` (pipe)  
- Separator between attribute name and values: `:` (colon)  
- Multiple values on parent only: `,` (comma)  

---

## 7. Field mapping in WordPress

1. Open **SheetSync → Import / Export**
2. Select your **Products** connection
3. Click **View Import Headers**
4. For each sheet column, choose the matching WooCommerce field

### Minimum mapping for variable products

| Sheet column | Map to |
|--------------|--------|
| SKU (Product Key) | SKU — enable **Key field** |
| Product Title | Product Title |
| Regular Price | Regular Price |
| Stock Quantity | Stock Quantity |
| Product Type | Product Type |
| Parent SKU (variable products) | Parent SKU |
| Variation Attributes | Variation Attributes |

Map other columns as needed (Sale Price, Categories, Images, etc.).

**Do not map** Order ID, Customer Name, or other order columns on a product import.

---

## 8. Running the import

1. **SheetSync → Import / Export**
2. Select connection → **View Import Headers** → confirm mapping
3. Options:
   - **Skip existing SKU** — if checked, rows whose SKU already exists in WooCommerce are not updated
   - **Create new products** — if checked, rows not found in WooCommerce are created
4. Click **Start Import**
5. Read the log: Created / Updated / Skipped / Errors

### After import, check in WooCommerce

- **Simple** — one product with correct price and stock  
- **Variable** — one parent product; open it and check **Variations** tab for each row  

---

## 9. Sync instead of one-time import

For ongoing updates from the sheet:

1. **Connections** → edit your Products connection  
2. Set **Sync direction** to **Google Sheets → WooCommerce** (or two-way)  
3. Save field maps on the connection  
4. Click **Sync Now** or enable scheduled sync  

Same row rules apply (parent before variations, correct `Variation Attributes` format).

---

## 10. Troubleshooting

| Problem | Likely cause | Fix |
|---------|----------------|-----|
| Variation skipped | Parent row below variations or parent SKU wrong | Move parent row up; check **Parent SKU** matches parent **SKU** |
| Invalid variation attributes | Wrong format or typo | Use `pa_color:red\|pa_size:s`; slugs must match WooCommerce terms |
| Attribute ignored | `pa_*` not in WooCommerce | Create attribute under **Products → Attributes** |
| Duplicate simple product | Variation row treated as simple | Fill **Parent SKU** and **Variation Attributes**; leave **Product Type** empty on variation rows |
| SKU exists but not variable | Same SKU already used by a simple product | Delete old product or use a new SKU |
| Image not imported | Bad URL or file too large | Use `https://` URL; image under 5 MB |
| Categories missing | Name typo | Categories are created if they do not exist |
| Order columns filled | Wrong sheet type | Clear order columns; use an Orders connection for orders |
| Parent has no price on shop | Normal for variable | Prices come from variations; check variation rows have **Regular Price** |

### Import log messages (examples)

- `Parent SKU "X" not found` — add or move parent row above variations  
- `Invalid or empty variation attributes` — check column U format  
- `SKU exists but is not variable` — SKU already used by a simple product  

---

## 11. Sample file

A ready-to-test CSV is included in the plugin folder:

```text
sample-import-test-products.csv
```

It contains:

- 1 simple product (`TSH-001`)  
- 1 variable parent (`HOODIE-01`)  
- 2 variations (`HOODIE-01-RED-S`, `HOODIE-01-RED-M`)  

**How to use**

1. Upload the CSV to Google Sheets, or copy rows into your connected sheet  
2. Ensure WooCommerce has attributes **Color** (`pa_color`) and **Size** (`pa_size`) with terms `red`, `blue`, `s`, `m`  
3. Map columns and run import as in [Section 8](#8-running-the-import)  

---

## Quick checklist before import

- [ ] Google Sheet shared with service account  
- [ ] Products connection (not Orders)  
- [ ] Row 1 = headers; data from row 2  
- [ ] SKU mapped as **Key field**  
- [ ] Simple: `Product Type` = simple; Parent SKU and Variation Attributes empty  
- [ ] Variable: parent row first; `Product Type` = variable  
- [ ] Variations: Parent SKU + Variation Attributes + price/stock per row  
- [ ] Order columns empty on all product rows  
- [ ] Global attributes exist in WooCommerce for every `pa_*` used in the sheet  

---

*SheetSync for WooCommerce — Product Upload Guide*
