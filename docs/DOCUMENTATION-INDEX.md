# SheetSync for WooCommerce — Documentation Index

**Who is this for?** Store owners, agencies, and support staff using SheetSync.

Use this page to find the right guide. All files live in the plugin folder: `docs/`

---

## Recommended reading paths

Pick the path that matches your situation:

### Path A — Brand new (never used SheetSync)

1. [GETTING-STARTED.md](GETTING-STARTED.md) — install, Google JSON, first connection  
2. [USER-GUIDE-Google-Sheet-to-WooCommerce.md](USER-GUIDE-Google-Sheet-to-WooCommerce.md) — sync buttons, field mapping, daily use  
3. [BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md) — **before going live** — set up cron URL  

### Path B — Sheet edits not showing in WooCommerce

1. [BACKGROUND-SYNC-AND-CRON.md § Smart Poll vs instant](BACKGROUND-SYNC-AND-CRON.md#4-real-time-sync-smart-poll-vs-instant) — why 3 seconds is not enough  
2. [BACKGROUND-SYNC-AND-CRON.md § External Cron URL](BACKGROUND-SYNC-AND-CRON.md#5-external-cron-url-recommended-for-production) — production fix  
3. [BACKGROUND-SYNC-AND-CRON.md § Sync Now](BACKGROUND-SYNC-AND-CRON.md#3-sync-now-interactive-sync) — immediate fix  

### Path C — Variable products (hoodies, sizes, colors)

1. [VARIABLE-PRODUCTS-GUIDE.md](VARIABLE-PRODUCTS-GUIDE.md) — full layout with examples  
2. [SAMPLE-SHEET-2-PRODUCTS.md](SAMPLE-SHEET-2-PRODUCTS.md) — copy-paste demo rows  
3. [BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md) — if only 1 variation synced after waiting  

### Path D — Yellow “Background tasks overdue” warning

1. [BACKGROUND-SYNC-AND-CRON.md § Fix overdue tasks](BACKGROUND-SYNC-AND-CRON.md#6-fix-background-tasks-overdue)  
2. **SheetSync → Settings → Background cron URL** → Run test → schedule every 5 min  
3. WooCommerce → Scheduled Actions → Run pending  

### Path E — বাংলায় পড়তে চান

1. [ব্যবহার-গাইড.md](ব্যবহার-গাইড.md) — সেটআপ ও আপলোড  
2. [ব্যাকগ্রাউন্ড-সিঙ্ক-ও-ক্রন-গাইড.md](ব্যাকগ্রাউন্ড-সিঙ্ক-ও-ক্রন-গাইড.md) — ক্রন ও সিঙ্ক সমস্যা  

### Path F — প্লাগইন আপলোড করে লেটেস্ট চেঞ্জ টেস্ট

1. [প্লাগইন-আপলোড-ও-ভেরিফাই-গাইড.md](প্লাগইন-আপলোড-ও-ভেরিফাই-গাইড.md) — সম্পূর্ণ QA চেকলিস্ট (বাংলা)  

---

## Start here

| Guide | Best for |
|-------|----------|
| **[GETTING-STARTED.md](GETTING-STARTED.md)** | First-time setup from zero to first product |
| **[USER-GUIDE-Google-Sheet-to-WooCommerce.md](USER-GUIDE-Google-Sheet-to-WooCommerce.md)** | Complete shop-owner manual (simple + variable + sync buttons) |
| **[ব্যবহার-গাইড.md](ব্যবহার-গাইড.md)** | বাংলায় সংক্ষিপ্ত সেটআপ ও আপলোড গাইড |

---

## Sync, background jobs & hosting

| Guide | Best for |
|-------|----------|
| **[BACKGROUND-SYNC-AND-CRON.md](BACKGROUND-SYNC-AND-CRON.md)** | Action Scheduler, Smart Poll, Cron URL, WP-CLI, “sync not working” |
| **[ব্যাকগ্রাউন্ড-সিঙ্ক-ও-ক্রন-গাইড.md](ব্যাকগ্রাউন্ড-সিঙ্ক-ও-ক্রন-গাইড.md)** | বাংলায় ব্যাকগ্রাউন্ড সিঙ্ক ও ক্রন সেটআপ |

---

## Products & sheet structure

| Guide | Best for |
|-------|----------|
| **[PRODUCT-UPLOAD-GUIDE.md](PRODUCT-UPLOAD-GUIDE.md)** | Column-by-column field reference |
| **[VARIABLE-PRODUCTS-GUIDE.md](VARIABLE-PRODUCTS-GUIDE.md)** | Parent rows, variations, Color/Size, common mistakes |
| **[SAMPLE-SHEET-2-PRODUCTS.md](SAMPLE-SHEET-2-PRODUCTS.md)** | Small demo sheet layout |
| **[SAMPLE-10-PRODUCTS-README.md](SAMPLE-10-PRODUCTS-README.md)** | Larger sample catalog |

---

## Developers & agencies

| Guide | Best for |
|-------|----------|
| **[QA-SETUP-FLOW.md](QA-SETUP-FLOW.md)** | Testing checklist before release |
| **[FREEMIUS-DEPLOY.md](FREEMIUS-DEPLOY.md)** | Packaging and licensing |
| **[PENDING-STATUS.md](PENDING-STATUS.md)** | Internal feature status notes |

---

## Quick answers (most common questions)

| Question | Read this section |
|----------|-------------------|
| Sheet updated but WooCommerce did not change | [BACKGROUND-SYNC-AND-CRON.md § Smart Poll vs instant](BACKGROUND-SYNC-AND-CRON.md#4-real-time-sync-smart-poll-vs-instant) |
| Yellow “Background tasks overdue” warning | [BACKGROUND-SYNC-AND-CRON.md § Fix overdue tasks](BACKGROUND-SYNC-AND-CRON.md#6-fix-background-tasks-overdue) |
| Only 1 variation imported, not 2 | [VARIABLE-PRODUCTS-GUIDE.md](VARIABLE-PRODUCTS-GUIDE.md) |
| “No price on row 3” on Check sheet | [VARIABLE-PRODUCTS-GUIDE.md § Parent row has no price](VARIABLE-PRODUCTS-GUIDE.md#parent-row-warning-no-price) |
| Product has title but no price/SKU after sync | [BACKGROUND-SYNC-AND-CRON.md § Sync Now](BACKGROUND-SYNC-AND-CRON.md#3-sync-now-interactive-sync) |
| Plugin upload করার পর লেটেস্ট চেঞ্জ টেস্ট | [প্লাগইন-আপলোড-ও-ভেরিফাই-গাইড.md](প্লাগইন-আপলোড-ও-ভেরিফাই-গাইড.md) |
| How to set up cron on production | [BACKGROUND-SYNC-AND-CRON.md § External Cron URL](BACKGROUND-SYNC-AND-CRON.md#5-external-cron-url-recommended-for-production) |

---

## Server requirements (summary)

| Requirement | Notes |
|-------------|--------|
| WordPress 6.0+ | Required |
| WooCommerce 7.0+ | Required |
| PHP 8.0+ with OpenSSL | Required |
| Google Service Account + Sheets API | Required |
| Action Scheduler (included with WooCommerce) | Used for background batches |
| Real server cron **or** External Cron URL | **Strongly recommended** for production |
| WP-CLI | Optional (agencies / VPS) |

---

*Print or host these files for your customers. For the latest version, ship the `docs/` folder with the plugin zip.*
