# Background Sync, Cron & Scheduled Actions

**SheetSync for WooCommerce — User & support guide**

**Audience:** Store owners, agencies, hosting support  
**No coding required** for most steps (copy-paste cron commands are provided)

---

## Table of contents

1. [How SheetSync runs sync in the background](#1-how-sheetsync-runs-sync-in-the-background)
2. [What is Action Scheduler?](#2-what-is-action-scheduler)
3. [Sync Now (interactive sync)](#3-sync-now-interactive-sync)
4. [Real-time sync: Smart Poll vs instant](#4-real-time-sync-smart-poll-vs-instant)
5. [External Cron URL (recommended for production)](#5-external-cron-url-recommended-for-production)
6. [Fix “Background tasks overdue”](#6-fix-background-tasks-overdue)
7. [WP-Cron vs real server cron](#7-wp-cron-vs-real-server-cron)
8. [WP-CLI (agencies & VPS)](#8-wp-cli-agencies--vps)
9. [Large catalogs (1,000–100,000+ rows)](#9-large-catalogs-1000100000-rows)
10. [Troubleshooting FAQ](#10-troubleshooting-faq)
11. [Checklist for selling / handing off to a client](#11-checklist-for-selling--handing-off-to-a-client)

---

## 1. How SheetSync runs sync in the background

SheetSync does **not** import your whole sheet in one long request. That would timeout on most hosts.

Instead it uses **layers**:

```
┌─────────────────────────────────────────────────────────────┐
│  YOU (manual)                                                │
│  • Sync Now  →  browser runs batches (tab can stay open)    │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│  AUTOMATIC (background)                                      │
│  • Action Scheduler  →  job batches, images, watchdog       │
│  • Smart Poll (Pro)  →  checks sheet every ~3 minutes       │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│  HOSTING (keeps background alive)                            │
│  • Real server cron  OR  External Cron URL (Settings)       │
└─────────────────────────────────────────────────────────────┘
```

| Layer | When to use | Works without cron? |
|-------|-------------|---------------------|
| **Sync Now + open tab** | First import, urgent fix, small catalogs | **Yes** |
| **Smart Poll** | Day-to-day sheet edits (Pro, real-time ON) | Partially — needs scheduler |
| **External Cron URL** | Production stores, bad shared hosting | N/A — this *is* the cron |
| **WP-CLI** | VPS, agencies | Yes (CLI replaces cron) |

---

## 2. What is Action Scheduler?

**Action Scheduler** is WooCommerce’s background job system. SheetSync uses it (same as many WooCommerce extensions).

**Where to see it:**  
**WooCommerce → Status → Scheduled Actions**

| Tab | Meaning |
|-----|---------|
| **Pending** | Waiting to run |
| **Past-due** | Should have run already but did not (problem sign) |
| **Failed** | Ran but errored — open row for message |
| **Complete** | Finished OK |

### Common SheetSync hooks

| Hook name | What it does |
|-----------|----------------|
| `sheetsync_run_job` | Main import/export batch |
| `sheetsync_product_sheet_poll` | Smart Poll — sheet → WooCommerce (incremental) |
| `sheetsync_process_media_queue` | Downloads product images |
| `sheetsync_job_watchdog` | Recovers stuck jobs (safety net) |

**Filter by group:** `sheetsync`

### Healthy vs unhealthy

| Status | Healthy? | Action |
|--------|----------|--------|
| Pending 0–5 | ✅ Yes | None |
| Past-due 5+ | ⚠️ No | [Fix overdue tasks](#6-fix-background-tasks-overdue) |
| Many Failed | ⚠️ No | Read error message; fix cause; retry |

---

## 3. Sync Now (interactive sync)

**Best for:** First sync, fixing broken products, catalogs under ~5,000 rows, hosts where cron is broken.

### Steps

1. Go to **SheetSync → Connections** → open your connection.
2. Open the **Sync** tab.
3. Click **Sync Now** (or **Import from sheet** / **Update existing** as needed).
4. **Keep the browser tab open** until the progress bar finishes.
5. Check **Products → All Products** and **SheetSync → Sync Logs**.

### Why keep the tab open?

SheetSync can continue batches through your browser (AJAX) even when Action Scheduler is stuck. If you close the tab mid-sync, remaining batches may wait for cron.

### When Sync Now is not enough alone

- 10,000+ rows
- You need automatic updates every few minutes without opening WordPress  
→ Set up [External Cron URL](#5-external-cron-url-recommended-for-production) or real server cron.

---

## 4. Real-time sync: Smart Poll vs instant

### Smart Poll (built-in, Pro)

| Setting | Value |
|---------|--------|
| Where | Connection → **Sync** tab → **Enable real-time for this connection** |
| How often | About **every 3 minutes** (not seconds) |
| Needs | Action Scheduler or External Cron URL working |

**Important:** Editing the sheet and waiting **3 seconds** will **not** update WooCommerce. Wait **3+ minutes**, or use **Sync Now**, or set up instant sync below.

### Instant sync (Apps Script webhook, Pro)

| Setting | Value |
|---------|--------|
| Speed | Seconds after sheet edit |
| Setup | Connection → Sync tab → **Optional: Instant sync (Apps Script)** |
| Needs | Google Apps Script + webhook secret; host must allow inbound HTTPS |

Use instant sync when customers expect near-real-time inventory updates.

### Comparison

| Method | Delay | Setup difficulty |
|--------|-------|------------------|
| Sync Now (manual) | Immediate while tab open | Easy |
| Smart Poll | ~3 minutes | Easy (toggle ON) |
| External Cron URL | Depends on cron interval (e.g. 5 min) | Medium |
| Apps Script webhook | Seconds | Medium–hard |

---

## 5. External Cron URL (recommended for production)

If WooCommerce shows **“Background tasks overdue”**, this is the most reliable fix for SheetSync customers.

### Step 1 — Open settings

**WordPress admin → SheetSync → Settings**

Scroll to **Background cron URL**.

### Step 2 — Test from WordPress

1. Click **Run queue now (test)**.
2. You should see a message like: `Past-due tasks: 19 → 3` (numbers vary).
3. If past-due does not drop, see [troubleshooting](#10-troubleshooting-faq).

### Step 3 — Copy the Cron URL

Two URLs are shown:

| URL | Purpose |
|-----|---------|
| **Cron URL (full queue run)** | Runs scheduler, Smart Poll, jobs, images — use this on a schedule |
| **Ping URL** | Health check only — use to verify token works |

**Keep the token secret.** Anyone with the URL can trigger background work on your site.

### Step 4 — Enable the feature

On the main Settings form (same page):

1. Check **Allow background cron URL**.
2. Click **Save Settings**.

### Step 5 — Schedule every 5 minutes

Pick **one** method:

#### Option A — cURL (most Linux hosts, cron-job.org, EasyCron)

```bash
*/5 * * * * curl -fsS -m 60 "PASTE_YOUR_CRON_URL_HERE" >/dev/null 2>&1
```

Replace `PASTE_YOUR_CRON_URL_HERE` with the full URL from Settings (includes `token=`).

#### Option B — Host panel (cPanel “Cron Jobs”, Plesk, etc.)

- Command: `curl -fsS -m 60 "YOUR_CRON_URL"`
- Interval: every 5 minutes

#### Option C — WP-CLI on VPS

```bash
*/5 * * * * cd /path/to/wordpress && wp sheetsync run-queue --seconds=45 >/dev/null 2>&1
```

### Step 6 — Verify

1. Wait 5–10 minutes.
2. **WooCommerce → Status → Scheduled Actions** → Pending should be low.
3. SheetSync Settings → yellow overdue warning should clear.
4. Edit a product in the sheet → within one cron cycle + Smart Poll, WooCommerce should update.

### Regenerate token

If the URL is leaked:

1. Settings → **Regenerate token**
2. Update **every** cron job / external service with the new URL

---

## 6. Fix “Background tasks overdue”

You may see this on the connection page or in Settings:

> **Background tasks overdue** — X background tasks are overdue…

This means **WordPress is not running background jobs on time**. SheetSync is not the only plugin affected — WooCommerce emails, imports, etc. may also delay.

### Quick fix (5 minutes)

1. **WooCommerce → Status → Scheduled Actions**
2. Tab: **Pending**
3. Filter **Group:** `sheetsync` (optional)
4. Click **Run** at the top — repeat until Pending count is low
5. SheetSync → Settings → **Run queue now (test)**

### Permanent fix

1. Set up [External Cron URL](#5-external-cron-url-recommended-for-production) **or** [real server cron](#7-wp-cron-vs-real-server-cron)
2. Avoid relying only on “someone visited the website” to trigger WP-Cron

### Local / staging sites

Local development (Local WP, XAMPP, wpsync, etc.) often **never** runs WP-Cron automatically. That is **normal**.

- Use **Sync Now** + keep tab open, **or**
- Open the Cron URL in a browser, **or**
- Run `wp sheetsync run-queue`

---

## 7. WP-Cron vs real server cron

| | WP-Cron (default) | Real server cron |
|--|-------------------|------------------|
| **Triggers when** | Someone visits the site | Clock time, every N minutes |
| **Reliability on shared hosting** | Often poor | Good |
| **Good for production stores?** | Not alone | **Yes** |

### Recommended `wp-config.php` change (production)

```php
define( 'DISABLE_WP_CRON', true );
```

Then add to server crontab:

```bash
*/5 * * * * curl -s "https://YOUR-SITE.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

**And** keep the SheetSync External Cron URL on the same or a different 5-minute schedule.

---

## 8. WP-CLI (agencies & VPS)

Requires [WP-CLI](https://wp-cli.org/) installed on the server.

```bash
# Full queue run (25 seconds default)
wp sheetsync run-queue

# Longer run for busy stores
wp sheetsync run-queue --seconds=45

# Health check only
wp sheetsync run-queue --ping
```

Run from the WordPress root directory (where `wp-config.php` lives).

---

## 9. Large catalogs (1,000–100,000+ rows)

SheetSync uses **adaptive batches** and **resume**:

- Each batch processes a limited number of rows (host-safe time limits).
- If a batch stops, the next cron run continues from the last cursor.
- Progress appears in the connection UI and **Sync Logs**.

### Before a large import

| Step | Action |
|------|--------|
| 1 | Fix Action Scheduler ([§6](#6-fix-background-tasks-overdue)) |
| 2 | Set External Cron URL every 5 minutes |
| 3 | Run **Check sheet** — fix errors first |
| 4 | Use **Sync Now** and keep tab open **or** let background jobs finish |
| 5 | Watch **Sync Logs** for row-level errors |

### Row count vs product count

The log counts **sheet rows**, not “product families.”

| Store | Example row count |
|-------|-------------------|
| 1,000 simple products | ~1,000 rows |
| 500 variable parents × 4 variations | ~500 + 2,000 = ~2,500 rows |

---

## 10. Troubleshooting FAQ

### I edited the sheet but nothing changed in WooCommerce

| Check | Fix |
|-------|-----|
| Waited less than 3 minutes with Smart Poll only | Wait 3+ min or use **Sync Now** |
| Action Scheduler past-due | [§6](#6-fix-background-tasks-overdue) |
| Wrong sync direction | Connection must allow **Sheet → WooCommerce** |
| Row skipped in logs | Open **Sync Logs** for that SKU/row |
| Product hash “unchanged” but WC wrong | Run **Sync Now** (full path updates WC) |

### Product exists but missing price, SKU, or categories

| Check | Fix |
|-------|-----|
| Sync interrupted | **Sync Now** again with tab open |
| Field mapping | **Field Mapping** — map Price, SKU, Categories columns |
| Cron never ran | External Cron URL |
| Old broken product | Trash product → sync again **or** Sync Now to update |

### Yellow warning on connection page

Same as overdue tasks — follow [§6](#6-fix-background-tasks-overdue).

### Cron URL returns 401 Unauthorized

- Token wrong or truncated in cron command
- External cron disabled in Settings — enable and Save
- Regenerated token but old URL still in cron job

### Cron URL returns 429 Rate limit

- Cron job running more than ~30 times per minute — use **every 5 minutes**, not every minute

### Images not downloading

- `sheetsync_process_media_queue` pending — run queue
- Image URL blocked or slow — test URL in Settings
- Large catalog — images run after price/SKU; wait for media queue

### Smart Poll works on live site but not local

Local sites rarely run WP-Cron. Use Sync Now or Cron URL manually.

---

## 11. Checklist for selling / handing off to a client

Give your client this checklist:

### One-time setup

- [ ] Google Service Account JSON in **SheetSync → Settings**
- [ ] Sheet shared with service account (Editor)
- [ ] Connection created and **Check sheet** passes
- [ ] Field mapping saved (SKU = key field)
- [ ] **External Cron URL** scheduled every 5 minutes
- [ ] Test: edit one price in sheet → confirm WooCommerce updates within 15 minutes

### Daily use

- [ ] Edit sheet as normal
- [ ] For urgent changes: **Sync Now**
- [ ] Check **Sync Logs** if something looks wrong

### When something breaks

1. SheetSync → Settings → **Run queue now (test)**
2. WooCommerce → Scheduled Actions → Run pending
3. Read **Sync Logs** for the SKU/row
4. Contact support with log message + screenshot of Scheduled Actions

---

## Related guides

- [VARIABLE-PRODUCTS-GUIDE.md](VARIABLE-PRODUCTS-GUIDE.md) — parent + variation rows
- [USER-GUIDE-Google-Sheet-to-WooCommerce.md](USER-GUIDE-Google-Sheet-to-WooCommerce.md) — full manual
- [DOCUMENTATION-INDEX.md](DOCUMENTATION-INDEX.md) — all docs

---

*SheetSync for WooCommerce — Background sync documentation*  
*You may print or PDF this file for customers.*
