# Unpaid order expiry cron

Automatically cancels storefront orders that remain unpaid after the payment window (default **15 minutes**, setting `commerce.unpaid_order_payment_minutes`).

## CLI (manual run)

From project root:

```bash
php cron/expire_unpaid_orders.php
```

## cPanel schedule (recommended: every 5 minutes)

```
/usr/bin/php /home/USER/public_html/cron/expire_unpaid_orders.php
```

Replace `USER` and path with your account. Find PHP CLI via SSH `which php` or cPanel MultiPHP.

## HTTP (optional)

If `cron.secret` is set in application settings:

```
GET https://your-domain/cron/expire_unpaid_orders.php?secret=YOUR_CRON_SECRET
```

## Expected output

```
[2026-11-08T12:00:00+05:30] cancelled=2 skipped=5 deferred=1 paid=0 errors=0 batch=40 notes=-
```

| Field | Meaning |
|-------|---------|
| `cancelled` | Orders cancelled for `payment_timeout` |
| `skipped` | Ineligible, not yet expired, or already handled |
| `deferred` | Gateway verification ambiguous; retry on next run |
| `paid` | Late payment confirmed; order preserved |
| `errors` | Per-order failures (see PHP error log) |

Concurrent runs: second instance prints `skipped=lock_busy` and exits 0.

## Logs

- Cron stdout/stderr (cPanel email or redirect to a file in your cron command)
- PHP `error_log` for per-order exceptions (`UnpaidOrderExpiryService::processOrder`, `OrderCancellationService`)

## Verify auto-cancel

1. Admin → order detail: cancellation shows **Automatic (system)** and reason *Payment not completed within the allowed time*.
2. `order_cancellation_events`: `requested_by = system`, `reason_code = payment_timeout`.
3. Customer order details: cancellation section; no retry payment CTA.
4. `notification_queue`: idempotency key `order.payment_timeout_cancelled:order:{id}:email` (once).

## Troubleshooting deferred orders

Orders stay in `pending_payment` with `payment_expires_at` in the past when:

- Latest payment attempt updated within ~2 minutes (`created`/`pending`)
- `payment_completion_exceptions` row is `open`
- Cashfree reconcile returned `error` or `reconciliation`

Re-run cron after webhooks complete or use admin payment refresh. Do not cancel manually unless you have confirmed no captured payment.

## Migration

Apply `database/migrations/1.33.0_unpaid_order_payment_expiry.sql` before first cron run.
