<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

AdminDemoPerms::denyIfCannot('dashboard');

admin_layout_start([
    'pageTitle' => __('admin_dashboard'),
    'activeAdminNav' => 'dashboard',
]);
$base = url_base() . '/admin';

$kpis = AdminDashboardService::kpiTiles();
$tiles = [
    ['Orders today', (string) $kpis['orders_today'], 'receipt', '', $base . '/orders/index.php'],
    ['Pending payments', (string) $kpis['pending_payments'], 'wallet', 'at-kpi--warn', $base . '/payments/index.php'],
    ['Low-stock variants', (string) $kpis['low_stock'], 'box', 'at-kpi--danger', $base . '/inventory/index.php'],
    ['Open tickets', (string) $kpis['open_tickets'], 'chat', 'at-kpi--info', $base . '/support/index.php'],
];
$week = AdminDashboardService::ordersLastSevenDays();
$max = 1;
foreach ($week as $d) {
    $max = max($max, (int) $d['count']);
}
$deliveries = AdminDashboardService::deliveriesTodaySummary();
$activity = AdminDashboardService::recentActivity(6);
$prodBlockers = AppEnvironment::isProduction() ? StorefrontReadiness::productionBlockers() : [];
?>
<?php if ($prodBlockers !== []): ?>
    <div class="at-alert at-alert--error mb-5 text-sm">
        <strong>Production readiness:</strong>
        <ul class="list-disc pl-5 mt-2"><?php foreach ($prodBlockers as $issue): ?><li><?= Security::escape($issue) ?></li><?php endforeach; ?></ul>
    </div>
<?php elseif (!Install::isSchemaReady()): ?>
    <div class="at-alert at-alert--info mb-5 text-sm">Import <code>database/database.sql</code>, configure <code>config.local.php</code>, then use <a class="underline" href="<?= Security::escape(url_base()) ?>/install.php">install checklist</a>.</div>
<?php elseif (!StorefrontReadiness::cataloguePublished() && !AppEnvironment::isProduction()): ?>
    <div class="at-alert at-alert--info mb-5 text-sm">No products in the catalogue yet — the storefront uses the session demo cart until you seed or add products.</div>
<?php endif; ?>

<div class="grid grid-cols-2 xl:grid-cols-4 gap-3 md:gap-4">
    <?php foreach ($tiles as $tile): ?>
        <a href="<?= Security::escape($tile[4]) ?>" class="at-kpi <?= Security::escape($tile[3]) ?> at-focusable block">
            <span class="at-kpi__icon"><?= at_icon($tile[2]) ?></span>
            <p class="at-kpi__label"><?= Security::escape($tile[0]) ?></p>
            <p class="at-kpi__value"><?= Security::escape($tile[1]) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-3 gap-4 mt-5">
    <section class="at-admin-card lg:col-span-2">
        <div class="at-admin-card__head"><h2>Orders this week</h2></div>
        <div class="at-admin-card__body">
            <?php if ($week === []): ?>
                <p class="text-sm at-muted">No order data yet.</p>
            <?php else: ?>
                <div class="at-chart" role="img" aria-label="Bar chart of orders per day">
                    <?php foreach ($week as $d): ?>
                        <div class="at-chart__col<?= !empty($d['is_today']) ? ' is-today' : '' ?>">
                            <b><?= (int) $d['count'] ?></b>
                            <div class="at-chart__bar" style="height: <?= (int) round($d['count'] / $max * 100) ?>%"></div>
                            <?= Security::escape((string) $d['label']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="at-admin-card">
        <div class="at-admin-card__head"><h2>Today’s deliveries</h2></div>
        <div class="at-admin-card__body">
            <p class="text-3xl font-extrabold"><?= (int) $deliveries['scheduled_today'] ?> <span class="text-sm font-medium at-muted">scheduled</span></p>
            <?php if ($deliveries['subscription_skips'] > 0): ?>
                <p class="text-sm at-muted mt-1"><?= (int) $deliveries['subscription_skips'] ?> subscription skip(s) today</p>
            <?php else: ?>
                <p class="text-sm at-muted mt-1">No subscription skips recorded for today.</p>
            <?php endif; ?>
            <a href="<?= Security::escape($base) ?>/subscriptions/deliveries.php" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-4"><?= at_icon('truck') ?>Manage deliveries</a>
        </div>
    </section>
</div>

<section class="at-admin-card mt-4">
    <div class="at-admin-card__head"><h2>Recent activity</h2></div>
    <?php if ($activity === []): ?>
        <p class="px-5 py-4 text-sm at-muted">No recent orders or reviews yet.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($activity as $a): ?>
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 border-b last:border-0" style="border-color:#edf0ec">
                    <span class="text-sm font-semibold"><?= Security::escape($a['title']) ?></span>
                    <span class="at-status <?= Security::escape($a['status_class']) ?>"><?= Security::escape($a['subtitle']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php admin_layout_end(); ?>
