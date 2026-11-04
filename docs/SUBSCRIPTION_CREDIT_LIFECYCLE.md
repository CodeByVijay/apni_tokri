# Subscription credit lifecycle (1.28.0)

## Credit accounting

- Purchased credits: `plan_snapshot_json.delivery_count` (7 / 15 / 30).
- Remaining credits: `customer_subscriptions.deliveries_remaining`.
- Consumption: only when admin marks subscription delivery **delivered** (`SubscriptionEntitlementService::onDeliveryMarkedDelivered`), idempotent via `subscription_delivery_entitlement_events`.
- `subscription_day_credits` remains the **financial/cancellation ledger** (cancel-day, gateway refund) — not the master delivery balance.

Skip and pause **never** decrement `deliveries_remaining`.

## Schedule extension

- Initial calendar is still generated at activation from the plan snapshot.
- `calendar_end_date` tracks the **latest scheduled delivery date** (planning horizon), **not** subscription expiry.
- While `deliveries_remaining > 0` and status is `active` or `paused`, `SubscriptionCreditScheduleService::ensureScheduleCoversRemainingCredits` adds `scheduled` days using plan frequency (`SubscriptionScheduleGenerator::nextDeliveryDateAfter`).
- Skips append a replacement day and release basket/reservation holds for that date.

## Pause / resume

- Customer selects **pause start** and **resume on** (max **30** consecutive calendar days between dates).
- Status `pending` = future pause; `open` = currently paused.
- **No dedicated resume cron.** Automatic resume runs lazily when:
  - Customer opens subscription details (`resolveSubscription`),
  - Existing subscription crons run (`SubscriptionAutoConfirmService::resolveDueSubscriptions`),
  - Or other lifecycle actions call resolve.
- If nothing touches the app on the resume date, the subscription may stay paused until the next event.
- Early resume: customer **Resume now** closes the pause and shifts future scheduled days.

## Completion

- When `deliveries_remaining = 0` and no open/pending pause, status becomes **`completed`** (`SubscriptionCreditScheduleService::maybeCompleteSubscription`).
- Auto-confirm and fulfilment require `deliveries_remaining > 0` and `status = active`.

## Deployment

1. Forward migration only: `database/migrations/1.28.0_subscription_credit_lifecycle.sql`
2. Target `Install::SCHEMA_VERSION`: `1.28.0-subscription-credit-lifecycle`
3. Do **not** import production SQL over a populated database.

## Crons (unchanged files)

- `cron/subscription_auto_confirm_baskets.php`
- `cron/subscription_generate_deliveries.php`
- `cron/subscription_recover_reservations.php`

No new cron jobs were added.
