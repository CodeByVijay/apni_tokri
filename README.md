# Apni Tokri

Local food and grocery e-commerce for Indore — **Core PHP**, **MySQL**, **Tailwind CDN**, **jQuery**.

Requirements: see [Apni_Tokri_Requirements.md](./Apni_Tokri_Requirements.md).

**Implementation progress:** [PROJECT_PROGRESS.md](./PROJECT_PROGRESS.md) (Stage 11 conditionally accepted; **Stage 12** is next).

## Development stages

| Stage | Focus |
|-------|--------|
| **0–10** | Foundation through offers and coupons — **complete** |
| **11** | Subscription engine — **core complete**; Razorpay staging/E2E **deferred** (see PROJECT_PROGRESS) |
| **12** | Reviews, testimonials, support and refunds — **Phase A in progress** |
| **13–15** | CMS/SEO, operations, launch QA — not started |

Full stage definitions: [Apni_Tokri_Requirements.md](./Apni_Tokri_Requirements.md) (Stages 0–15).

## Requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `sodium`, `curl`, `mbstring`, `json`
- MySQL 8+ or compatible MariaDB
- Apache/Nginx with HTTPS in production
- **No** Composer, npm, or build step

## Apache must use PHP 8.2+

If you see **HTTP 500** and the Apache error log shows a parse error in `includes/`, the web server is probably still on **PHP 7.3**. CLI `php -v` may show 8.4 while Apache uses 7.3.

On Ubuntu (requires sudo):

```bash
sudo a2dismod php7.3
sudo a2enmod php8.4
sudo systemctl reload apache2
```

Then open `health-check.php` and confirm **PHP 8.2+** is green.

## Local setup (Stage 0)

1. Clone or copy project files to your web root (e.g. `/var/www/html/Apni Tokri`).

2. Copy configuration:
   ```bash
   cp includes/config.example.php includes/config.local.php
   ```
   Edit database credentials and `app.base_url` for your environment.

3. Create an empty database (full schema in **Stage 5**):
   ```sql
   CREATE DATABASE apni_tokri CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'apni_tokri_user'@'localhost' IDENTIFIED BY 'your_password';
   GRANT ALL PRIVILEGES ON apni_tokri.* TO 'apni_tokri_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

4. Ensure directories are writable by the web server:
   ```bash
   chmod -R 775 storage/logs storage/temporary uploads
   ```

5. Import schema (Stage 5):
   ```bash
   mysql -u apni_tokri_user -p apni_tokri < database/database.sql
   php scripts/verify-install.php
   ```
6. Open in browser:
   - [install.php](./install.php) — install checklist
   - [health-check.php](./health-check.php) — full verification
   - [admin/login.php](./admin/login.php) — default `admin@apnitokri.local` / `ChangeMe123!`

6. Optional AJAX smoke test:
   ```bash
   curl -s -H 'Accept: application/json' 'http://localhost/Apni%20Tokri/ajax/ping.php'
   ```

## Production deployment (overview)

1. Create database and import `database/database.sql` (complete file from Stage 5 onward).
2. Upload files; set `includes/config.local.php` and encryption key **outside** public webroot when possible.
3. Set `app.env` = `production`, `app.debug` = false.
4. Enable HTTPS; set session `cookie_secure` true.
5. Configure cron jobs and payment webhooks (later stages).
6. Block web access to `includes/`, `storage/`, `database/` (see `.htaccess`).

## Secrets

- Do **not** commit `includes/config.local.php`, `.env`, or encryption key files.
- Payment/SMTP/SMS secrets are encrypted at rest (Stage 9 / settings modules); master key path is configured in `config.local.php`.

## Coding conventions

See [CODING_CONVENTIONS.md](./CODING_CONVENTIONS.md).

## License

Proprietary — Apni Tokri business project.
