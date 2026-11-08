# Staging rollout: unpaid order expiry (1.33.0)

## Pre-migrate (read-only)

Run `scripts/sql/migration-1.33.0-backfill-impact-report.sql` on a **staging DB clone** (never production first pass).

Review `cron_eligible_immediately` — expect a spike on first cron after migrate if many stale `pending_payment` rows exist.

## Migrate staging

1. Apply `database/migrations/1.33.0_unpaid_order_payment_expiry.sql` once.
2. Confirm `orders.payment_expires_at` exists and `order_cancellation_events.requested_by` includes `system`.

## First cron strategy

1. Set `APNI_TOKRI_UNPAID_EXPIRY_BATCH=10` for the first 2–3 runs (env on cron command).
2. Run manually: `php cron/expire_unpaid_orders.php` and inspect stdout (`deferred`, `cancelled`, `paid`).
3. Watch PHP error log for `UnpaidOrderExpiry defer` and gateway reconcile messages.
4. Increase batch to 40 after counts stabilize.

## Staging verification checklist

- [ ] Place order → `payment_expires_at` set at checkout (not extended on retry).
- [ ] Cron defers on Cashfree/Razorpay API timeout (order stays `pending_payment`).
- [ ] Late Razorpay/Cashfree capture after timeout cancel → `payment_completion_exceptions` + admin order reconciliation block.
- [ ] Admin → Payment completion exceptions list shows `order_cancelled` category.
- [ ] Environment mismatch (test metadata + live keys) → cron `deferred`, audit `order.expiry_deferred`, no auto-cancel.

## Production

Do not enable production cron until all staging checks pass and backfill impact is accepted.
