-- Read-only impact report for migration 1.33.0_unpaid_order_payment_expiry.sql (run on STAGING replica; not a migration).
-- Simulates backfill: payment_expires_at = placed_at/created_at + 15 minutes for unpaid pending_payment rows.

SELECT 'pending_payment_unpaid_total' AS metric,
       COUNT(*) AS cnt
FROM orders
WHERE status = 'pending_payment'
  AND payment_status IN ('pending', 'failed');

SELECT 'would_backfill_expiry_set' AS metric,
       COUNT(*) AS cnt
FROM orders
WHERE payment_expires_at IS NULL
  AND status = 'pending_payment'
  AND payment_status IN ('pending', 'failed');

SELECT 'after_backfill_expiry_in_past' AS metric,
       COUNT(*) AS cnt
FROM orders
WHERE status = 'pending_payment'
  AND payment_status IN ('pending', 'failed')
  AND payment_expires_at IS NULL
  AND DATE_ADD(COALESCE(placed_at, created_at), INTERVAL 15 MINUTE) <= NOW();

SELECT 'after_backfill_expiry_still_future' AS metric,
       COUNT(*) AS cnt
FROM orders
WHERE status = 'pending_payment'
  AND payment_status IN ('pending', 'failed')
  AND payment_expires_at IS NULL
  AND DATE_ADD(COALESCE(placed_at, created_at), INTERVAL 15 MINUTE) > NOW();

SELECT 'null_expiry_after_backfill_simulation' AS metric,
       COUNT(*) AS cnt
FROM orders
WHERE status = 'pending_payment'
  AND payment_status IN ('pending', 'failed')
  AND COALESCE(placed_at, created_at) IS NULL;

-- Eligible for first cron batch (post-migration shape)
SELECT 'cron_eligible_immediately' AS metric,
       COUNT(*) AS cnt
FROM orders o
WHERE o.status = 'pending_payment'
  AND o.payment_status IN ('pending', 'failed')
  AND DATE_ADD(COALESCE(o.placed_at, o.created_at), INTERVAL 15 MINUTE) <= NOW()
  AND (o.order_source IS NULL OR o.order_source NOT IN ('subscription'))
  AND NOT EXISTS (
      SELECT 1 FROM payments p
      WHERE p.order_id = o.id AND LOWER(p.gateway) IN ('cod', 'cash_on_delivery')
  );

-- Sample rows for manual review (limit 20)
SELECT o.id,
       o.order_number,
       o.status,
       o.payment_status,
       o.placed_at,
       o.created_at,
       DATE_ADD(COALESCE(o.placed_at, o.created_at), INTERVAL 15 MINUTE) AS simulated_payment_expires_at,
       TIMESTAMPDIFF(MINUTE, DATE_ADD(COALESCE(o.placed_at, o.created_at), INTERVAL 15 MINUTE), NOW()) AS minutes_past_expiry
FROM orders o
WHERE o.status = 'pending_payment'
  AND o.payment_status IN ('pending', 'failed')
  AND DATE_ADD(COALESCE(o.placed_at, o.created_at), INTERVAL 15 MINUTE) <= NOW()
ORDER BY o.placed_at ASC
LIMIT 20;
