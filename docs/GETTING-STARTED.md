# SheetSync — Getting Started (From Zero to First Product Upload)

**Who is this for?** Store owners and staff who use SheetSync to manage WooCommerce products in **Google Sheets**.

**Fastest path:** Use **SheetSync → ✨ Setup Wizard** for a guided 7-step flow (Google JSON → sheet URL → workflow → first sync). Manual steps below are still available.

**Documentation hub:** [DOCUMENTATION-INDEX.md](DOCUMENTATION-INDEX.md) — all guides including **background sync & cron**.

**What will you learn?**

1. What to do **right after activating** the plugin  
2. How to **connect** Google Sheets  
3. How to **upload products** from the sheet (simple + variable)  
4. What **not** to put in the sheet  

For detailed field-by-field help, see **[PRODUCT-UPLOAD-GUIDE.md](PRODUCT-UPLOAD-GUIDE.md)**.

---

## Part A — After you activate the plugin

Do these steps **once**, in order.

### Step A1 — Check requirements

| Item | Status |
|------|--------|
| WordPress 6.0 or newer | ☐ |
| WooCommerce installed and active | ☐ |
| PHP 8.0+ with OpenSSL | ☐ |
| A Google account and one Google Sheet | ☐ |

### Step A2 — Create a Google Service Account (for Sheets API)

1. Open [Google Cloud Console](https://console.cloud.google.com/).
2. Create or select a project.
3. Enable **Google Sheets API** for that project.
4. Go to **IAM & Admin → Service Accounts** → create a service account.
5. Create a **JSON key** and download the file.
6. Copy the service account **email** (looks like `something@project-id.iam.gserviceaccount.com`).

### Step A3 — Share your Google Sheet with that email

1. Open the Google Sheet you will use for products.
2. Click **Share**.
3. Paste the **service account email**.
4. Give **Editor** access.
5. Save.

Without this step, SheetSync cannot read or write your sheet.

### Step A4 — Paste the JSON key in WordPress

1. In WordPress admin, go to **SheetSync → Settings**.
2. Paste the full **Service Account JSON** into the field.
3. Click **Save**.

### Step A5 — Create a Products connection

1. Go to **SheetSync → Connections**.
2. Click **Add New** (or similar).
3. Fill in:
   - **Connection name** — e.g. `My Product Sheet`
   - **Connection type** — **Products** (not Orders)
   - **Spreadsheet ID** — from the sheet URL:  
     `https://docs.google.com/spreadsheets/d/`**`THIS_PART`**`/edit`
   - **Sheet tab name** — usually `Sheet1` (bottom tab in Google Sheets)
   - **Header row** — usually `1` (if row 1 has column titles)
4. **Sync direction** (for later):
   - One-time upload: any direction is OK if you only use **Import**
   - Ongoing updates from sheet: choose **Google Sheets → WooCommerce**
5. Save the connection.

### Step A6 — Prepare your sheet headers (row 1)

Row 1 must be **column titles**. Recommended titles (you can import them from the plugin):

| Column | Title in sheet |
|--------|----------------|
| A | SKU (Product Key) |
| B | Product Title |
| C | Regular Price |
| D | Stock Quantity |
| E | Product Status (publish/draft) |
| … | (see full list in [PRODUCT-UPLOAD-GUIDE.md](PRODUCT-UPLOAD-GUIDE.md)) |

**Tip:** In **SheetSync → Import / Export**, select your connection and click **View Import Headers** to load standard headers into your sheet.

You are now ready to add product rows and import.

---

## Part B — How product upload works (big picture)

```text
Google Sheet (you edit products in rows)
        ↓
SheetSync reads each row
        ↓
Maps columns → WooCommerce fields (SKU, price, stock, etc.)
        ↓
Creates or updates products in WooCommerce
```

- **One row** can be one **simple** product.  
- **Variable** products need **one parent row** + **one row per variation** (size/color, etc.).  
- **Order columns** (Order ID, Customer Name, …) are **not** for product upload — leave them empty on product sheets.

---

## Part C — Upload your first simple product

### C1 — Add one row in the sheet (example)

| SKU | Product Title | Regular Price | Stock Quantity | Product Status | Product Type | Parent SKU | Variation Attributes |
|-----|---------------|---------------|----------------|----------------|--------------|------------|------------------------|
| TSH-001 | Blue T-Shirt | 599 | 50 | publish | simple | *(empty)* | *(empty)* |

Leave **Parent SKU** and **Variation Attributes** empty for simple products.

### C2 — Map columns in WordPress

1. **SheetSync → Import / Export**
2. Select your **Products** connection
3. Click **View Import Headers** (if you have not already)
4. In **Step 2 — Map fields**, match each sheet column to the WooCommerce field (same names)
5. Set **SKU** as the **Key field** (important for finding products later)

### C3 — Run import

1. Enable **Create new products**
2. For first test, disable **Skip existing SKU** (so you see updates if you re-import)
3. Click **Start Import**
4. Check the log: Created / Updated / Skipped
5. Go to **Products → All Products** in WooCommerce and confirm **TSH-001**

---

## Part D — Upload a variable product (hoodie with sizes)

### D1 — Create attributes in WooCommerce (once)

**Products → Attributes**

| Attribute | Example terms (slugs) |
|-----------|------------------------|
| Color | `red`, `blue` |
| Size | `s`, `m` |

In the sheet you will write `pa_color` and `pa_size` (WooCommerce adds the `pa_` prefix to global attributes).

### D2 — Add rows in the sheet (4 rows total if you also have the simple shirt)

**Row order: parent first, then variations.**

| SKU | Title | Type | Parent SKU | Variation Attributes | Price | Stock |
|-----|-------|------|------------|----------------------|-------|-------|
| HOODIE-01 | Cotton Hoodie | variable | empty | `pa_color:red,blue\|pa_size:s,m` | empty | empty |
| HOODIE-01-RED-S | empty | empty | HOODIE-01 | `pa_color:red\|pa_size:s` | 899 | 10 |
| HOODIE-01-RED-M | empty | empty | HOODIE-01 | `pa_color:red\|pa_size:m` | 899 | 8 |

**Parent row**

- **Product Type** = `variable`
- **Variation Attributes** = all options (comma between values, pipe between attributes)

**Each variation row**

- **Parent SKU** = parent’s SKU (`HOODIE-01`)
- **Variation Attributes** = one value per attribute
- **Regular Price** and **Stock** on the variation row
- **Product Type** = leave empty

### D3 — Import again

Same as Part C: map fields (include **Product Type**, **Parent SKU**, **Variation Attributes**) → **Start Import**.

Check in WooCommerce: open **Cotton Hoodie** → **Variations** tab — you should see two variations.

---

## Part E — What the plugin does vs what you do

| You do | Plugin does |
|--------|-------------|
| Fill Google Sheet rows | Reads rows via Google API |
| Map columns once per import | Applies values to WooCommerce products |
| Create attributes for variable products | Creates/updates parent, variations, prices, stock |
| Share sheet with service account | Authenticates with your JSON key |
| Run Import or Sync Now | Creates new products or updates existing (by SKU) |

---

## Part F — Daily workflow (after setup)

**Option 1 — One-time bulk upload**

1. Fill sheet  
2. **SheetSync → Import / Export** → **Start Import**

**Option 2 — Ongoing sync from sheet**

1. Edit products in the sheet  
2. **SheetSync → Connections** → your connection → **Sync Now**  
   (or enable scheduled sync on the connection)

---

## Part G — Common mistakes (avoid these)

| Mistake | Result |
|---------|--------|
| Sheet not shared with service account email | Import fails / cannot read sheet |
| Connection type = Orders instead of Products | Wrong behavior |
| Variation row before parent row | Variations skipped until parent exists |
| `Product Type` = simple on a variation row | Wrong product type |
| Filled Order ID / Customer Name on product rows | Ignored; confuses your sheet |
| Used `Red` instead of slug `red` in attributes | Variation may not match |
| Same SKU on two different products | Errors or wrong updates |

---

## Part H — Sample file and full reference

| Resource | Location |
|----------|----------|
| Test CSV (1 simple + 1 variable + 2 variations) | `sample-import-test-products.csv` |
| Every column explained | [PRODUCT-UPLOAD-GUIDE.md](PRODUCT-UPLOAD-GUIDE.md) |

---

## Quick checklist

**After activation**

- [ ] JSON saved in Settings  
- [ ] Sheet shared with service account (Editor)  
- [ ] Products connection created  
- [ ] Row 1 = headers  

**Before each import**

- [ ] SKU column mapped as Key field  
- [ ] Simple: Parent SKU + Variation Attributes empty  
- [ ] Variable: parent row above variations  
- [ ] Order columns empty  

---

*SheetSync for WooCommerce — Getting Started*
