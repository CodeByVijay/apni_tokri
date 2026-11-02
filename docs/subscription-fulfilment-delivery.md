# Subscription fulfilment — delivery quote (D5)

Subscription-generated orders use the **same delivery quote pipeline as checkout** (`DeliveryQuoteService`). There is no separate pincode-only path.

## Flow (`SubscriptionFulfilmentService::generateForDay`)

1. Confirm basket, substitutions, and inventory hold (unchanged Stage 11 logic).
2. Resolve customer default address.
3. Resolve preferred delivery slot for the scheduled date (`SubscriptionSlotService`).
4. Build cart lines from basket items (including `grants_free_delivery` from products).
5. **`DeliveryQuoteService::quoteForCheckout()`** with the scheduled **`delivery_date`** for slot capacity and bookability.
6. On quote failure: release inventory hold, record `subscription_fulfilment_events` as `failed` (or `slot_unavailable` for slot/provider failures). **No order is created.**
7. On success: persist order with **`delivery_snapshot_json`** from `DeliveryQuoteService::buildSubscriptionOrderSnapshot()`.
8. Order **`delivery_fee` remains 0** and **`grand_total` = basket subtotal** (prepaid subscription — snapshot records the quoted fee for ops/reporting only).

## Snapshot contract

Same fields as checkout (`schema_version` **1.8.0-d4**), plus:

- `source`: `subscription`
- `subscription_number`
- `subscription_delivery_day_id`

Snapshots are **immutable** after order creation. Admin zone/fee changes do not rewrite historical orders.

## Policies

- **Hybrid / legacy:** `delivery.zone_resolution_mode` and coordinate requirements apply at fulfilment time.
- **Weight limits:** enforced via quote when configured on zones.
- **Provider / manual:** slot `allowed_provider_slugs_json` and manual fallback rules match checkout (D4 hardening).
- **Slot capacity:** D2 locking inside quote; failed fulfilment does not consume a slot (hold released, no order).

## Payment reconciliation

Subscription fulfilment orders are created **already paid**. D4 `payment_completion_exceptions` apply to **gateway capture at checkout payment**, not to subscription cron generation. Inventory release on quote failure uses existing subscription reservation services.

## External requirements (unchanged)

- Live **SMS** and **payment gateway E2E** still require provider credentials and staging validation.
- **Road-distance provider** remains disabled in quote until configured and approved (`externalRoadProviderAvailable()` false).

## Tests

```bash
php scripts/test-subscription-d5.php
```

Includes Stage 11 and delivery regression suites.
