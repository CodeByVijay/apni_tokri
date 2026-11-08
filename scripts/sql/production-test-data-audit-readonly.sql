-- =============================================================================
-- PRODUCTION TEST DATA — PHASE 1 READ-ONLY AUDIT (SELECT ONLY)
-- Do NOT run any DELETE/UPDATE/TRUNCATE from this file.
-- =============================================================================
-- Usage (production or clone):
--   mysql -h HOST -u USER -p DATABASE < scripts/sql/production-test-data-audit-readonly.sql
-- =============================================================================

SET SESSION TRANSACTION READ ONLY;
START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) Protected customer: Vijay Amule (mobile + email + name)
-- ---------------------------------------------------------------------------
SELECT 'protected_customers' AS section;
SELECT id, mobile, name, email, is_active, created_at
FROM customers
WHERE REPLACE(REPLACE(REPLACE(REPLACE(mobile,'+91',''),' ',''),'-',''),'\t','') = '9407005023'
   OR LOWER(TRIM(email)) = LOWER('vijayamule7@gmail.com')
   OR name = 'Vijay Amule'
ORDER BY id;

-- Duplicate mobile / email elsewhere (must not delete wrong account)
SELECT 'protected_overlap_mobile' AS section;
SELECT id, mobile, name, email FROM customers
WHERE REPLACE(REPLACE(REPLACE(REPLACE(mobile,'+91',''),' ',''),'-',''),'\t','') = '9407005023'
ORDER BY id;

SELECT 'protected_overlap_email' AS section;
SELECT id, mobile, name, email FROM customers
WHERE LOWER(TRIM(email)) = LOWER('vijayamule7@gmail.com')
ORDER BY id;

-- ---------------------------------------------------------------------------
-- 2) Tier A — documented fixture markers (SAFE_TEST_DATA when blockers = 0)
-- Evidence: scripts/includes/stage12_phase_c1_fixture.php, docs/PHASE3_CLEANUP_PLAN.md
-- ---------------------------------------------------------------------------
SELECT 'tier_a_markers' AS section;
SELECT 'orders_at_c1fix' AS marker, COUNT(*) AS c FROM orders WHERE order_number LIKE 'AT-C1FIX-%'
UNION ALL SELECT 'orders_at_acct', COUNT(*) FROM orders WHERE order_number LIKE 'AT-ACCT%'
UNION ALL SELECT 'orders_at_test', COUNT(*) FROM orders WHERE order_number LIKE 'AT-TEST-%'
UNION ALL SELECT 'orders_tl', COUNT(*) FROM orders WHERE order_number LIKE 'TL-%'
UNION ALL SELECT 'orders_vt_retry', COUNT(*) FROM orders WHERE order_number LIKE 'VT-RETRY-%'
UNION ALL SELECT 'orders_vt_idem', COUNT(*) FROM orders WHERE order_number LIKE 'VT-IDEM-%'
UNION ALL SELECT 'orders_at_404', COUNT(*) FROM orders WHERE order_number LIKE 'AT-404%'
UNION ALL SELECT 'refunds_rf_c1', COUNT(*) FROM refund_requests WHERE request_number LIKE 'RF-C1%'
UNION ALL SELECT 'subs_sub_c1fix', COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-C1FIX-%'
UNION ALL SELECT 'subs_sub_pg', COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-PG-%'
UNION ALL SELECT 'subs_sub_h', COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'SUB-H-%'
UNION ALL SELECT 'subs_zt_sub', COUNT(*) FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-SUB-%'
UNION ALL SELECT 'customers_987c1', COUNT(*) FROM customers WHERE mobile LIKE '987C1%';

-- Blockers for Tier A (must be zero before auto-delete)
SELECT 'tier_a_blockers' AS section;
SELECT COUNT(*) AS payments_non_simulate_on_c1
FROM payments p
INNER JOIN orders o ON o.id = p.order_id
WHERE o.order_number LIKE 'AT-C1FIX-%' AND p.gateway IN ('razorpay','cashfree');
SELECT COUNT(*) AS invoices_on_c1_orders
FROM invoices i INNER JOIN orders o ON o.id = i.order_id WHERE o.order_number LIKE 'AT-C1FIX-%';
SELECT COUNT(*) AS customers_987c1_non_c1_orders
FROM orders o
WHERE o.customer_id IN (SELECT id FROM customers WHERE mobile LIKE '987C1%')
  AND o.order_number NOT LIKE 'AT-C1FIX-%';

-- Protected overlap: Vijay must not appear in candidate sets
SELECT 'protected_in_c1_customers' AS section;
SELECT COUNT(*) AS c FROM customers c
WHERE (REPLACE(REPLACE(REPLACE(REPLACE(c.mobile,'+91',''),' ',''),'-',''),'\t','') = '9407005023'
   OR LOWER(TRIM(c.email)) = LOWER('vijayamule7@gmail.com'))
  AND c.mobile LIKE '987C1%';

-- ---------------------------------------------------------------------------
-- 3) Candidate test customers (explicit patterns only — not email heuristics)
-- ---------------------------------------------------------------------------
SELECT 'candidate_test_customers' AS section;
SELECT c.id, c.mobile, c.name, c.email,
       (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS orders,
       (SELECT COUNT(*) FROM customer_subscriptions cs WHERE cs.customer_id = c.id) AS subs
FROM customers c
WHERE c.mobile LIKE '987C1%'
   OR c.name IN ('Stage12 C1 Test Customer A','Stage12 C1 Test Customer B')
   OR REPLACE(REPLACE(REPLACE(REPLACE(c.mobile,'+91',''),' ',''),'-',''),'\t','') = '9700000001'
ORDER BY c.id;

-- ---------------------------------------------------------------------------
-- 4) Per-customer counts (replace @pid with protected id from section 1)
-- ---------------------------------------------------------------------------
-- SET @pid := <protected_customer_id>;
-- SELECT 'orders', COUNT(*) FROM orders WHERE customer_id = @pid
-- UNION ALL SELECT 'payments', COUNT(*) FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.customer_id=@pid
-- UNION ALL SELECT 'invoices', COUNT(*) FROM invoices WHERE customer_id = @pid;

-- ---------------------------------------------------------------------------
-- 5) Foreign keys touching customers / orders (dependency map input)
-- ---------------------------------------------------------------------------
SELECT 'fk_customer_order_graph' AS section;
SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, kcu.COLUMN_NAME,
       kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
FROM information_schema.TABLE_CONSTRAINTS tc
JOIN information_schema.KEY_COLUMN_USAGE kcu
  ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
  ON tc.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
  AND (kcu.REFERENCED_TABLE_NAME IN ('customers','orders','customer_subscriptions','payments','refund_requests')
       OR tc.TABLE_NAME IN ('customers','orders','payments','refund_requests','invoices','deliveries'))
ORDER BY tc.TABLE_NAME;

-- ---------------------------------------------------------------------------
-- 6) Master / shared — counts only (NEVER DELETE in cleanup phase)
-- ---------------------------------------------------------------------------
SELECT 'master_tables_row_counts' AS section;
SELECT 'products' AS t, COUNT(*) AS c FROM products
UNION ALL SELECT 'product_variants', COUNT(*) FROM product_variants
UNION ALL SELECT 'categories', COUNT(*) FROM categories
UNION ALL SELECT 'coupons', COUNT(*) FROM coupons
UNION ALL SELECT 'subscription_plans', COUNT(*) FROM subscription_plans
UNION ALL SELECT 'delivery_slots', COUNT(*) FROM delivery_slots
UNION ALL SELECT 'settings', COUNT(*) FROM settings
UNION ALL SELECT 'admin_users', COUNT(*) FROM admin_users;

ROLLBACK;
