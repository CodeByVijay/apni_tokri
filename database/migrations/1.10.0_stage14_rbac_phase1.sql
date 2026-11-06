-- Veggie Tokri — Stage 14 Phase 1 (RBAC role permission seeding)
-- Depends on: 1.9.0-stage13-cms-1 (admin_roles / admin_permissions populated)
-- Idempotent: INSERT IGNORE only; does not remove custom role assignments.

SET NAMES utf8mb4;

-- Super Admin: ensure all permissions (fresh installs already have role_id=1).
INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.slug = 'super_admin';

-- Order Manager — orders, deliveries, customers, support, refunds, payments, dashboard
INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `admin_roles` r
INNER JOIN `admin_permissions` p ON p.slug IN (
  'dashboard.view',
  'orders.manage',
  'deliveries.manage',
  'customers.view',
  'support.manage',
  'refunds.manage',
  'payments.view'
)
WHERE r.slug = 'order_manager';

-- Inventory Manager — catalogue and stock
INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `admin_roles` r
INNER JOIN `admin_permissions` p ON p.slug IN (
  'dashboard.view',
  'products.manage',
  'categories.manage',
  'inventory.manage'
)
WHERE r.slug = 'inventory_manager';

-- Customer Support — tickets, reviews, refunds (workflow), customers, FAQs
INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `admin_roles` r
INNER JOIN `admin_permissions` p ON p.slug IN (
  'dashboard.view',
  'support.manage',
  'reviews.moderate',
  'refunds.manage',
  'customers.view',
  'faqs.manage',
  'testimonials.manage'
)
WHERE r.slug = 'support';

-- Finance — payments, refunds, reports, coupons, offers (no order fulfilment or settings)
INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `admin_roles` r
INNER JOIN `admin_permissions` p ON p.slug IN (
  'dashboard.view',
  'payments.view',
  'refunds.manage',
  'reports.view',
  'coupons.manage',
  'offers.manage'
)
WHERE r.slug = 'finance';

UPDATE `settings` SET `setting_value` = '1.10.0-stage14-rbac-1' WHERE `setting_key` = 'schema_version';
