<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$subNumber = isset($_GET['sub']) && is_string($_GET['sub']) ? trim($_GET['sub']) : '';
$base = url_base();
$customerId = CustomerAuth::id();

if ($subNumber === '' || $customerId === null || !CommerceGateway::useDatabase() || !Install::isSchemaReady()) {
    header('Location: ' . $base . '/my-subscriptions.php', true, 302);
    exit;
}

$sub = CustomerSubscriptionRepository::findByNumberForCustomer($subNumber, $customerId);
if ($sub === null) {
    layout_public_start(['pageTitle' => 'Subscription details — Apni Tokri', 'activeNav' => 'account']);
    $emptyTitle = 'Subscription not found';
    $emptyMessage = 'We could not find this subscription on your account.';
    $emptyActionHref = $base . '/my-subscriptions.php';
    $emptyActionLabel = 'My subscriptions';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
$name = (string) ($snapshot['name'] ?? 'Subscription');
$status = (string) $sub['status'];
$allowance = SubscriptionBasketService::dailyAllowance($snapshot);
$skipPolicy = is_array($snapshot['skip_pause_policy_json'] ?? null) ? $snapshot['skip_pause_policy_json'] : [];
$skipsUsed = SubscriptionLifecycleService::countSkips((int) $sub['id']);
$notifications = CustomerNotificationService::unreadForCustomer($customerId);
$dayCredits = SubscriptionRefundService::creditsForCustomer($customerId);
$isActive = $status === 'active';
$isPaused = $status === 'paused';
$days = ($isActive || $isPaused) ? CustomerSubscriptionRepository::deliveryDays((int) $sub['id']) : [];

$dayPayloads = [];
$selectableVariants = [];
if ($isActive) {
    foreach (InventoryService::variantsForSelect() as $v) {
        if ((int) ($v['stock_qty'] ?? 0) > 0) {
            $selectableVariants[] = $v;
        }
    }
    foreach ($days as $day) {
        SubscriptionBasketService::backfillDayCutoff($day, $snapshot);
        $dayFresh = SubscriptionBasketRepository::dayByIdForSubscription((int) $day['id'], (int) $sub['id']) ?? $day;
        $locked = SubscriptionCutoff::isLocked($dayFresh);
        $basketResult = SubscriptionBasketService::ensureSuggestedBasket($sub, $dayFresh, $snapshot);
        $basket = $basketResult['basket'] ?? null;
        $items = $basketResult['items'] ?? [];
        $total = $basket ? (float) $basket['total_inr'] : 0.0;
        $orderNumber = '';
        $fulfilmentStatus = (string) ($dayFresh['fulfilment_status'] ?? 'none');
        $genOrderId = (int) ($dayFresh['generated_order_id'] ?? 0);
        if ($genOrderId > 0) {
            Install::ensureConnection();
            $on = Database::pdo()->prepare('SELECT order_number FROM orders WHERE id = ? LIMIT 1');
            $on->execute([$genOrderId]);
            $orderNumber = (string) ($on->fetchColumn() ?: '');
        }
        $pendingSubs = SubscriptionSubstitutionService::pendingForDay((int) $dayFresh['id'], $customerId);
        $dayPayloads[] = [
            'id' => (int) $dayFresh['id'],
            'date' => (string) $dayFresh['delivery_date'],
            'index' => (int) $dayFresh['day_index'],
            'day_status' => (string) ($dayFresh['status'] ?? 'scheduled'),
            'locked' => $locked,
            'cutoff' => (string) ($dayFresh['selection_cutoff_at'] ?? ''),
            'basket_status' => (string) ($dayFresh['basket_status'] ?? 'none'),
            'basket_row_status' => $basket ? (string) $basket['status'] : '',
            'fulfilment_status' => $fulfilmentStatus,
            'order_number' => $orderNumber,
            'substitutions' => $pendingSubs,
            'total' => $total,
            'items' => $items,
        ];
    }
}

layout_public_start(['pageTitle' => 'Subscription details — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'subscriptions';
$accountTitle = $name;
$accountSub = 'Daily allowance ' . catalogue_format_price($allowance) . ' · ' . $subNumber;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<?php if ($status !== 'active' && !$isPaused): ?>
    <div class="at-alert at-alert--info">Your delivery calendar and baskets appear after payment activates the subscription.</div>
<?php else: ?>
    <?php if ($dayCredits !== []): ?>
        <div class="at-alert at-alert--info text-sm mt-2">
            <div class="min-w-0">
                <p class="font-semibold mb-1">Delivery credits &amp; refunds</p>
                <ul class="space-y-1">
                    <?php foreach ($dayCredits as $cr): ?>
                        <li><?= Security::escape((string) ($cr['delivery_date'] ?? '')) ?> — <?= Security::escape(SubscriptionRefundService::publicRefundStatusLabel($cr)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($notifications !== []): ?>
        <div class="at-alert at-alert--warning text-sm mt-2">
            <div class="min-w-0">
                <?php foreach ($notifications as $n): ?>
                    <p class="mb-1"><strong><?= Security::escape((string) $n['title']) ?></strong> — <?= Security::escape((string) $n['body']) ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isPaused): ?>
        <div class="at-card mt-4">
            <div class="at-card__body flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm">Your subscription is <strong>paused</strong>. Deliveries are on hold until you resume.</p>
                <button type="button" class="at-btn at-btn--primary at-focusable" id="at-sub-resume"><?= at_icon('play', 'w-4 h-4') ?> Resume</button>
            </div>
        </div>
    <?php elseif (!empty($skipPolicy['pause_allowed'])): ?>
        <div class="at-card mt-4">
            <div class="at-card__body flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm at-muted">Need a break? Pause extends your calendar per plan rules; remaining deliveries are preserved.</p>
                <button type="button" class="at-btn at-btn--secondary at-focusable" id="at-sub-pause"><?= at_icon('pause', 'w-4 h-4') ?> Pause subscription</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isActive): ?>
    <div class="at-alert at-alert--info text-sm">
        <?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?>
        <span>Suggested baskets use admin menus and real stock. Customizing never charges extra — totals above your allowance are blocked server-side.</span>
    </div>

    <section class="at-card mt-4" id="at-sub-multi">
        <div class="at-card__body">
            <h2 class="at-h3">Multi-day customization</h2>
            <p class="text-sm at-muted mt-1">Select several upcoming days, edit lines once, then save or confirm all selected days in one step.</p>
            <div class="flex flex-wrap gap-2 mt-3" id="at-day-picks">
                <?php foreach ($dayPayloads as $dp): if ($dp['locked']) {
                    continue;
                } ?>
                    <label class="at-badge at-badge--neutral cursor-pointer">
                        <input type="checkbox" class="at-multi-day" value="<?= (int) $dp['id'] ?>"> <?= Security::escape($dp['date']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 grid gap-2" id="at-multi-lines">
                <?php foreach (array_slice($selectableVariants, 0, 5) as $i => $v): ?>
                    <div class="flex flex-wrap gap-2 items-center multi-line">
                        <select class="at-input at-focusable variant-select min-w-0 max-w-full">
                            <?php foreach ($selectableVariants as $opt): ?>
                                <option value="<?= (int) $opt['id'] ?>" <?= (int) $opt['id'] === (int) $v['id'] ? 'selected' : '' ?>>
                                    <?= Security::escape($opt['product_name'] . ' — ' . $opt['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" class="at-input at-focusable w-24 qty-input" min="0" step="0.001" value="1" placeholder="Qty">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex flex-wrap gap-2 mt-4">
                <button type="button" class="at-btn at-btn--secondary at-focusable" id="at-multi-save">Save draft (selected days)</button>
                <button type="button" class="at-btn at-btn--primary at-focusable" id="at-multi-confirm">Confirm (selected days)</button>
            </div>
        </div>
    </section>

    <?php foreach ($dayPayloads as $dp): ?>
        <section class="at-card mt-4" data-day-id="<?= (int) $dp['id'] ?>">
            <div class="at-card__body">
                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        <h2 class="at-h3">Delivery #<?= (int) $dp['index'] ?> · <?= Security::escape($dp['date']) ?></h2>
                        <p class="text-sm at-muted">Cutoff: <?= Security::escape($dp['cutoff'] !== '' ? $dp['cutoff'] : '—') ?> (Asia/Kolkata)</p>
                    </div>
                    <span class="at-status at-status--<?= $dp['locked'] ? 'warn' : 'ok' ?>"><?= $dp['locked'] ? 'Locked' : Security::escape($dp['basket_row_status'] ?: $dp['basket_status']) ?></span>
                </div>

                <dl class="at-summary-list mt-3 text-sm">
                    <div><dt>Basket total</dt><dd><?= Security::escape(catalogue_format_price((float) $dp['total'])) ?></dd></div>
                    <div><dt>Daily allowance</dt><dd><?= Security::escape(catalogue_format_price($allowance)) ?></dd></div>
                    <?php if ($dp['fulfilment_status'] !== 'none'): ?>
                        <div><dt>Fulfilment</dt><dd><?= Security::escape($dp['fulfilment_status']) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($dp['order_number'] !== ''): ?>
                        <div><dt>Order</dt><dd><a href="<?= Security::escape($base . '/order.php?id=' . rawurlencode($dp['order_number'])) ?>"><?= Security::escape($dp['order_number']) ?></a></dd></div>
                    <?php endif; ?>
                </dl>

                <?php if ($dp['substitutions'] !== []): ?>
                    <div class="at-alert at-alert--warning text-sm mt-3 flex-col">
                        <p><strong>Substitution approval required</strong> — we never replace items without your consent.</p>
                        <ul class="mt-2 space-y-2">
                            <?php foreach ($dp['substitutions'] as $sub): ?>
                                <?php if ((string) $sub['status'] !== 'pending_customer') {
                                    continue;
                                } ?>
                                <li class="flex flex-wrap items-center justify-between gap-2">
                                    <span>Replace variant #<?= (int) $sub['original_variant_id'] ?> with #<?= (int) $sub['proposed_variant_id'] ?>
                                        <?php if ((float) $sub['price_difference_inr'] > 0.01): ?>
                                            (extra <?= catalogue_format_price((float) $sub['price_difference_inr']) ?> — contact support)
                                        <?php else: ?>
                                            (no extra charge)
                                        <?php endif; ?>
                                    </span>
                                    <span class="flex flex-wrap gap-2">
                                        <button type="button" class="at-btn at-btn--secondary at-focusable at-sub-sub-reject-partial" data-id="<?= (int) $sub['id'] ?>">Deliver without item</button>
                                        <button type="button" class="at-btn at-btn--secondary at-focusable at-sub-sub-reject-cancel" data-id="<?= (int) $sub['id'] ?>">Cancel this day</button>
                                        <button type="button" class="at-btn at-btn--primary at-focusable at-sub-sub-approve" data-id="<?= (int) $sub['id'] ?>">Approve<?= (float) $sub['price_difference_inr'] > 0.01 ? ' & pay ' . catalogue_format_price((float) $sub['price_difference_inr']) : '' ?></button>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($dp['items'] !== []): ?>
                    <ul class="mt-3 divide-y divide-[var(--at-line)] text-sm">
                        <?php foreach ($dp['items'] as $it): ?>
                            <li class="py-2 flex justify-between gap-3">
                                <span><?= Security::escape($it['product_name'] . ' — ' . $it['variant_name']) ?> · <?= Security::escape((string) $it['qty']) ?> <?= Security::escape((string) $it['unit_label']) ?> @ <?= catalogue_format_price((float) $it['unit_price_inr']) ?></span>
                                <strong><?= catalogue_format_price((float) $it['line_total_inr']) ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-sm at-muted mt-2">No suggested basket yet — configure admin menu templates with in-stock items.</p>
                <?php endif; ?>

                <?php if (!$dp['locked'] && $dp['day_status'] === 'scheduled'): ?>
                    <div class="flex flex-wrap gap-2 mt-4">
                        <button type="button" class="at-btn at-btn--primary at-focusable at-accept-suggested" data-day-id="<?= (int) $dp['id'] ?>">Accept suggested basket</button>
                        <?php if ((int) ($skipPolicy['max_skips'] ?? 0) === 0 || $skipsUsed < (int) $skipPolicy['max_skips']): ?>
                            <button type="button" class="at-btn at-btn--secondary at-focusable at-skip-day" data-day-id="<?= (int) $dp['id'] ?>"><?= at_icon('skip', 'w-4 h-4') ?>Skip delivery</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <script>
    window.AT_SUB_BASKET = {
        csrf: <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>,
        url: <?= json_encode($base . '/ajax/subscription/basket.php', JSON_THROW_ON_ERROR) ?>,
        subUrl: <?= json_encode($base . '/ajax/subscription/substitution.php', JSON_THROW_ON_ERROR) ?>,
        base: <?= json_encode($base, JSON_THROW_ON_ERROR) ?>,
        subscriptionNumber: <?= json_encode($subNumber, JSON_THROW_ON_ERROR) ?>
    };
    </script>
    <script>
    (function ($) {
        if (typeof window.AT_SUB_BASKET === 'undefined') return;
        var cfg = window.AT_SUB_BASKET;
        function collectLines() {
            var lines = [];
            $('#at-multi-lines .multi-line').each(function () {
                var vid = parseInt($(this).find('.variant-select').val(), 10);
                var qty = parseFloat($(this).find('.qty-input').val());
                if (vid > 0 && qty > 0) lines.push({ variant_id: vid, qty: qty });
            });
            return lines;
        }
        function selectedDayIds() {
            var ids = [];
            $('.at-multi-day:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });
            return ids;
        }
        function postDays(ids, confirm) {
            var days = {};
            var lines = collectLines();
            ids.forEach(function (id) { days[id] = { lines: lines }; });
            return $.post(cfg.url, {
                _csrf_token: cfg.csrf,
                action: 'save',
                subscription_number: cfg.subscriptionNumber,
                confirm: confirm ? 1 : 0,
                days: days
            });
        }
        $('#at-multi-save').on('click', function () {
            var ids = selectedDayIds();
            if (!ids.length) { alert('Select at least one day.'); return; }
            postDays(ids, false).done(function () { location.reload(); }).fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Save failed');
            });
        });
        $('#at-multi-confirm').on('click', function () {
            var ids = selectedDayIds();
            if (!ids.length) { alert('Select at least one day.'); return; }
            postDays(ids, true).done(function () { location.reload(); }).fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Confirm failed');
            });
        });
        $('.at-accept-suggested').on('click', function () {
            var dayId = $(this).data('day-id');
            $.post(cfg.url, {
                _csrf_token: cfg.csrf,
                action: 'accept',
                subscription_number: cfg.subscriptionNumber,
                day_id: dayId
            }).done(function () { location.reload(); }).fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Could not accept');
            });
        });
        function postSubst(id, action, resolution) {
            var data = {
                _csrf_token: cfg.csrf,
                action: action,
                substitution_id: id
            };
            if (resolution) data.reject_resolution = resolution;
            return $.post(cfg.subUrl, data);
        }
        $('.at-sub-sub-approve').on('click', function () {
            var id = $(this).data('id');
            postSubst(id, 'approve').done(function (resp) {
                if (resp && resp.data && resp.data.requires_payment && resp.data.checkout) {
                    var c = resp.data.checkout;
                    if (c.mode === 'simulate') {
                        $.post(cfg.base + '/ajax/payment/simulate.php', {
                            _csrf_token: cfg.csrf,
                            substitution_id: c.substitution_id || id,
                            payment_token: c.token,
                            idempotency_key: c.idempotency_key,
                            outcome: 'success'
                        }).done(function () { location.reload(); });
                        return;
                    }
                }
                location.reload();
            }).fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Could not save');
            });
        });
        $('.at-sub-sub-reject-partial').on('click', function () {
            postSubst($(this).data('id'), 'reject', 'partial_delivery').done(function () { location.reload(); });
        });
        $('.at-sub-sub-reject-cancel').on('click', function () {
            if (!confirm('Cancel this delivery day? Credit applies per your plan policy.')) return;
            postSubst($(this).data('id'), 'reject', 'cancel_day').done(function () { location.reload(); });
        });
    })(jQuery);
    </script>
    <?php endif; ?>

    <script>
    window.AT_SUB_LIFECYCLE = {
        csrf: <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>,
        url: <?= json_encode($base . '/ajax/subscription/lifecycle.php', JSON_THROW_ON_ERROR) ?>,
        subscriptionNumber: <?= json_encode($subNumber, JSON_THROW_ON_ERROR) ?>
    };
    </script>
    <script>
    (function ($) {
        var lc = window.AT_SUB_LIFECYCLE;
        if (lc) {
            $('#at-sub-pause').on('click', function () {
                if (!confirm('Pause your subscription?')) return;
                $.post(lc.url, { _csrf_token: lc.csrf, action: 'pause', subscription_number: lc.subscriptionNumber }).done(function () { location.reload(); });
            });
            $('#at-sub-resume').on('click', function () {
                $.post(lc.url, { _csrf_token: lc.csrf, action: 'resume', subscription_number: lc.subscriptionNumber }).done(function () { location.reload(); });
            });
            $('.at-skip-day').on('click', function () {
                var dayId = $(this).data('day-id');
                if (!confirm('Skip this delivery? It may carry forward per your plan.')) return;
                $.post(lc.url, { _csrf_token: lc.csrf, action: 'skip', subscription_number: lc.subscriptionNumber, day_id: dayId })
                    .done(function () { location.reload(); })
                    .fail(function (xhr) {
                        alert((xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Skip failed');
                    });
            });
        }
    })(jQuery);
    </script>
<?php endif; ?>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
