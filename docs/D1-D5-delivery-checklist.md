# D1–D5 delivery enhancement checklist

| Track | Deliverable | Status |
|-------|-------------|--------|
| **D1** | Zones, hybrid resolution, pincode legacy | Done |
| **D2** | Slot CRUD, reservations, capacity | Done |
| **D2 hardening** | Payment completion exceptions (slot failure after capture) | Done |
| **D3** | Manual courier fulfilment, assignments | Done |
| **D3 hardening** | SMS dedupe, history privacy, atomic locks | Done |
| **D4** | Checkout quote, immutable snapshot, payment revalidation | Done |
| **D4 hardening** | Inventory/address drift reconciliation, cart preview, manual allow-list | Done |
| **D5** | Subscription fulfilment uses shared quote + snapshot | Done |

## Schema version chain

`1.8.0` → `1.8.0-d2` → `1.8.0-d2-hardening` → `1.8.0-d3-manual` → (D3 hardening tables, same label) → `1.8.0-d4-checkout` → `1.8.0-d4-hardening` → **`1.8.0-d5-subscription`**

## Regression command (full delivery + subscription)

```bash
php scripts/test-subscription-d5.php
```

Runs D5 checks plus D1–D4, Stage 8–12 B, and Stage 11 suites.

## Not in D1–D5 scope

- Stage 12 Phase C
- Live road-distance API integration
- Production SMS/payment E2E sign-off (documented separately)
