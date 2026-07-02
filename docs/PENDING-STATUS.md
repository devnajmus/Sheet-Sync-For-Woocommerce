# SheetSync — Pending vs Complete (v1.2.0)

Last updated after Phase 4–6 implementation pass.

---

## ✅ Complete (ship-ready)

| Area | Items |
|------|--------|
| **Phase 0** | Setup progress model, helpers, share errors, QA doc |
| **Phase 1** | JSON upload, URL paste, progress bar, copy email, test auth |
| **Phase 2** | 7-step Setup Wizard, redirect on activate, skip/resume |
| **Phase 3** | Bootstrap, real-time card, mapping profiles |
| **Phase 4** | Troubleshooting accordion, help tooltips, setup video embed, docs (English), wizard a11y, Import/Export redirect |
| **Phase 5 (code)** | OAuth flow (client ID/secret + Connect button), Drive create spreadsheet API, wizard “Create new sheet”, SA + OAuth auth method switch |
| **Phase 6 (code)** | Setup Health admin page, onboarding emails (day 1/3/7 cron), multi-connection wizard (“Add another”), A/B variant tracking, wizard white-label settings |

---

## 🟡 Pending — requires YOUR Google Cloud / external setup

These are **not plugin bugs** — they need configuration or third-party accounts:

| # | Item | What you must do |
|---|------|------------------|
| P1 | **OAuth “Sign in with Google”** | Google Cloud Console → OAuth client ID (Web) → add redirect URI from Settings → save Client ID/Secret → Connect |
| P2 | **Create sheet from plugin** | Enable **Google Sheets API** (and for SA: share not needed for OAuth-owned sheets). Service Account needs API access; OAuth uses user’s Drive |
| P3 | **Setup video** | Replace default YouTube embed URL in **Settings → Setup video** |
| P4 | **Freemius upload** | Upload `sheetsync-for-woocommerce.zip` to Freemius Releases |

---

## 🔴 Pending — future / optional (not in this release)

| # | Item | Why pending |
|---|------|-------------|
| F1 | **Apps Script API auto-deploy** | Google limitation; manual Apps Script install still required for real-time |
| F2 | **Google Workspace Marketplace add-on** | Long-term distribution path |
| F3 | **Multi-site agency rollup** | Needs central dashboard product (not single WP plugin scope) |
| F4 | **Loom embed per wizard step** | URLs can be added via `sheetsync_wizard_video_urls` option; no UI yet for steps 2–7 |
| F5 | **A/B test analytics dashboard** | Counts only in Setup Health; no conversion metrics |
| F6 | **100% WCAG audit** | Basic ARIA/keyboard added; full audit not done |
| F7 | **Centralized OAuth app (SheetSync-hosted)** | Each site uses own OAuth client today |

---

## Quick test after upload

1. **SheetSync → Setup Wizard** — finish all 7 steps  
2. **Settings** — test SA JSON + optional OAuth  
3. **Step 2 wizard** — “Create new sheet for me”  
4. **Import/Export** — should redirect to first connection Sync tab (`?legacy=1` for old page)  
5. **Setup Health** — view checklist + A/B counts  

---

*File: `docs/PENDING-STATUS.md` — update this when closing pending items.*
