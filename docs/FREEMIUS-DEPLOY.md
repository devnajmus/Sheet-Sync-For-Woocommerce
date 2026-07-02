# Freemius / customer distribution ZIP

## File to upload

**`sheetsync-for-woocommerce.zip`**

Customers and Freemius must receive this filename. Version number lives only in the plugin header (`1.2.0`), not in the folder name.

## Structure inside the ZIP (required)

```
sheetsync-for-woocommerce/
├── sheetsync-for-woocommerce.php   ← main plugin file
├── INSTALL.txt
├── admin/
├── includes/
└── ...
```

## Correct path on the server

```
wp-content/plugins/sheetsync-for-woocommerce/sheetsync-for-woocommerce.php
```

## Wrong installs (causes "Plugin file does not exist")

| Wrong | Why |
|-------|-----|
| `plugins/sheetsync-for-woocommerce-1.2.0/...` | Folder renamed to match ZIP filename |
| Double nested folders | Extracted ZIP inside another folder |
| Old broken folder left in `plugins/` | Stale path in WordPress |

## Build command (maintainers)

From repo source folder, run the build script or:

```powershell
$src = "path\to\sheetsync-for-woocommerce"
$dest = "sheetsync-for-woocommerce.zip"
# Copy to _zip-build\sheetsync-for-woocommerce, exclude Bengali docs + bn_BD.po
# Compress-Archive -Path ...\sheetsync-for-woocommerce -DestinationPath $dest
```

Excluded from customer ZIP: Bengali docs, `bn_BD.po` (English-only distribution).

## Language

Customer build is **English only** in the admin UI. No Bengali strings in PHP/JS user-facing text.
