# Invoice system — production deployment (security)

This document covers **hosting configuration** for Veggie Tokri invoice PDFs and encryption. The application enforces path rules when `app.env` is `production` (see `InvoiceProductionSecurity`).

## Required configuration (`config.local.php`)

```php
'encryption' => [
    // Absolute path to the existing 32-byte Sodium master key file (0600). Never inside the web docroot.
    'master_key_path' => '/var/lib/apni-tokri/secrets/master.key',
],

'storage' => [
    // Absolute directory for encrypted invoice PDFs (0750). Never inside the web docroot.
    'invoices_path' => '/var/lib/apni-tokri/invoices',
],
```

Also set:

```php
'app' => [
    'env' => 'production',
    'debug' => false,
],
'session' => [
    'cookie_secure' => true, // on HTTPS sites
],
```

**Do not** delete or regenerate `master.key` in production. A new key makes existing encrypted invoice files unreadable.

## Filesystem permissions

| Resource | Mode | Owner |
|----------|------|--------|
| Master key file | **0600** | PHP user (e.g. `www-data`) |
| Invoice storage directory | **0750** | PHP user |
| Invoice PDF files | **0640** | PHP user |
| Logo assets under invoice storage | **0640** | PHP user |

**Never use `chmod 777`** on the key, invoice directory, or PDF files.

## Apache: deny direct HTTP access

`.htaccess` rules in the project (e.g. `RewriteRule ^storage/ - [F,L]`) are **not sufficient** when the virtual host sets `AllowOverride None` — Apache will ignore them.

Configure the **VirtualHost** (adjust paths to your server):

```apache
<VirtualHost *:443>
    ServerName shop.example.com
    DocumentRoot /var/www/apni-tokri/public

    # Block sensitive trees even if they exist under or beside the docroot
    <Directory "/var/www/apni-tokri/storage">
        Require all denied
    </Directory>
    <Directory "/var/www/apni-tokri/includes">
        Require all denied
    </Directory>
    <Directory "/var/www/apni-tokri/database">
        Require all denied
    </Directory>

    # If docroot is the project root (legacy layout), deny the same URL paths:
    <LocationMatch "^/(storage|includes|database)(/|$)">
        Require all denied
    </LocationMatch>
</VirtualHost>
```

Prefer moving `storage.invoices_path` and `encryption.master_key_path` **completely outside** any `DocumentRoot`.

## Verification (after deploy)

Replace `https://shop.example.com` with your site URL.

```bash
# Must NOT return key material (expect 403/404, body must not be 32 bytes of binary)
curl -sS -o /dev/null -w "%{http_code} size=%{size_download}\n" \
  "https://shop.example.com/storage/secrets/master.key"

# Must NOT list or serve invoice ciphertext
curl -sS -o /dev/null -w "%{http_code}\n" \
  "https://shop.example.com/storage/invoices/"

# Application bootstrap must not be downloadable as source
curl -sS -o /dev/null -w "%{http_code}\n" \
  "https://shop.example.com/includes/bootstrap.php"
```

Successful invoice access remains only through authenticated endpoints (`invoice-download.php`, admin download), never via static URLs under `/storage/`.

## Cron

```bash
*/10 * * * * php /var/www/apni-tokri/cron/generate_invoices.php >> /var/log/apni-tokri-invoices.log 2>&1
```

## Orphan encrypted file audit (optional maintenance)

```bash
php scripts/invoice-storage-audit.php          # dry-run: list orphans
php scripts/invoice-storage-audit.php --delete # delete only verified orphans (see script help)
```

## Stale invoice email log rows

Pending rows older than 30 minutes are marked `failed` with `stale_pending` when a new send is attempted, so admins can retry without duplicate-send guards blocking forever. No email is sent by that recovery step alone.
