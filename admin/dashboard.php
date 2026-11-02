<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

AdminDemoPerms::denyIfCannot('dashboard');

admin_layout_start([
    'pageTitle' => __('admin_dashboard'),
    'activeAdminNav' => 'dashboard',
]);
admin_flash_render();
$base = url_base() . '/admin';

$tiles = [
    ['Orders today', '12', 'receipt', '', $base . '/orders/index.php'],
    ['Pending payments', '3', 'wallet', 'at-kpi--warn', $base . '/payments/index.php'],
        ['Low-stock variants', (string) (Install::isSchemaReady() && ProductRepository::productCount() > 0 ? InventoryService::countLowStockVariants() : 5), 'box', 'at-kpi--danger', $base . '/inventory/index.php'],
    ['Open tickets', '4', 'chat', 'at-kpi--info', $base . '/support/index.php'],
];
// Demo series only: the real chart is fed by orders in Stage 7+.
$week = [['Mon', 9], ['Tue', 12], ['Wed', 8], ['Thu', 14], ['Fri', 11], ['Sat', 18], ['Sun', 12]];
$max = max(array_column($week, 1));
$activity = [
    ['Order AT-260130-ABC123', 'Confirmed', 'at-status--ok'],
    ['Review awaiting moderation', 'Fresh Red Tomatoes', 'at-status--warn'],
    ['Refund requested', 'Quality issue', 'at-status--bad'],
    ['Stock adjusted', 'Cut Mixed Salad −2', 'at-status--info'],
];
?>
<div class="at-alert at-alert--info mb-5 text-sm">All figures on this dashboard are <strong>demo metrics</strong>. Live numbers arrive with the order and inventory backend.</div>

<div class="grid grid-cols-2 xl:grid-cols-4 gap-3 md:gap-4">
    <?php foreach ($tiles as $tile): ?>
        <a href="<?= Security::escape($tile[4]) ?>" class="at-kpi <?= Security::escape($tile[3]) ?> at-focusable block">
            <span class="at-kpi__icon"><?= at_icon($tile[2]) ?></span>
            <p class="at-kpi__label"><?= Security::escape($tile[0]) ?></p>
            <p class="at-kpi__value"><?= Security::escape($tile[1]) ?></p>
            <span class="at-status mt-2">Demo</span>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-3 gap-4 mt-5">
    <section class="at-admin-card lg:col-span-2">
        <div class="at-admin-card__head"><h2>Orders this week</h2><span class="at-status">Demo data</span></div>
        <div class="at-admin-card__body">
            <div class="at-chart" role="img" aria-label="Demo bar chart of orders per day">
                <?php foreach ($week as $i => $d): ?>
                    <div class="at-chart__col<?= $i === 5 ? ' is-today' : '' ?>">
                        <b><?= (int) $d[1] ?></b>
                        <div class="at-chart__bar" style="height: <?= (int) round($d[1] / $max * 100) ?>%"></div>
                        <?= Security::escape($d[0]) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="at-admin-card">
        <div class="at-admin-card__head"><h2>Today’s deliveries</h2></div>
        <div class="at-admin-card__body">
            <p class="text-3xl font-extrabold">7 <span class="text-sm font-medium at-muted">scheduled</span></p>
            <p class="text-sm at-muted mt-1">2 subscription skips carried forward</p>
            <a href="<?= Security::escape($base) ?>/subscriptions/deliveries.php" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-4"><?= at_icon('truck') ?>Manage deliveries</a>
        </div>
    </section>
</div>

<section class="at-admin-card mt-4">
    <div class="at-admin-card__head"><h2>Recent activity</h2><span class="at-status">Demo data</span></div>
    <ul>
        <?php foreach ($activity as $a): ?>
            <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 border-b last:border-0" style="border-color:#edf0ec">
                <span class="text-sm font-semibold"><?= Security::escape($a[0]) ?></span>
                <span class="at-status <?= Security::escape($a[2]) ?>"><?= Security::escape($a[1]) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<div class="at-alert at-alert--info mt-5 text-sm">
    <?php if (Install::isSchemaReady()): ?>
        Schema <?= Security::escape((string) Install::installedSchemaVersion()) ?> installed — admin login uses database roles. Storefront still uses session demo cart until Stage 6–7.
    <?php else: ?>
        Import <code>database/database.sql</code>, configure <code>config.local.php</code>, then use <a class="underline" href="<?= Security::escape(url_base()) ?>/install.php">install checklist</a>.
    <?php endif; ?>
</div>
<?php admin_layout_end(); ?>
