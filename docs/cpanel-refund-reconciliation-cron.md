# Refund status reconciliation cron

Automatic **read-only** polling of Cashfree/Razorpay refund status for in-flight `payment_refunds` rows.

## Apply migration

Run `database/migrations/1.32.0_refund_status_reconciliation.sql` on the database.

## Schedule

Recommended: **every 5 minutes**.

## cPanel command

Replace `USER`, PHP binary, and path:

```text
/usr/bin/php /home/USER/public_html/cron/refund_status_reconciliation.php
```

### Find your PHP CLI

- SSH: `which php` or `php -v`
- cPanel: **MultiPHP Manager** / **Select PHP Version** (CLI path is often `/usr/local/bin/php` or `/opt/cpanel/ea-phpXX/root/usr/bin/php`)

### Project path on XAMPP (local)

```text
d:\xampp\php\php.exe d:\xampp\htdocs\Apni_Tokri\cron\refund_status_reconciliation.php
```

## Environment (optional)

| Variable | Default | Purpose |
|----------|---------|---------|
| `APNI_TOKRI_REFUND_RECONCILE_BATCH` | `25` | Max refunds checked per run |
| `APNI_TOKRI_REFUND_RECONCILE_HTTP_TIMEOUT` | `20` | Gateway HTTP timeout (seconds) |

## HTTP trigger (optional)

If `cron.secret` is set in Admin settings, you may call:

`https://your-store/cron/refund_status_reconciliation.php?secret=YOUR_SECRET`

Prefer CLI on shared hosting.

## Safety

- Never POSTs refunds — status GET only.
- Uses merchant `refund_id` for Cashfree (not `cf_refund_id`).
- Overlapping runs are blocked via `storage/cache/refund_reconcile.lock` and per-row leases.
