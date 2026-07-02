# Sample sheet — 2 products (all fields filled)

Copy these **4 rows** into your Google Sheet **below row 1 (headers)**.  
Order columns (Order ID → Customer Note) stay **empty** for products.

**Before import:** WooCommerce → **Products → Attributes** → create **Color** (`pa_color`: `red`, `blue`) and **Size** (`pa_size`: `s`, `m`).

---

## Row 2 — Simple product `TSH-001`

| Field | Value |
|-------|--------|
| SKU (Product Key) | `TSH-001` |
| Product Title | `Blue Cotton T-Shirt` |
| Regular Price | `599` |
| Stock Quantity | `50` |
| Product Status | `publish` |
| Product Order | `1` |
| Sale Price | `499` |
| Short Description | `Soft 100% cotton crew neck tee for daily wear.` |
| Stock Status | `instock` |
| Weight | `0.2` |
| Length | `28` |
| Width | `22` |
| Height | `2` |
| Long Description | `<p>Comfortable blue t-shirt. Machine washable.</p>` |
| Main Image (URL) | `https://picsum.photos/seed/sheetsync-tshirt-main/800/800` |
| Gallery Images | `https://picsum.photos/seed/sheetsync-tshirt-g1/800/800, https://picsum.photos/seed/sheetsync-tshirt-g2/800/800` |
| Product Type | `simple` |
| Categories | `T-Shirts, Men's Wear` |
| Tags | `cotton, summer, casual, blue` |
| Parent SKU | *(empty)* |
| Variation Attributes | *(empty)* |
| Order ID → Customer Note | *(all empty)* |

---

## Row 3 — Variable parent `HOODIE-01`

| Field | Value |
|-------|--------|
| SKU | `HOODIE-01` |
| Product Title | `Cotton Hoodie Premium` |
| Regular Price | *(empty — price on variations)* |
| Stock Quantity | `0` |
| Product Status | `publish` |
| Product Order | `2` |
| Sale Price | *(empty)* |
| Short Description | `Warm fleece-lined hoodie. Choose color and size below.` |
| Stock Status | `instock` |
| Weight | `0.55` |
| Length | `35` |
| Width | `30` |
| Height | `8` |
| Long Description | `<p>Premium cotton hoodie with kangaroo pocket.</p>` |
| Main Image (URL) | `https://picsum.photos/seed/sheetsync-hoodie-main/800/800` |
| Gallery Images | `https://picsum.photos/seed/sheetsync-hoodie-g1/800/800, https://picsum.photos/seed/sheetsync-hoodie-g2/800/800` |
| Product Type | `variable` |
| Categories | `Hoodies` |
| Tags | `winter, warm, hoodie, cotton` |
| Parent SKU | *(empty)* |
| Variation Attributes | `pa_color:red,blue\|pa_size:s,m` |
| Order columns | *(all empty)* |

---

## Row 4 — Variation Red / S

| Field | Value |
|-------|--------|
| SKU | `HOODIE-01-RED-S` |
| Product Title | `Cotton Hoodie — Red / S` |
| Regular Price | `899` |
| Stock Quantity | `12` |
| Product Status | `publish` |
| Product Order | `3` |
| Sale Price | `799` |
| Short Description | `Red color size Small variation.` |
| Stock Status | `instock` |
| Weight | `0.52` |
| Length / Width / Height | `35` / `30` / `8` |
| Long Description | `Variation: Red + Small.` |
| Main Image (URL) | `https://picsum.photos/seed/sheetsync-hoodie-red-s/800/800` |
| Gallery Images | *(empty)* |
| Product Type | *(empty)* |
| Categories / Tags | *(empty)* |
| Parent SKU | `HOODIE-01` |
| Variation Attributes | `pa_color:red\|pa_size:s` |
| Order columns | *(all empty)* |

---

## Row 5 — Variation Red / M

| Field | Value |
|-------|--------|
| SKU | `HOODIE-01-RED-M` |
| Product Title | `Cotton Hoodie — Red / M` |
| Regular Price | `899` |
| Stock Quantity | `15` |
| Product Status | `publish` |
| Product Order | `4` |
| Sale Price | `849` |
| Short Description | `Red color size Medium variation.` |
| Stock Status | `instock` |
| Weight | `0.54` |
| Main Image (URL) | `https://picsum.photos/seed/sheetsync-hoodie-red-m/800/800` |
| Parent SKU | `HOODIE-01` |
| Variation Attributes | `pa_color:red\|pa_size:m` |
| (same empty rules as row 4 for Type, Categories, Gallery, Orders) | |

---

## Import in WordPress

1. Paste CSV into Google Sheet or copy rows 2–5 from `sample-import-test-products.csv`
2. **SheetSync → Import / Export** → map all product columns
3. **SKU = Key field** ✓ | **Create new products** ✓
4. **Start Import**

---

*Images use picsum.photos (free placeholders). Replace with your real product image URLs anytime.*
