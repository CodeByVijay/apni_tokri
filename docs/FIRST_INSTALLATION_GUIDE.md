# Apni Tokri — first-time installation guide

## Who this is for

Non-technical operators deploying on **shared hosting / cPanel** without editing PHP files by hand.

## Before you start

1. Upload the Apni Tokri project to your hosting (often `public_html` or a subfolder).
2. In cPanel → **MySQL® Databases**, create:
   - A new **empty** database (no existing tables).
   - A database user with **ALL PRIVILEGES** on that database only.
3. Note the host (usually `localhost`), database name, username, and password.

## Run the wizard

1. Open `https://your-domain.example/install.php` in a browser.
2. Confirm all **Server requirements** show ✓ (PHP 8.1+, PDO MySQL, Sodium, writable `storage/`).
3. Enter database credentials → **Test connection & check empty** (must succeed on an empty database).
4. Review **Private storage** paths (defaults are **outside** `public_html` when cPanel layout is detected):
   - `…/apni-tokri-private/secrets/master.key` (0600)
   - `…/apni-tokri-private/invoices/` (0750)
5. Set your **administrator email and password** (not the factory seed password).
6. Enable **Production environment** on live sites.
7. Click **Install Apni Tokri**.

On success you are redirected to **Admin login**. The wizard writes `includes/config.local.php` (mode **0640**) and `storage/install.lock`, and **install.php returns 404** afterward.

## What the wizard does not do

- It does **not** import SQL into a database that already has tables.
- It does **not** overwrite an existing `config.local.php` on an installed site.
- It does **not** replace an existing encryption master key.
- It does **not** generate a new key if **invoice rows** already exist in the database.

## Existing installation / repair

Sites that already have a database and config continue to work unchanged.

If the database is installed but **invoice paths** were never configured for production:

1. Sign in to Admin.
2. Open **Admin → System storage repair** (`/admin/setup-repair.php`).
3. Set master key and invoice directory paths (outside document root).
4. Save — existing keys are preserved when the key file already exists.

## Manual / CLI path (advanced)

Still supported for developers:

```bash
mysql -u USER -p EMPTY_DB < database/production/apni_tokri_production.sql
cp includes/config.example.php includes/config.local.php
# edit config.local.php — DB, paths outside webroot
php scripts/verify-install.php
```

Apply later upgrades with files in `database/migrations/` — do **not** re-import the consolidated production SQL over live data.

## cPanel / shared hosting notes

| Topic | Guidance |
|--------|-----------|
| Home directory | Detected from `HOME`, or `public_html` parent — not hardcoded to a single host. |
| Permissions | Use **0750** on directories, **0640** on config, **0600** on master key — avoid **777**. |
| PHP version | Select 8.1+ in cPanel **MultiPHP Manager** for the site path. |
| HTTPS | Enable SSL before checking “Production environment”. |
| Cron | After install, configure cron URLs from project docs (subscription/delivery jobs). |

## Verification

```bash
php scripts/verify-install.php
php scripts/test-install-wizard.php   # disposable DB only
```

## Security reminders

- Change any default admin password immediately if you used manual SQL import.
- Keep `config.local.php` and `master.key` **outside** public_html when possible.
- Do not commit `config.local.php` or keys to git.
