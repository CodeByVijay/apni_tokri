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
if ($sub !== null) {
    SubscriptionCreditScheduleService::resolveSubscription((int) $sub['id']);
    $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subNumber, $customerId);
}
if ($sub === null) {
    layout_public_start(['pageTitle' => 'Subscription details — Veggie Tokri', 'activeNav' => 'account']);
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
$isCompleted = $status === 'completed';
$purchasedTotal = SubscriptionCreditScheduleService::purchasedCredits($sub);
$deliveredCount = SubscriptionCreditScheduleService::deliveredCount($sub);
$remainingCredits = (int) ($sub['deliveries_remaining'] ?? 0);
$pauseRow = SubscriptionCreditScheduleService::currentPauseRow((int) $sub['id']);
$showCalendar = $isActive || $isPaused || $isCompleted;
$days = $showCalendar ? CustomerSubscriptionRepository::deliveryDays((int) $sub['id']) : [];

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
        $skippedMenu = $basketResult['skipped'] ?? [];
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
            'skipped_menu' => $skippedMenu,
        ];
    }
} elseif ($showCalendar) {
    foreach ($days as $day) {
        $dayPayloads[] = [
            'id' => (int) $day['id'],
            'date' => (string) $day['delivery_date'],
            'index' => (int) $day['day_index'],
            'day_status' => (string) ($day['status'] ?? 'scheduled'),
            'locked' => true,
            'cutoff' => (string) ($day['selection_cutoff_at'] ?? ''),
            'basket_status' => (string) ($day['basket_status'] ?? 'none'),
            'basket_row_status' => '',
            'fulfilment_status' => (string) ($day['fulfilment_status'] ?? 'none'),
            'order_number' => '',
            'substitutions' => [],
            'total' => 0.0,
            'items' => [],
            'skipped_menu' => [],
        ];
    }
}

layout_public_start(['pageTitle' => 'Subscription details — Veggie Tokri', 'activeNav' => 'account']);
$accountActive = 'subscriptions';
$accountTitle = $name;
$accountSub = 'Daily allowance ' . catalogue_format_price($allowance) . ' · ' . $subNumber;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<?php if ($status === 'pending_payment' || $status === 'payment_failed'): ?>
    <div class="at-alert at-alert--info">Your delivery calendar and baskets appear after payment activates the subscription.</div>
<?php else: ?>
    <?php if ($isCompleted): ?>
        <div class="at-alert at-alert--info">This subscription is complete — all purchased deliveries have been fulfilled.</div>
    <?php endif; ?>
    <div class="at-card mt-2">
        <div class="at-card__body text-sm">
            <dl class="at-summary-list">
                <div><dt>Purchased deliveries</dt><dd><?= (int) $purchasedTotal ?></dd></div>
                <div><dt>Delivered</dt><dd><?= (int) $deliveredCount ?></dd></div>
                <div><dt>Remaining credits</dt><dd><?= (int) $remainingCredits ?></dd></div>
                <?php if ((string) ($sub['calendar_end_date'] ?? '') !== ''): ?>
                    <div><dt>Schedule horizon</dt><dd><?= Security::escape((string) $sub['calendar_end_date']) ?> <span class="at-muted">(extends with skips/pause; not an expiry)</span></dd></div>
                <?php endif; ?>
            </dl>
        </div>
    </div>
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
                <div class="text-sm">
                    <p>Your subscription is <strong>paused</strong>. Pause does not use delivery credits.</p>
                    <?php if ($pauseRow):
                        $ps = (string) ($pauseRow['pause_start_date'] ?? substr((string) ($pauseRow['paused_at'] ?? ''), 0, 10));
                        $ro = (string) ($pauseRow['resume_on_date'] ?? '');
                        ?>
                        <p class="at-muted mt-1">
                            <?php if ($ps !== ''): ?>From <strong><?= Security::escape($ps) ?></strong><?php endif; ?>
                            <?php if ($ro !== ''): ?> — auto-resume on <strong><?= Security::escape($ro) ?></strong><?php endif; ?>
                            (Asia/Kolkata). Resume early anytime, or wait for automatic resume when you next open this page or when our delivery jobs run.
                        </p>
                    <?php endif; ?>
                </div>
                <button type="button" class="at-btn at-btn--primary at-focusable" id="at-sub-resume"><?= at_icon('play', 'w-4 h-4') ?> Resume now</button>
            </div>
        </div>
    <?php elseif ($pauseRow && (string) ($pauseRow['status'] ?? '') === 'pending'): ?>
        <div class="at-alert at-alert--info text-sm mt-4">
            Pause scheduled from <?= Security::escape((string) ($pauseRow['pause_start_date'] ?? '')) ?>
            until <?= Security::escape((string) ($pauseRow['resume_on_date'] ?? '')) ?>.
        </div>
    <?php elseif (!empty($skipPolicy['pause_allowed']) && $isActive): ?>
        <div class="at-card mt-4" id="at-pause-form">
            <div class="at-card__body">
                <h2 class="at-h3">Pause subscription</h2>
                <p class="text-sm at-muted mt-1">Choose pause start and expected resume date (max <?= (int) SubscriptionCreditScheduleService::MAX_PAUSE_CALENDAR_DAYS ?> consecutive days). Credits are preserved.</p>
                <div class="flex flex-wrap gap-3 mt-3">
                    <div><label class="at-label">Pause from</label>
                        <input type="date" class="at-input at-focusable" id="at-pause-start" name="pause_start_date"></div>
                    <div><label class="at-label">Resume on</label>
                        <input type="date" class="at-input at-focusable" id="at-pause-resume" name="resume_on_date"></div>
                </div>
                <button type="button" class="at-btn at-btn--secondary at-focusable mt-4" id="at-sub-pause"><?= at_icon('pause', 'w-4 h-4') ?> Schedule pause</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showCalendar && $dayPayloads !== []): ?>
    <?php foreach ($dayPayloads as $dp): if ($dp['day_status'] === 'skipped'): ?>
        <p class="text-sm at-muted mt-2">Skipped delivery on <?= Security::escape($dp['date']) ?> — credit preserved.</p>
    <?php endif; endforeach; ?>
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

    <?php foreach ($dayPayloads as $dp): if ($dp['day_status'] === 'skipped') {
        continue;
    } ?>
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

                <?php if (!empty($dp['skipped_menu'])): ?>
                    <div class="at-alert at-alert--info text-sm mt-3">
                        <p class="font-semibold mb-1">Some menu items were not included</p>
                        <p class="at-muted">We never substitute a different product when an item is unavailable. Your suggested basket only includes what is in stock and published.</p>
                        <ul class="mt-2 space-y-1">
                            <?php foreach ($dp['skipped_menu'] as $skip): ?>
                                <li><?= Security::escape((string) ($skip['product_name'] ?? '') . ' — ' . ($skip['variant_name'] ?? '')) ?>
                                    <span class="at-muted">(<?= Security::escape((string) ($skip['reason'] ?? '')) ?>)</span></li>
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
                <?php elseif ($dp['skipped_menu'] !== []): ?>
                    <p class="text-sm at-muted mt-2">No suggested basket could be built from the menu for this day. See skipped items above.</p>
                <?php else: ?>
                    <p class="text-sm at-muted mt-2">No suggested basket yet — configure admin menu templates with in-stock items.</p>
                <?php endif; ?>

                <?php if (!$dp['locked'] && $dp['day_status'] === 'scheduled'): ?>
                    <div class="flex flex-wrap gap-2 mt-4">
                        <button type="button" class="at-btn at-btn--primary at-focusable at-accept-suggested" data-day-id="<?= (int) $dp['id'] ?>">Accept suggested basket</button>
                        <?php if ((int) ($skipPolicy['max_skips'] ?? 0) === 0 || $skipsUsed < (int) $skipPolicy['max_skips']): ?>
                            <button type="button" class="at-btn at-btn--secondary at-focusable at-skip-day" data-day-id="<?= (int) $dp['id'] ?>"><?= at_icon('skip', 'w-4 h-4') ?>Skip delivery</button>
                            <span class="text-xs at-muted w-full">Skipping does not use a delivery credit.</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php elseif ($showCalendar && $dayPayloads !== []): ?>
        <section class="at-card mt-4">
            <div class="at-card__body">
                <h2 class="at-h3">Delivery calendar</h2>
                <ul class="mt-3 text-sm space-y-2">
                    <?php foreach ($dayPayloads as $dp): ?>
                        <li>
                            #<?= (int) $dp['index'] ?> · <?= Security::escape($dp['date']) ?>
                            — <?= Security::escape($dp['day_status']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($isActive): ?>
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
                var ps = $('#at-pause-start').val();
                var ro = $('#at-pause-resume').val();
                if (!ps || !ro) {
                    alert('Choose both pause start and resume dates.');
                    return;
                }
                if (!confirm('Schedule pause from ' + ps + ' until ' + ro + '?')) return;
                $.post(lc.url, {
                    _csrf_token: lc.csrf,
                    action: 'pause',
                    subscription_number: lc.subscriptionNumber,
                    pause_start_date: ps,
                    resume_on_date: ro
                }).done(function () { location.reload(); }).fail(function (xhr) {
                    alert((xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.message) || 'Pause failed');
                });
            });
            $('#at-sub-resume').on('click', function () {
                $.post(lc.url, { _csrf_token: lc.csrf, action: 'resume', subscription_number: lc.subscriptionNumber }).done(function () { location.reload(); });
            });
            $('.at-skip-day').on('click', function () {
                var dayId = $(this).data('day-id');
                if (!confirm('Skip this delivery? Your credit stays — we will add a future delivery date.')) return;
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
