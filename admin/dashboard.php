<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

AdminDemoPerms::denyIfCannot('dashboard');

$dashQuery = array_merge(
    ['preset' => 'last7', 'from' => '', 'to' => ''],
    $_GET
);
$rangeParsed = ReportDateRange::fromRequest($dashQuery);
$rangeError = null;
$range = null;
if (!$rangeParsed['ok']) {
    $rangeError = (string) $rangeParsed['message'];
}
$fallbackRange = ReportDateRange::fromRequest(['preset' => 'last7']);
if ($rangeParsed['ok']) {
    $range = $rangeParsed['range'];
} elseif ($fallbackRange['ok']) {
    $range = $fallbackRange['range'];
}

admin_layout_start([
    'pageTitle' => __('admin_dashboard'),
    'activeAdminNav' => 'dashboard',
]);
$base = url_base() . '/admin';

$prodBlockers = AppEnvironment::isProduction() ? StorefrontReadiness::productionBlockers() : [];

$dash = $range !== null
    ? AdminDashboardService::dashboardOverview($range)
    : AdminDashboardService::emptyDashboardPayloadForView();

/** @var array<string, mixed> $dash */

$trends = $dash['trends'];
$days = $trends['days'] ?? [];
$revMax = (float) ($trends['revenue_max'] ?? 1);
$ordMax = (int) ($trends['orders_max'] ?? 1);
if ($revMax <= 0) {
    $revMax = 1;
}
if ($ordMax <= 0) {
    $ordMax = 1;
}

$kpiList = [
    [
        'label' => "Today's revenue (paid)",
        'href' => $base . '/reports/sales.php',
        'icon' => 'chart',
        'modifier' => '',
        'data' => $dash['kpis']['revenue'] ?? [],
    ],
    [
        'label' => "Today's orders (placed)",
        'href' => $base . '/orders/index.php',
        'icon' => 'receipt',
        'modifier' => '',
        'data' => $dash['kpis']['orders'] ?? [],
    ],
    [
        'label' => "Today's AOV (paid)",
        'href' => $base . '/reports/sales.php',
        'icon' => 'percent',
        'modifier' => '',
        'data' => $dash['kpis']['aov'] ?? [],
    ],
    [
        'label' => 'Pending payments',
        'href' => $base . '/payments/index.php',
        'icon' => 'wallet',
        'modifier' => 'at-kpi--warn',
        'data' => $dash['kpis']['pending_payments'] ?? [],
    ],
    [
        'label' => 'Active subscriptions',
        'href' => $base . '/subscriptions/index.php',
        'icon' => 'repeat',
        'modifier' => 'at-kpi--info',
        'data' => $dash['kpis']['active_subscriptions'] ?? [],
    ],
    [
        'label' => 'Low-stock variants',
        'href' => $base . '/inventory/index.php',
        'icon' => 'box',
        'modifier' => 'at-kpi--danger',
        'data' => $dash['kpis']['low_stock'] ?? [],
    ],
];

$statusSegs = $dash['order_status']['segments'] ?? [];
$statusTotal = (int) ($dash['order_status']['total'] ?? 0);

$conicStops = [];
$cumPct = 0.0;
if ($statusTotal > 0) {
    foreach ($statusSegs as $seg) {
        $c = (int) ($seg['count'] ?? 0);
        if ($c <= 0) {
            continue;
        }
        $pct = ($c / $statusTotal) * 100;
        $next = $cumPct + $pct;
        $conicStops[] = ($seg['color'] ?? '#ccc') . ' ' . round($cumPct, 4) . '% ' . round($next, 4) . '%';
        $cumPct = $next;
    }
}
$donutStyle = $conicStops !== []
    ? 'background: conic-gradient(from -90deg, ' . implode(', ', $conicStops) . ');'
    : '';

$deliveries = $dash['deliveries_today'] ?? ['scheduled_today' => 0, 'subscription_skips' => 0];
$preset = $dashQuery['preset'] ?? 'last7';
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

<?php if ($rangeError !== null): ?>
    <div class="at-alert at-alert--error mb-4 text-sm"><?= Security::escape($rangeError) ?> Showing last 7 days.</div>
<?php endif; ?>

<header class="at-dash-header">
    <div>
        <h1 class="at-dash-header__title"><?= Security::escape(__('admin_dashboard')) ?></h1>
        <p class="at-dash-header__meta">
            <span class="at-dash-pill"><?= at_icon('clock', 'at-dash-pill__icon') ?><?= Security::escape($dash['range_label'] ?? '') ?></span>
            <?php if (!empty($dash['period_summary'])): ?>
                <span class="at-muted text-sm">
                    Revenue <?= Security::escape($dash['period_summary']['revenue']['formatted'] ?? '') ?> ·
                    <?= (int) ($dash['period_summary']['orders']['value'] ?? 0) ?> paid orders
                </span>
            <?php endif; ?>
        </p>
    </div>
    <form class="at-dash-filters" method="get" action="">
        <label class="at-dash-filters__field">
            <span class="at-visually-hidden">Date range</span>
            <select name="preset" id="at-dash-preset" class="at-dash-filters__select">
                <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="last7" <?= $preset === 'last7' ? 'selected' : '' ?>>Last 7 days</option>
                <option value="last30" <?= $preset === 'last30' ? 'selected' : '' ?>>Last 30 days</option>
                <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This month</option>
                <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom range</option>
            </select>
        </label>
        <span class="at-dash-filters__custom" id="at-dash-custom-wrap">
            <input type="date" name="from" id="at-dash-from-input" value="<?= Security::escape((string) ($dashQuery['from'] ?? '')) ?>" class="at-dash-filters__date" aria-label="From date">
            <span class="at-dash-filters__sep">—</span>
            <input type="date" name="to" id="at-dash-to-input" value="<?= Security::escape((string) ($dashQuery['to'] ?? '')) ?>" class="at-dash-filters__date" aria-label="To date">
        </span>
        <button type="submit" class="at-btn at-btn--primary at-btn--sm at-focusable">Apply</button>
    </form>
</header>

<div class="grid grid-cols-2 xl:grid-cols-3 gap-3 md:gap-4">
    <?php foreach ($kpiList as $kpi): ?>
        <?php
        $d = $kpi['data'];
        $delta = $d['delta'] ?? null;
        $deltaLabel = $d['delta_label'] ?? null;
        $trendClass = 'at-dash-kpi__trend--flat';
        if ($delta !== null) {
            $trendClass = $delta > 0 ? 'at-dash-kpi__trend--up' : ($delta < 0 ? 'at-dash-kpi__trend--down' : 'at-dash-kpi__trend--flat');
        }
        ?>
        <a href="<?= Security::escape($kpi['href']) ?>" class="at-dash-kpi at-kpi at-focusable block <?= Security::escape($kpi['modifier']) ?>">
            <span class="at-kpi__icon"><?= at_icon($kpi['icon']) ?></span>
            <p class="at-kpi__label"><?= Security::escape($kpi['label']) ?></p>
            <p class="at-kpi__value"><?= Security::escape((string) ($d['formatted'] ?? '')) ?></p>
            <?php if ($deltaLabel !== null && $deltaLabel !== ''): ?>
                <p class="at-dash-kpi__trend <?= Security::escape($trendClass) ?>"><?= Security::escape($deltaLabel) ?></p>
            <?php else: ?>
                <p class="at-dash-kpi__trend at-dash-kpi__trend--muted">No comparison</p>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-2 gap-4 mt-6">
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head">
            <h2>Revenue trend (paid, excl. cancelled)</h2>
        </div>
        <div class="at-admin-card__body">
            <?php if ($days === []): ?>
                <p class="text-sm at-muted">No orders in this range.</p>
            <?php else: ?>
                <div class="at-chart" role="img" aria-label="Daily revenue">
                    <?php foreach ($days as $d): ?>
                        <?php $h = (float) ($d['revenue'] ?? 0); ?>
                        <div class="at-chart__col">
                            <b><?= Security::escape(catalogue_format_price($h)) ?></b>
                            <div class="at-chart__bar at-dash-chart__bar--rev" style="height: <?= (int) round($h / $revMax * 100) ?>%"></div>
                            <?= Security::escape((string) ($d['label'] ?? '')) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head">
            <h2>Orders trend (paid, excl. cancelled)</h2>
        </div>
        <div class="at-admin-card__body">
            <?php if ($days === []): ?>
                <p class="text-sm at-muted">No orders in this range.</p>
            <?php else: ?>
                <div class="at-chart" role="img" aria-label="Daily paid order count">
                    <?php foreach ($days as $d): ?>
                        <?php $oc = (int) ($d['orders'] ?? 0); ?>
                        <div class="at-chart__col">
                            <b><?= (int) $oc ?></b>
                            <div class="at-chart__bar at-dash-chart__bar--ord" style="height: <?= (int) round($oc / $ordMax * 100) ?>%"></div>
                            <?= Security::escape((string) ($d['label'] ?? '')) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="grid lg:grid-cols-2 gap-4 mt-4">
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head"><h2>Order status distribution</h2></div>
        <div class="at-admin-card__body at-dash-donut-wrap">
            <?php if ($statusTotal <= 0): ?>
                <p class="text-sm at-muted">No orders in this range.</p>
            <?php else: ?>
                <div class="at-dash-donut" style="<?= Security::escape($donutStyle) ?>" role="img" aria-label="Order status shares"></div>
                <ul class="at-dash-legend">
                    <?php foreach ($statusSegs as $seg): ?>
                        <?php if ((int) ($seg['count'] ?? 0) <= 0) {
                            continue;
                        } ?>
                        <li>
                            <span class="at-dash-legend__swatch" style="background:<?= Security::escape((string) ($seg['color'] ?? '#999')) ?>"></span>
                            <span class="at-dash-legend__label"><?= Security::escape((string) ($seg['label'] ?? '')) ?></span>
                            <span class="at-dash-legend__val"><?= (int) ($seg['count'] ?? 0) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head"><h2>Today's subscription deliveries</h2></div>
        <div class="at-admin-card__body">
            <p class="text-3xl font-extrabold"><?= (int) ($deliveries['scheduled_today'] ?? 0) ?> <span class="text-sm font-medium at-muted">scheduled</span></p>
            <?php if ((int) ($deliveries['subscription_skips'] ?? 0) > 0): ?>
                <p class="text-sm at-muted mt-1"><?= (int) $deliveries['subscription_skips'] ?> subscription skip(s) today</p>
            <?php else: ?>
                <p class="text-sm at-muted mt-1">No subscription skips recorded for today.</p>
            <?php endif; ?>
            <a href="<?= Security::escape($base) ?>/subscriptions/deliveries.php" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-4"><?= at_icon('truck') ?>Manage deliveries</a>
        </div>
    </section>
</div>

<div class="grid lg:grid-cols-2 gap-4 mt-4">
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head"><h2>Payment analytics (orders)</h2></div>
        <div class="at-admin-card__body">
            <?php $opr = $dash['order_payment']['rows'] ?? []; ?>
            <?php if ($opr === []): ?>
                <p class="text-sm at-muted">No data.</p>
            <?php else: ?>
                <ul class="at-dash-statlist">
                    <?php foreach ($opr as $row): ?>
                        <li>
                            <span><?= Security::escape((string) ($row['label'] ?? '')) ?></span>
                            <strong><?= (int) ($row['count'] ?? 0) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head"><h2>Payment records (by row status)</h2></div>
        <div class="at-admin-card__body">
            <?php $prec = $dash['payment_records']['rows'] ?? []; ?>
            <?php if ($prec === []): ?>
                <p class="text-sm at-muted">No payment rows in this range.</p>
            <?php else: ?>
                <ul class="at-dash-statlist">
                    <?php foreach ($prec as $row): ?>
                        <li>
                            <span><?= Security::escape((string) ($row['label'] ?? '')) ?></span>
                            <strong><?= (int) ($row['count'] ?? 0) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="grid lg:grid-cols-2 gap-4 mt-4">
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head"><h2>Subscription overview (all-time)</h2></div>
        <div class="at-admin-card__body">
            <?php $sub = $dash['subscription'] ?? []; ?>
            <ul class="at-dash-statlist">
                <li><span>Active subscriptions</span><strong><?= (int) ($sub['active'] ?? 0) ?></strong></li>
                <li><span>Upcoming delivery days (scheduled, from today)</span><strong><?= (int) ($sub['upcoming'] ?? 0) ?></strong></li>
                <li><span>Completed deliveries (delivered)</span><strong><?= (int) ($sub['delivered'] ?? 0) ?></strong></li>
                <li><span>Skipped delivery days</span><strong><?= (int) ($sub['skipped'] ?? 0) ?></strong></li>
            </ul>
        </div>
    </section>
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head">
            <h2>Inventory alerts</h2>
            <a href="<?= Security::escape($base) ?>/inventory/index.php" class="text-sm font-semibold underline at-focusable">View inventory</a>
        </div>
        <div class="at-admin-card__body">
            <?php $inv = $dash['inventory'] ?? []; ?>
            <ul class="at-dash-statlist">
                <li><span>Low-stock variants</span><strong><?= (int) ($inv['low'] ?? 0) ?></strong></li>
                <li><span>Out of stock (published)</span><strong><?= (int) ($inv['out'] ?? 0) ?></strong></li>
                <li><span>Variants needing attention</span><strong><?= (int) ($inv['attention'] ?? 0) ?></strong></li>
            </ul>
        </div>
    </section>
</div>

<div class="grid lg:grid-cols-1 gap-4 mt-4">
    <section class="at-admin-card at-dash-card">
        <div class="at-admin-card__head">
            <h2>Top selling products</h2>
            <span class="text-xs at-muted">Paid orders, range selected</span>
        </div>
        <div class="at-admin-card__body p-0">
            <?php $tops = $dash['top_products'] ?? []; ?>
            <?php if ($tops === []): ?>
                <p class="px-5 py-4 text-sm at-muted">No line items in this range.</p>
            <?php else: ?>
                <div class="at-admin-table-wrap">
                    <table class="at-admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col" class="is-num">Qty</th>
                                <th scope="col" class="is-num">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tops as $tp): ?>
                                <tr>
                                    <td>
                                        <div class="at-dash-product">
                                            <?php if (!empty($tp['image_url'])): ?>
                                                <img src="<?= Security::escape((string) $tp['image_url']) ?>" alt="" class="at-dash-product__img" width="40" height="40" loading="lazy">
                                            <?php else: ?>
                                                <span class="at-dash-product__placeholder"><?= at_icon('image') ?></span>
                                            <?php endif; ?>
                                            <span class="at-dash-product__name"><?= Security::escape((string) ($tp['product_name'] ?? '')) ?></span>
                                        </div>
                                    </td>
                                    <td class="is-num"><?= (int) ($tp['qty_sold'] ?? 0) ?></td>
                                    <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($tp['revenue'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="at-admin-card mt-4 at-dash-card">
    <div class="at-admin-card__head">
        <h2>Recent orders</h2>
        <a href="<?= Security::escape($base) ?>/orders/index.php" class="text-sm font-semibold underline at-focusable">All orders</a>
    </div>
    <?php $recent = $dash['recent_orders'] ?? []; ?>
    <?php if ($recent === []): ?>
        <p class="px-5 py-4 text-sm at-muted">No orders yet.</p>
    <?php else: ?>
        <div class="at-admin-table-wrap at-dash-recent-wrap">
            <table class="at-admin-table">
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="is-num">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col">When</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $ro): ?>
                        <tr>
                            <td>
                                <a href="<?= Security::escape((string) ($ro['href'] ?? '#')) ?>" class="underline font-semibold at-focusable"><?= Security::escape((string) ($ro['order_number'] ?? '')) ?></a>
                            </td>
                            <td><?= Security::escape((string) ($ro['customer'] ?? '')) ?></td>
                            <td class="is-num"><?= Security::escape((string) ($ro['amount'] ?? '')) ?></td>
                            <td><span class="at-status <?= Security::escape((string) ($ro['status_class'] ?? '')) ?>"><?= Security::escape((string) ($ro['status'] ?? '')) ?></span></td>
                            <td class="text-sm"><?= Security::escape((string) ($ro['placed_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (AdminDemoPerms::can('support')): ?>
    <section class="at-admin-card mt-4 at-dash-card">
        <div class="at-admin-card__head">
            <h2>Open support tickets</h2>
            <a href="<?= Security::escape($base) ?>/support/index.php" class="text-sm font-semibold underline at-focusable">Support</a>
        </div>
        <div class="at-admin-card__body">
            <p class="text-2xl font-extrabold"><?= (int) ($dash['open_tickets'] ?? 0) ?></p>
        </div>
    </section>
<?php endif; ?>

<script>
(function () {
    var preset = document.getElementById('at-dash-preset');
    var wrap = document.getElementById('at-dash-custom-wrap');
    var fromI = document.getElementById('at-dash-from-input');
    var toI = document.getElementById('at-dash-to-input');
    if (!preset || !wrap) return;
    function sync() {
        var isCustom = preset.value === 'custom';
        wrap.style.display = isCustom ? 'inline-flex' : 'none';
        if (fromI) {
            fromI.disabled = !isCustom;
            fromI.setAttribute('aria-hidden', isCustom ? 'false' : 'true');
        }
        if (toI) {
            toI.disabled = !isCustom;
            toI.setAttribute('aria-hidden', isCustom ? 'false' : 'true');
        }
    }
    preset.addEventListener('change', sync);
    sync();
})();
</script>
<?php admin_layout_end(); ?>
