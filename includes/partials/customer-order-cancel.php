<?php

declare(strict_types=1);

/** @var string $cancelOrderNumber */
/** @var bool $cancelEligible */
/** @var string|null $cancelMessage */
/** @var string|null $cancelSuccess */
/** @var string|null $cancelError */
/** @var array<string, string> $cancelReasons */

$cancelOrderNumber = $cancelOrderNumber ?? '';
$cancelEligible = !empty($cancelEligible);
$cancelMessage = $cancelMessage ?? null;
$cancelSuccess = $cancelSuccess ?? null;
$cancelError = $cancelError ?? null;
$cancelReasons = $cancelReasons ?? OrderCancellationService::CUSTOMER_REASONS;
$cancelFormAction = $cancelFormAction ?? (url_base() . '/order-details.php?id=' . rawurlencode($cancelOrderNumber));
?>
<?php if ($cancelSuccess !== null && $cancelSuccess !== ''): ?>
    <div class="at-alert at-alert--success mb-4" role="status"><?= Security::escape($cancelSuccess) ?></div>
<?php endif; ?>
<?php if ($cancelError !== null && $cancelError !== ''): ?>
    <div class="at-alert at-alert--error mb-4" role="alert"><?= Security::escape($cancelError) ?></div>
<?php endif; ?>
<?php if ($cancelEligible): ?>
    <section class="at-card at-order-cancel">
        <div class="at-card__body">
            <h2 class="at-h3">Cancel order</h2>
            <p class="text-sm at-muted mt-1">You can cancel this order until we start preparing it. Paid online orders will receive a refund request for admin processing.</p>
            <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-3" id="at-open-cancel-dialog" aria-haspopup="dialog">
                Cancel order
            </button>
        </div>
    </section>
    <dialog id="at-cancel-order-dialog" class="at-dialog max-w-md w-[calc(100%-2rem)] rounded-xl border border-[var(--at-line)] p-0 shadow-lg">
        <form method="post" action="<?= Security::escape($cancelFormAction) ?>" class="p-5 space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="cancel_order" value="1">
            <input type="hidden" name="idempotency_key" value="<?= Security::escape(Security::randomToken(16)) ?>">
            <h3 class="font-bold text-lg">Cancel this order?</h3>
            <p class="text-sm at-muted">This cannot be undone. Refunds for online payments are processed separately after review.</p>
            <div>
                <label class="at-label" for="cancel_reason_code">Reason</label>
                <select id="cancel_reason_code" name="reason_code" class="at-select at-focusable w-full" required>
                    <?php foreach ($cancelReasons as $code => $label): ?>
                        <option value="<?= Security::escape($code) ?>"><?= Security::escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="cancel_other_wrap" class="hidden">
                <label class="at-label" for="cancel_reason_detail">Details (optional for other reasons)</label>
                <textarea id="cancel_reason_detail" name="reason_detail" class="at-textarea at-focusable w-full" rows="3" maxlength="500" placeholder="Tell us a little more"></textarea>
            </div>
            <div class="flex flex-wrap gap-2 justify-end pt-2">
                <button type="button" class="at-btn at-btn--ghost at-focusable" id="at-close-cancel-dialog">Keep order</button>
                <button type="submit" class="at-btn at-btn--primary at-focusable">Confirm cancellation</button>
            </div>
        </form>
    </dialog>
    <script <?= Security::cspNonceAttr() ?>>
    (function () {
        var dlg = document.getElementById('at-cancel-order-dialog');
        var openBtn = document.getElementById('at-open-cancel-dialog');
        var closeBtn = document.getElementById('at-close-cancel-dialog');
        var reason = document.getElementById('cancel_reason_code');
        var otherWrap = document.getElementById('cancel_other_wrap');
        var detail = document.getElementById('cancel_reason_detail');
        if (!dlg || !openBtn) return;
        openBtn.addEventListener('click', function () { dlg.showModal(); });
        if (closeBtn) closeBtn.addEventListener('click', function () { dlg.close(); });
        function syncOther() {
            if (!reason || !otherWrap) return;
            var show = reason.value === 'other';
            otherWrap.classList.toggle('hidden', !show);
            if (detail) detail.required = show;
        }
        if (reason) { reason.addEventListener('change', syncOther); syncOther(); }
        <?php if (!empty($_GET['cancel'])): ?>dlg.showModal();<?php endif; ?>
    })();
    </script>
<?php elseif ($cancelMessage !== null && $cancelMessage !== '' && empty($cancelSuccess)): ?>
    <p class="text-sm at-muted"><?= Security::escape($cancelMessage) ?></p>
<?php endif; ?>
