# SheetSync Dashboard — Feature Roadmap

> **User documentation:** see [GETTING-STARTED.md](docs/GETTING-STARTED.md) and [docs/QA-SETUP-FLOW.md](docs/QA-SETUP-FLOW.md)

> **Updated:** Phase 3 complete.

---

## ✅ Phase 1 (Done)

Scheduled auto-export, email alerts, export history, BOE presets/templates/columns, onboarding, automation panel, last synced, open in Google Sheets, WP widget.

---

## ✅ Phase 2 (Done)

| Feature | How to use |
|---------|------------|
| **KPI help tooltips** | Hover/focus **?** on any KPI card (Sales, Inventory, Bulk Export) |
| **PDF report** | Sales or Inventory topbar → **PDF** → print dialog → Save as PDF |
| **Global search** | Toolbar search box — orders & products (min 2 chars) |
| **Role-based access** | Automation → Dashboard Access — comma-separated WP roles per tab |
| **Monthly goal progress** | Automation → set goal → Sales KPI section shows progress bar |
| **Export history CSV** | Automation → Export History → **Download CSV** |
| **Demo / sample data** | Toolbar → **Demo data** checkbox |
| **Reorder suggestions** | Inventory Dashboard → Reorder Suggestions table |

---

## ✅ Phase 3 (Done)

| Feature | How to use |
|---------|------------|
| **i18n** | English UI; additional locales via standard WordPress translation files |
| **Profit / COGS** | Product → Pricing → **Cost of goods (COGS)**; Automation → enable COGS |
| **Variation inventory** | Inventory Dashboard → sidebar **Variations** |
| **ML sales forecast** | Sales → **Sales Forecast** — Holt exponential smoothing + confidence % |
| **Multi-store rollup** | Automation → Multi-store → enable (WordPress multisite) |
| **Webhooks / Zapier** | Automation → Webhooks → URLs + optional signing secret |
| **White-label branding** | Automation → app name, logo URL, colors, hide PRO badge |
| **Mobile PWA widget** | Automation → **Open Mobile Widget** → Add to home screen |

---

## Key files

- `includes/pro/class-dashboard-phase3.php` — Phase 3 backend
- `includes/pro/class-dashboard-phase2.php` — Phase 2 backend
- `admin/js/dashboard-enhancements.js` — automation, branding, search
- `admin/js/sales-dashboard.js` — COGS, multistore, forecast UI
- `admin/js/inventory-dashboard.js` — variation inventory table
- `admin/pwa/` — mobile widget + service worker
- `languages/sheetsync-for-woocommerce.pot` — translation template (English source)
