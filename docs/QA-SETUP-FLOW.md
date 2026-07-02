# SheetSync — QA Setup Flow Checklist

Manual test steps for Phase 0–1 setup UX. Run on a clean site or after resetting `sheetsync_setup_progress` in the database.

## Prerequisites

- WordPress + WooCommerce active
- Valid Google Cloud project with Sheets API enabled
- Service Account JSON key downloaded
- A Google Sheet you own (not yet shared with the SA)

---

## 1. Google Settings (`SheetSync → Settings`)

| Step | Action | Expected |
|------|--------|----------|
| 1.1 | Open Settings with no SA configured | Warning notice: no Service Account |
| 1.2 | Drag & drop JSON file onto upload zone | Textarea fills with JSON content |
| 1.3 | Click upload zone → pick `.json` file | Same as 1.2 |
| 1.4 | Save Settings | Success notice includes **Connected as: email@…**; textarea blank on reload |
| 1.5 | Click **Copy email for sharing** | Email copied to clipboard |
| 1.6 | Click **Test Google Connection** | ✅ success with service account email |
| 1.7 | Share instructions card | Shows 4 numbered steps with SA email |

---

## 2. Connections List (`SheetSync → Connections`)

| Step | Action | Expected |
|------|--------|----------|
| 2.1 | After Google connected only | Progress bar shows partial %; **Connect Google** step checked |
| 2.2 | **Continue setup →** CTA | Links to next incomplete step (Share sheet or New connection) |
| 2.3 | Complete all required steps | Progress bar hidden at 100% (optional realtime may remain) |

---

## 3. New / Edit Connection

| Step | Action | Expected |
|------|--------|----------|
| 3.1 | Google connected | Blue info box with SA email + Copy button |
| 3.2 | Google not connected | Warning with link to Settings |
| 3.3 | Paste full Sheet URL in **Google Sheet URL** | Spreadsheet ID field auto-fills |
| 3.4 | Test Connection **before** sharing sheet | Red error with share steps + copy email |
| 3.5 | Share sheet with SA (Editor) → Test again | Green success + **Open in Google Sheets** link |
| 3.6 | Successful test | Sheet tab dropdown populated (if tabs returned) |
| 3.7 | Save connection | Redirect success; progress **Create connection** checked |

---

## 4. Sync & Progress Auto-Update

| Step | Action | Expected |
|------|--------|----------|
| 4.1 | Write template (Sync tab) | `template_written` step marked done |
| 4.2 | Run first manual sync | `first_sync_done` step marked done |
| 4.3 | Enable real-time auto sync (Pro) | `realtime_enabled` step marked done |

---

## 5. Admin Notices

| Step | Action | Expected |
|------|--------|----------|
| 5.1 | Save settings | Notice appears only on SheetSync admin pages |
| 5.2 | Visit unrelated WP admin page | No stale SheetSync flash notices |

---

## 6. Error Messages (403 / permission)

| Step | Action | Expected |
|------|--------|----------|
| 6.1 | Test unshared spreadsheet | Message mentions sharing with SA email |
| 6.2 | Response JSON | Includes `error_type: share_required`, `share_email`, `share_steps` |

---

## Reset for re-testing

```sql
DELETE FROM wp_options WHERE option_name IN (
  'sheetsync_setup_progress',
  'sheetsync_template_written_connections',
  'sheetsync_service_account'
);
```

Or deactivate/reactivate plugin (tables remain; progress option re-inits on activate if missing).
