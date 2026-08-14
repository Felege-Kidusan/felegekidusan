# FKSS Content Management System — Deployment Guide

This adds a full content-management system so staff can edit the public website without touching code.

## What it does

A new **"Content Manager"** admin area with 6 panels:
1. **Registrations** — review requests submitted from the public website's registration form (stored as leads; you contact them manually)
2. **Gallery** — albums, photo uploads, captions (English + Amharic), featured-for-slideshow toggle
3. **Teachers** — the "Our Teachers" section, with photos
4. **Schedule** — the weekly schedule table
5. **Programs** — the educational programs cards
6. **Social Links** — footer social media icons + links

The public homepage now pulls all this content **from the database**. If a section has no content yet, it shows sensible default content (so the site never looks broken).

---

## Files in this package

```
sql/
  002_cms_schema.sql              ← run this in phpMyAdmin FIRST
admin/
  api_cms.php                     ← backend API (NEW)
  dashboard.php                   ← UPDATED (adds content_editor route)
  dashboards/
    content_editor.php            ← the CMS admin UI (NEW)
register_submit.php               ← public form handler (NEW)
index.php                         ← UPDATED (now database-driven)
```

---

## Deployment steps (in order)

### Step 1 — Run the database migration
1. Open phpMyAdmin → select the FKSS database
2. Go to the **SQL** tab
3. Open `sql/002_cms_schema.sql`, copy ALL of it, paste, and click **Go**
4. You should see the new `cms_*` tables created. Verify with: `SHOW TABLES LIKE 'cms_%';`

This also adds the `content_editor` role option and seeds 4 default social links (with `#` placeholder URLs you'll edit later).

### Step 2 — Upload the files
Upload to your FKSS `felegekidusan.arkeonethiopia.com` folder:

| File | Upload to |
|------|-----------|
| `admin/api_cms.php` | `felegekidusan.arkeonethiopia.com/admin/api_cms.php` |
| `admin/dashboard.php` | `felegekidusan.arkeonethiopia.com/admin/dashboard.php` (overwrite) |
| `admin/dashboards/content_editor.php` | `felegekidusan.arkeonethiopia.com/admin/dashboards/content_editor.php` |
| `register_submit.php` | `felegekidusan.arkeonethiopia.com/register_submit.php` |
| `index.php` | `felegekidusan.arkeonethiopia.com/index.php` (overwrite) |

### Step 3 — Create the uploads folder
The gallery and teacher photos need somewhere to live. Create these folders (if they don't exist) and make sure they're writable (755):
- `felegekidusan.arkeonethiopia.com/uploads/gallery/`
- `felegekidusan.arkeonethiopia.com/uploads/teachers/`

(The API tries to create them automatically, but creating them manually avoids permission issues.)

### Step 4 — Test
1. **Public page:** visit `felegekidusan.arkeonethiopia.com` — it should look exactly as before (showing default content)
2. **Admin:** log in, then visit `felegekidusan.arkeonethiopia.com/admin/dashboards/content_editor.php`
   - You should see the Content Manager with 6 tabs
3. **Add a test program** → refresh the public page → it should now show YOUR program instead of the defaults
4. **Submit the public registration form** → check the Registrations tab → your submission should appear

---

## Who can access it

Four roles can manage content:
- **super_admin** — via direct URL `/admin/dashboards/content_editor.php`
- **school_admin** — via direct URL
- **info_dept** — via direct URL
- **content_editor** (NEW role) — logs in and goes straight to the Content Manager

### To create a content_editor user
In phpMyAdmin, insert a user with `role = 'content_editor'` (or use your existing user-creation flow). They'll only see the Content Manager — nothing else.

### To give an existing admin a shortcut
super_admin/school_admin reach it by direct URL. If you want a button on their dashboard, tell me and I'll add it (I kept it URL-only to avoid editing those large dashboard files right before launch).

---

## How the registration flow works (Option B)

1. A parent fills the form on the public website
2. It saves to the `cms_registration_submissions` table as a **lead** (status: "new")
3. It does **NOT** auto-create a member
4. Staff see it in the Registrations tab, call the family, and update the status (New → Contacted → Enrolled/Rejected)
5. If they enroll, staff register them properly through the normal Info Dept member registration

This keeps your real member database clean — only staff-verified people become members.

---

## Anti-spam protection

The public form has three layers:
1. **Honeypot field** — a hidden field bots fill but humans don't; if filled, the submission is silently ignored
2. **Rate limiting** — max 5 submissions per IP per hour
3. **Validation** — name and phone required, email format checked

---

## Safety notes

- The public page **gracefully falls back** to default content if the database is unavailable or the `cms_*` tables don't exist yet. It will never show a blank page or error to visitors.
- All admin actions require login + CSRF token + one of the 4 allowed roles.
- Uploaded images are validated (type + real-image check + 8MB limit) and stored under `/uploads/`.
- Image deletion is path-restricted so it can only delete files inside `/uploads/`.

---

## Tested

All 5 PHP files pass syntax validation. The public page was render-tested in two scenarios:
- **With database content** → shows your CMS data, slideshow works, fallbacks hidden
- **Without database** → shows default content cleanly, no errors

---

## If something breaks

**Content Manager shows "permission" error** → your user's role isn't one of the 4 allowed. Check the `role` column in the users table.

**Photos won't upload** → the `uploads/gallery/` folder isn't writable. Set it to 755 in File Manager.

**Public page shows default content even after adding items in admin** → make sure you clicked "Show on website" (the is_active checkbox) when adding the item.

**Public form says "service unavailable"** → the database connection failed; check that `index.php` and `register_submit.php` can reach `admin/config.php`.
