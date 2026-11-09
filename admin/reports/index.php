<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('reports');

admin_layout_start(['pageTitle' => 'Reports', 'activeAdminNav' => 'reports']);
$pageHeading = 'Reports & exports';
$pageDescription = 'Sales, orders, payments, refunds, subscriptions, and inventory (Asia/Kolkata dates).';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$base = url_base() . '/admin/reports';
$cards = [
    ['Sales summary', 'Gross, net, refunds, order counts', $base . '/sales.php'],
    ['Order report', 'Paginated order list with payment & refund status', $base . '/orders.php'],
    ['Payment report', 'Storefront attempts, collections, reconciliation', $base . '/payments.php'],
    ['Refund report', 'Requests, completed gateway refunds, legacy rows', $base . '/refunds.php'],
    ['Subscription report', 'Activations, collection, delivery fulfilment', $base . '/subscriptions.php'],
    ['Inventory report', 'Stock levels and movement history', $base . '/inventory.php'],
    ['Coupons', 'Coupon usage report', $base . '/coupons.php'],
];
?>
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
    <?php foreach ($cards as [$title, $desc, $href]): ?>
        <a href="<?= Security::escape($href) ?>" class="at-card at-focusable text-left hover:border-brand-green/30 block">
            <div class="at-card__body">
                <p class="font-semibold"><?= Security::escape($title) ?></p>
                <p class="text-xs text-neutral-500 mt-1"><?= Security::escape($desc) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<p class="text-sm at-muted mt-4">Calculation rules: <code>docs/reports-calculation-rules.md</code></p>
<?php admin_layout_end(); ?>
