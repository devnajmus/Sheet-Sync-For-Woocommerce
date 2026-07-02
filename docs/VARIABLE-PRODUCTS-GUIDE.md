# Variable Products — Sheet Layout & Sync Guide

**SheetSync for WooCommerce**

Use this guide when you sell products with **options** (Color, Size, etc.) — one parent product in WooCommerce with multiple **variations**.

---

## Table of contents

1. [Big picture](#1-big-picture)
2. [Required columns](#2-required-columns)
3. [Example sheet (hoodie)](#3-example-sheet-hoodie)
4. [Row order rules](#4-row-order-rules)
5. [Color & Size columns vs Variation Attributes](#5-color--size-columns-vs-variation-attributes)
6. [Parent row warning: “No price”](#6-parent-row-warning-no-price)
7. [Check sheet before sync](#7-check-sheet-before-sync)
8. [Common mistakes](#8-common-mistakes)
9. [After sync — verify in WooCommerce](#9-after-sync--verify-in-woocommerce)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. Big picture

```
ONE variable product in WooCommerce  =  MULTIPLE rows in Google Sheets

Row A: Parent   (type = variable, lists all colors/sizes)
Row B: Variation (parent SKU + one color + one size + price)
Row C: Variation (parent SKU + different color/size + price)
```

Customers see **one product page** with a Color/Size dropdown. Each dropdown combination is a **variation** with its own SKU, price, and stock.

---

## 2. Required columns

Map these in **Field Mapping** (names in row 1 can match your template):

| Column letter (recommended) | Field name | Parent row | Variation row |
|-----------------------------|------------|------------|---------------|
| A | SKU | `DEMO-HOODIE-01` | Optional (recommended unique SKU) |
| B | Product ID | *(filled after export)* | *(filled after export)* |
| C | Product Title | `Demo Variable Hoodie` | e.g. `Demo Hoodie — Red / S` |
| D | Regular Price | **Leave empty** | **Required** (e.g. `59.99`) |
| R | Product Type | `variable` | **Leave empty** |
| S | Categories | `Hoodies` | Optional |
| U | Parent SKU | **Leave empty** | **Required** — parent’s SKU |
| V | Variation Attributes | `pa_color:red,black\|pa_size:s,m` OR leave empty if using Color/Size | **Leave empty** if using Color/Size |
| W | Color | `red,black` (all options) | **One value** e.g. `red` |
| X | Size | `s,m` (all options) | **One value** e.g. `s` |

**Minimum for variations without column V:**

- **Parent SKU** (U) filled on variation rows  
- **Color** (W) and/or **Size** (X) filled  
- **Regular Price** (D) on each variation row  

---

## 3. Example sheet (hoodie)

### Row 2 — Simple product (for comparison)

| SKU | Title | Price | Type | Parent SKU | Color | Size |
|-----|-------|-------|------|------------|-------|------|
| DEMO-TSH-001 | Demo Cotton T-Shirt | 29.99 | simple | *(empty)* | *(empty)* | *(empty)* |

### Row 3 — Variable parent

| SKU | Title | Price | Type | Parent SKU | Color | Size |
|-----|-------|-------|------|------------|-------|------|
| DEMO-HOODIE-01 | Demo Variable Hoodie | *(empty)* | variable | *(empty)* | red,black | s,m |

### Row 4 — Variation 1

| SKU | Title | Price | Parent SKU | Color | Size |
|-----|-------|-------|------------|-------|------|
| DEMO-HOODIE-01-RED-S | Demo Hoodie — Red / S | 59.99 | DEMO-HOODIE-01 | red | s |

### Row 5 — Variation 2

| SKU | Title | Price | Sale | Parent SKU | Color | Size |
|-----|-------|-------|------|------------|-------|------|
| DEMO-HOODIE-01-BLACK-M | Demo Hoodie — Black / M | 40 | 30 | DEMO-HOODIE-01 | black | m |

**Rules this example follows:**

- Parent has **no price** (variations have prices).
- Parent **Color** lists all values: `red,black`.
- Parent **Size** lists all values: `s,m`.
- Each variation has **one** Color and **one** Size.
- **Parent SKU** on variations matches parent **SKU** exactly.
- SKU and title match the color/size (no “Blur” in title when Color is `black`).

---

## 4. Row order rules

| Rule | Why |
|------|-----|
| Parent row **above** its variations | Parent must exist before variations link |
| Simple products can be anywhere | No parent dependency |
| All variations of same parent grouped together | Recommended for readability (plugin also re-sorts on import) |

**Check sheet** should report: `1 simple, 1 variable parents, 2 variations` (for the 4-row demo).

---

## 5. Color & Size columns vs Variation Attributes

You can define attributes two ways:

### Option A — Color & Size columns (recommended for beginners)

- **Parent:** Color = `red,black` · Size = `s,m`
- **Variation:** Color = `red` · Size = `s`
- Leave column **U (Variation Attributes)** empty on variation rows

### Option B — Variation Attributes column (U) only

- **Parent:** `pa_color:red,black|pa_size:s,m`
- **Variation:** `pa_color:red|pa_size:s`

### Important

If column **U** has old/wrong values **and** Color/Size columns are filled, SheetSync uses **Color & Size on variation rows** (column U is ignored for variations).

**Best practice:** Leave **U empty** on variation rows when using Color (V) and Size (W).

---

## 6. Parent row warning: “No price”

When you run **Check sheet**, you may see:

> ⚠️ No price on a publish row — product will be saved as draft until price is set. **(Row 3)**

**This is normal for variable parents.**

| Row | Has price? | OK? |
|-----|------------|-----|
| Parent (row 3) | No | ✅ Yes — parent gets price range from variations |
| Variation rows | Yes | ✅ Required for purchasable variations |

You can ignore this warning if row 3 is your **variable parent**.

---

## 7. Check sheet before sync

**Connection → Sync tab → Check sheet before sync**

| Result | Meaning |
|--------|---------|
| ✅ Ready to sync · N rows | Structure OK |
| ✅ 1 simple, 1 variable parents, 2 variations | Counts match |
| ❌ Error rows | Fix before sync |
| ⚠️ Warnings | Often safe to continue (read message) |

Always run **Check sheet** after adding new variation rows.

---

## 8. Common mistakes

| Mistake | Symptom | Fix |
|---------|---------|-----|
| Parent SKU typo on variation | Variation not created | Match parent SKU exactly |
| Color on variation = `red,black` | Only one variation works | One value per variation row |
| Missing price on variation | Variation draft / missing | Set Regular Price on variation row |
| Title says “Blue” but Color = `red` | Confusing admin; matching issues | Keep title, SKU, Color consistent |
| Variation created as simple product | No parent link | Map Parent SKU, Color, Size; parent type = variable |
| Column U has `pa_color:red` on two rows with different Color column | Duplicate attribute skip | Clear U on variation rows; use V and W |
| Only waited 3 seconds after sheet edit | WC not updated | Wait 3+ min (Smart Poll) or **Sync Now** |
| Action Scheduler overdue | Sync stalls | [BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md) |

---

## 9. After sync — verify in WooCommerce

1. **Products → All Products** — find parent by SKU `DEMO-HOODIE-01`
2. Edit product → **Product data** → **Variable product**
3. Tab **Variations** — you should see **2 variations** (e.g. red/s and black/m)
4. Each variation: correct price, stock, SKU

If only **1 variation** appears:

1. **SheetSync → Sync Logs** — look for row 4 and row 5 messages  
2. Run **Sync Now** (keep tab open)  
3. Re-read [Troubleshooting](#10-troubleshooting)

---

## 10. Troubleshooting

| Problem | What to do |
|---------|------------|
| Only 1 of 2 variations | Sync Logs row 5; fix Parent SKU / Color / Size; **Sync Now** |
| Parent exists, no variations | Parent row must be `variable`; variations need Parent SKU |
| “Duplicate variation in sheet” | Two rows same parent + same Color/Size — keep one |
| “Parent SKU not found” | Import parent row first; run **Sync Now** again |
| Variation SKU conflict | SKU already used by another product — change SKU or delete old product |
| Attributes not in dropdown | Create attributes under **Products → Attributes** first |

---

## Related guides

- [GETTING-STARTED.md](GETTING-STARTED.md) — first setup  
- [BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md) — sync timing & cron  
- [USER-GUIDE-Google-Sheet-to-WooCommerce.md](USER-GUIDE-Google-Sheet-to-WooCommerce.md) — full manual  

---

*SheetSync for WooCommerce — Variable products guide*
