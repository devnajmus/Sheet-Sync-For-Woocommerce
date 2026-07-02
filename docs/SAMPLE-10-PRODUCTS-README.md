# 10-product test CSV — how to use

File: **`sample-import-10-products.csv`** (plugin root folder)

## What is included (10 rows)

| # | SKU | Type | Notes |
|---|-----|------|--------|
| 1 | TSH-001 | Simple | T-Shirt |
| 2 | MUG-001 | Simple | Mug |
| 3 | BOTTLE-001 | Simple | Bottle |
| 4 | WALLET-001 | Simple | Wallet |
| 5 | HOODIE-01 | **Variable parent** | Color + Size |
| 6 | HOODIE-01-RED-S | Variation | Red / S |
| 7 | HOODIE-01-BLK-M | Variation | Black / M |
| 8 | SHOE-01 | **Variable parent** | Size + Color |
| 9 | SHOE-01-BLU-S | Variation | Blue / S |
| 10 | SHOE-01-RED-M | Variation | Red / M |

**Result in WooCommerce:** 4 simple products + 2 variable parents + 4 variations = **6 products** in admin list (variations are inside parents).

---

## Before import — WooCommerce attributes

Create under **Products → Attributes**:

| Attribute | Slug | Terms (slugs) |
|-----------|------|----------------|
| Color | color → `pa_color` | `red`, `black`, `blue` (must exist under **Configure terms**) |
| Size | size → `pa_size` | `s`, `m`, `l` (lowercase slugs in sheet) |

---

## Steps

1. Copy CSV into Google Sheet (or File → Import).
2. Row 1 must stay as headers.
3. **SheetSync → Connections** → your connection → **Field Mapping**:
   - Click **Import Headers** → **Save Field Mapping**
   - Check: **T** = Parent SKU, **U** = Variation Attributes, **Q** = Product Type
4. **Sync** tab → **Full Sync** (first test) or **Import/Export** → Create new ✓
5. Check products:
   - 4 simple in product list
   - HOODIE-01 and SHOE-01 = Variable product
   - Open each → **Variations** tab = 2 variations each

---

## Row order

Parent rows are **before** variation rows (required).

---

*Images use picsum.photos — replace with your URLs if needed.*
