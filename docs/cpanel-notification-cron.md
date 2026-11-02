# cPanel — notification queue cron

Apni Tokri processes the notification queue via a **PHP CLI** script. This document is for typical **shared cPanel** hosting (5-minute cron, limited CPU/memory). It has **not** been verified on every host; run the checks below on **your** account before relying on production cron.

## PHP CLI requirement

The worker **`cron/process_notification_queue.php` rejects non-CLI** requests. cPanel must be able to run PHP from the command line.

If CLI is **not** available, you cannot use this worker as designed. Options are: change hosting, enable CLI if the provider allows it, or run the worker manually from SSH when available. **Do not** expose a public HTTP URL to process the queue.

## 1. Check whether PHP CLI exists

**Terminal (SSH)** — if enabled on your account:

```bash
command -v php
php -v
```

**cPanel → Advanced → Terminal** (same commands if Terminal is offered).

If `command -v php` prints nothing, try common locations **only on your server** (paths vary by provider):

```bash
ls -l "$(which php 2>/dev/null)" 2>/dev/null
find "$HOME" -maxdepth 4 -type f -name 'php' 2>/dev/null | head
```

Use the path that responds to `php -v` — do not assume a single global path.

## 2. Verify CLI PHP version

Apni Tokri targets **PHP 8.2+** (see project README / install notes). Match the version your site uses in cPanel **MultiPHP** when possible:

```bash
php -v
```

If SSH `php` differs from the site’s web PHP, use the **MultiPHP / Select PHP Version** path shown in cPanel documentation for CLI, e.g. binaries under `opt/cpanel/...` on some hosts — **confirm with your host’s docs**.

## 3. Verify required extensions

From the project root:

```bash
cd ~/public_html/Apni_Tokri
php -m | egrep '^(pdo|pdo_mysql|json|openssl|mbstring|curl|sodium)$'
```

Adjust the project path to match your upload location. Enable missing extensions in cPanel **Select PHP Version → Extensions** for the CLI PHP version if needed.

## 4. Test database connectivity through CLI

```bash
cd ~/public_html/Apni_Tokri
php -r "require 'includes/bootstrap.php'; Install::ensureConnection(); echo Install::isSchemaReady() ? 'schema_ok' : 'schema_not_ready'; echo PHP_EOL;"
```

Expect `schema_ok` after migrations are applied. If not, import migrations (see `docs/stage14-notifications-migration.md`).

## 5. Manual worker run

```bash
cd ~/public_html/Apni_Tokri
php cron/process_notification_queue.php
```

Example output (numbers vary):

```text
[2026-11-02T12:00:00+05:30] recovered=0 claimed=2 sent=2 failed=0 retry_scheduled=0 stop=batch_complete batch=10 max_runtime=45
```

Optional environment (same shell line or cPanel “Environment” if supported):

- `NOTIFICATION_QUEUE_BATCH` — default **10**, maximum **20**
- `NOTIFICATION_QUEUE_MAX_RUNTIME` — default **45** seconds (soft limit between jobs)

## 6. Configure cron in cPanel

**cPanel → Advanced → Cron Jobs**

- **Schedule (recommended):** every 5 minutes — `*/5 * * * *`
- **Command:** use your discovered PHP binary and **full path** to the project:

```bash
cd /home/YOUR_CPANEL_USER/public_html/Apni_Tokri && NOTIFICATION_QUEUE_BATCH=10 NOTIFICATION_QUEUE_MAX_RUNTIME=45 php cron/process_notification_queue.php >> /home/YOUR_CPANEL_USER/logs/notification-queue.log 2>&1
```

Replace:

- `YOUR_CPANEL_USER` with your cPanel username (home directory name).
- `public_html/Apni_Tokri` if the app lives elsewhere (addon domain docroot, subdirectory, etc.).
- `php` with the CLI binary path you verified in step 1.

Create the log directory if needed:

```bash
mkdir -p ~/logs
chmod 700 ~/logs
```

## 7. Log handling and rotation

- Append stdout/stderr to a file under **`~/logs/`** (not web-accessible).
- Rotate periodically (cPanel **Metrics → Errors**, logrotate via host, or manual truncate):

```bash
: > ~/logs/notification-queue.log
```

Avoid unbounded multi‑MB logs on shared accounts.

## 8. Verify a notification was processed

1. Trigger an integrated event (e.g. complete a test payment with SMTP enabled, or create a support ticket).
2. **Admin → Notifications** — filter by status `sent` / `failed` / `pending`.
3. Re-run the worker manually or wait for the next 5‑minute cron tick.
4. Confirm the row moves to **`sent`** (or **`failed`** with a sanitized error code).

Pending rows with a future `next_retry_at` are waiting for backoff; disabled SMTP/SMS stays **`pending`** / retry — not **`sent`**.

## 9. Hosting limits

- Worker **batch default 10**, max **20**; **~45s** max runtime per invocation — suited to shared CPU/time limits.
- Worst-case delay with a 5‑minute cron: **up to ~5 minutes** after enqueue (plus retries).
- Long SMTP timeouts (admin setting up to 120s) can exceed one cron slice; keep **`smtp.timeout_seconds`** modest on shared hosting (e.g. 15–25) so jobs fit the worker budget.

## 10. What we do not use

- Redis, Supervisor, persistent workers, `pcntl`, or VPS-only services.
- Public HTTP cron endpoints.
