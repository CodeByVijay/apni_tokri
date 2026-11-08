-- =============================================================================
-- PROPOSED TRANSACTIONAL DELETION — NOT FOR EXECUTION
-- =============================================================================
-- Status: DRAFT — requires explicit approval, full backup, clone rehearsal.
-- This file intentionally contains NO runnable auto-commit deletes.
-- Uncomment and run ONLY after:
--   1) phase3-1-tier-a-safety-review.php reports zero blockers on a CLONE
--   2) Protected customer IDs verified (Vijay Amule — NOT in any WHERE clause)
--   3) mysqldump backup completed and verified
-- =============================================================================

-- Recommended: wrap each scope in its own transaction on STAGING CLONE first.

/*
START TRANSACTION;

-- Scope: Stage 12 C1 refunds (marker RF-C1%)
-- DELETE FROM payment_refunds WHERE refund_request_id IN (SELECT id FROM refund_requests WHERE request_number LIKE 'RF-C1%');
-- DELETE FROM refund_request_evidence WHERE refund_request_id IN (...);
-- DELETE FROM refund_request_items WHERE refund_request_id IN (...);
-- DELETE FROM refund_request_history WHERE refund_request_id IN (...);
-- DELETE FROM refund_requests WHERE request_number LIKE 'RF-C1%';

-- Scope: Stage 12 C1 orders (marker AT-C1FIX-%)
-- DELETE FROM delivery_email_logs WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'AT-C1FIX-%');
-- DELETE FROM delivery_sms_logs WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'AT-C1FIX-%');
-- DELETE FROM payment_completion_exceptions WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'AT-C1FIX-%');
-- DELETE FROM delivery_status_history WHERE delivery_id IN (SELECT id FROM deliveries WHERE order_id IN (...));
-- DELETE FROM delivery_slot_reservations WHERE order_id IN (...);
-- DELETE FROM deliveries WHERE order_id IN (...);
-- DELETE FROM order_cancellation_events WHERE order_id IN (...);
-- DELETE FROM order_stock_restock_events WHERE order_id IN (...);
-- DELETE FROM order_status_history WHERE order_id IN (...);
-- DELETE FROM payment_refunds WHERE payment_id IN (SELECT id FROM payments WHERE order_id IN (...));
-- DELETE FROM payments WHERE order_id IN (...) AND idempotency_key LIKE 'pay_c1fix_%';
-- DELETE FROM order_items WHERE order_id IN (...);
-- DELETE FROM orders WHERE order_number LIKE 'AT-C1FIX-%';

-- Scope: C1 subscriptions SUB-C1FIX-%
-- DELETE FROM subscription_payments WHERE idempotency_key LIKE 'subpay_c1fix_%';
-- DELETE FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%';

-- Scope: C1 customers (ONLY after above + verify no non-C1 orders)
-- DELETE FROM customers WHERE mobile LIKE '987C1%' AND id NOT IN (<PROTECTED_IDS>);

-- Scope: SUB-PG-%, SUB-H-%, ZT-SUB-% — use SubscriptionTestFulfilmentFixture purge order (see docs/PHASE3_CLEANUP_PLAN.md)

-- Scope: AT-ACCT% orders — same order subgraph pattern as C1

ROLLBACK;
*/

-- Deletion order reference (child → parent), do not assume CASCADE for manual SQL:
-- 1 payment_refunds (refund- or payment-linked)
-- 2 refund_request_* → refund_requests
-- 3 delivery_* / order_status_history / order_items / payments → orders
-- 4 orders (marker-scoped)
-- 5 subscription fulfilment subgraph → customer_subscriptions
-- 6 subscription_payments → customer_subscriptions
-- 7 customer_* junction tables → customers (or rely ON DELETE CASCADE where verified)
-- 8 customers (ONLY marker-verified rows; NEVER protected IDs)
