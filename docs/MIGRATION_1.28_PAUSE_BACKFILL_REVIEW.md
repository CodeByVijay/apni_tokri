# Migration 1.28.0 — pause backfill review (pre-production)

## What the migration does

1. Adds `pause_start_date`, `resume_on_date` to `subscription_pauses` (idempotent).
2. Sets `pause_start_date = DATE(paused_at)` for all rows where it was NULL.
3. Sets `resume_on_date = DATE(resumed_at)` for **closed** pauses with `resumed_at`.
4. Sets `resume_on_date = pause_start + 30 days` only for **`open` or `pending`** pauses still missing `resume_on_date`.

## Legacy open pauses — risk

If a subscription was paused **before** 1.28 without a resume date, step 4 assigns **pause start + 30 calendar days**. When that date is **already in the past**, the next `resolveSubscription` / subscription cron will **auto-resume** and shift scheduled days.

### Pre-migration checklist (production)

```sql
SELECT id, customer_subscription_id, status, paused_at, pause_start_date, resume_on_date
FROM subscription_pauses
WHERE status IN ('open', 'pending');
```

For each row, set `resume_on_date` explicitly to the intended customer resume date **before** or **immediately after** migration if synthetic +30 days is wrong.

### Safer manual fix for stale open pauses (example)

```sql
-- Extend review: do not run blindly on production.
UPDATE subscription_pauses
SET resume_on_date = DATE_ADD(CURDATE(), INTERVAL 7 DAY)
WHERE status = 'open' AND resume_on_date IS NOT NULL AND resume_on_date < CURDATE();
```

## Closed historical pauses

Closed rows no longer receive a synthetic future `resume_on_date` unless `resumed_at` is present. Historical audit rows are preserved; only NULL columns are backfilled.
