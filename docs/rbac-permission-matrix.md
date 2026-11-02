# RBAC permission matrix (Stage 14.1)

System roles are seeded in `database/migrations/1.10.0_stage14_rbac_phase1.sql` (idempotent `INSERT IGNORE` by role **slug**, not numeric id).

| Permission slug | Super Admin | Order Manager | Inventory Manager | Customer Support | Finance |
|-----------------|:-----------:|:-------------:|:-----------------:|:----------------:|:-------:|
| dashboard.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| orders.manage | ✓ | ✓ | — | — | — |
| deliveries.manage | ✓ | ✓ | — | — | — |
| customers.view | ✓ | ✓ | — | ✓ | — |
| support.manage | ✓ | ✓ | — | ✓ | — |
| refunds.manage | ✓ | ✓ | — | ✓ | ✓ |
| payments.view | ✓ | ✓ | — | — | ✓ |
| products.manage | ✓ | — | ✓ | — | — |
| categories.manage | ✓ | — | ✓ | — | — |
| inventory.manage | ✓ | — | ✓ | — | — |
| reviews.moderate | ✓ | — | — | ✓ | — |
| faqs.manage | ✓ | — | — | ✓ | — |
| testimonials.manage | ✓ | — | — | ✓ | — |
| reports.view | ✓ | — | — | — | ✓ |
| reports.export | ✓ | — | — | — | ✓ |
| coupons.manage | ✓ | — | — | — | ✓ |
| offers.manage | ✓ | — | — | — | ✓ |
| subscriptions.manage | ✓ | — | — | — | — |
| cms.manage | ✓ | — | — | — | — |
| homepage.manage | ✓ | — | — | — | — |
| settings.manage | ✓ | — | — | — | — |
| users.manage | ✓ | — | — | — | — |
| roles.manage | ✓ | — | — | — | — |
| seo.manage | ✓ | — | — | — | — |
| marketing.manage | ✓ | — | — | — | — |

**Super Admin** receives every row in `admin_permissions` via migration (full access).

**Custom roles** (non-system) are created in admin UI; permissions must be a subset of the editor’s own permissions.

Module checks in `Permission::canModule()` map admin nav modules to these slugs (e.g. `payments` → `payments.view`, `refunds` → `refunds.manage`).
