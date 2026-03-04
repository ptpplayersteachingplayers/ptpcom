# PTP Engine v3.0 — Deployment Guide

## What Changed

v3 replaces the broken 380-line inline JS admin with a **proper React app** (1,659 lines JSX, 121KB compiled) that surfaces **ALL ~200 REST endpoints** your backend already has.

### New Modules (18 total)
1. **Dashboard** — stats, pipeline bar, 48hr window, funnel, quick metrics
2. **Pipeline** — free session applications with status/temp management, follow-ups
3. **Contacts** — families CRM with search, filter, add, CSV export
4. **Customer 360** — cross-table profile search (pipeline + families + camps + training)
5. **Inbox** — real-time SMS with 3-second polling, AI draft integration
6. **Campaigns** — both SMS and Email campaigns in one view
7. **Bookings** — training session booking management
8. **Coaches** — trainer CRUD with rates, location, status
9. **Camps** — 5-tab view: overview, listings, bookings, abandoned, customers
10. **Schedule** — Google Calendar integration, scheduled calls, call stats
11. **AI Engine** — draft queue (approve/reject), stats, settings toggle
12. **Rules** — keyword/intent auto-reply rule builder
13. **Sequences** — active automation sequence viewer
14. **Training Links** — booking link management with clipboard copy
15. **Attribution & Finance** — attribution overview + monthly P&L breakdown
16. **OpenPhone Platform** — stats, call history with AI summaries, voicemails
17. **Analytics** — ad spend logging, activity log, daily digest generator
18. **Settings** — health check, DB table status, OpenPhone connection, cron status

### Files Changed
- `admin/class-cc-admin.php` — **REPLACED** (new React app loader)
- `assets/ptp-app.js` — **NEW** (compiled React app, 121KB)
- `assets/ptp-app.jsx` — **NEW** (source, for future edits)
- `ptp-engine.php` — **UPDATED** (v3.0, clean admin_menu via CC_Admin::register_menu())

### Files Unchanged
All 22 PHP backend classes in `includes/` are **untouched**. Zero backend changes.

## Deployment Steps

### 1. Backup
```bash
# SSH into your server or use file manager
cp -r wp-content/plugins/ptp-engine wp-content/plugins/ptp-engine-v2-backup
```

### 2. Upload New Files
Upload these files to `wp-content/plugins/ptp-engine/`:

```
ptp-engine/
├── ptp-engine.php              (REPLACE)
├── admin/
│   └── class-cc-admin.php      (REPLACE)
├── assets/
│   └── ptp-app.js              (NEW — this is the compiled React app)
│   └── ptp-app.jsx             (NEW — source for future edits)
├── includes/                   (NO CHANGES)
│   └── ... all 22 PHP files unchanged
└── uninstall.php               (NO CHANGES)
```

### 3. Clear Cache
- Clear any object cache (Redis, Memcached)
- Clear any page cache plugin
- Hard refresh in browser: `Ctrl+Shift+R` (Windows) or `Cmd+Shift+R` (Mac)

### 4. Verify
1. Go to WP Admin > PTP Engine
2. You should see the new sidebar with 18 navigation items
3. Dashboard should load with live data from your API
4. Check Settings > Health to verify all DB tables and connections

## Rebuilding the App

If you need to modify the React app:

```bash
# Install esbuild (one time)
npm install esbuild

# Edit ptp-app.jsx

# Build
node build.js

# Copy to plugin
cp ptp-app.js wp-content/plugins/ptp-engine/assets/ptp-app.js
```

## API Endpoint Coverage

The app now surfaces endpoints from:
- `CC_Desktop_API` — dashboard, families, conversations, messaging, spend, activity
- `CC_API` — pipeline, parents, trainers, bookings, camps, customer360, finance, rules, drafts, templates, search
- `CC_OpenPhone_Platform` — stats, calls, voicemails, webhooks, contacts, backfill
- `CC_Campaigns` — SMS campaign CRUD and sending
- `CC_Email_Campaigns` — email campaign CRUD, segment preview, sending
- `CC_AI_Engine` — reply generation, settings, stats
- `CC_Attribution` — attribution overview, ad spend sync
- `CC_Inbox` — unified inbox contacts, threads, sync
- `CC_GCal` — calendar status, events, scheduled calls
- `CC_Health` — system health, DB table verification

## Rollback

If anything breaks:
```bash
# Remove v3
rm -rf wp-content/plugins/ptp-engine

# Restore v2 backup
mv wp-content/plugins/ptp-engine-v2-backup wp-content/plugins/ptp-engine
```
