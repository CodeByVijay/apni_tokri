<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
$assisted = $order['assisted'] ?? null;
if (!is_array($assisted) || $assisted === []) {
    return;
}
$meta = is_array($assisted['admin_meta'] ?? null) ? $assisted['admin_meta'] : [];
$delivery = is_array($meta['delivery'] ?? null) ? $meta['delivery'] : [];
$custom = is_array($meta['custom_discount'] ?? null) ? $meta['custom_discount'] : [];
?>
<section class="at-card mt-4" aria-labelledby="at-admin-od-assisted-title">
    <div class="at-card__body">
        <h2 id="at-admin-od-assisted-title" class="at-admin-od-section-title">Admin-assisted order</h2>
        <dl class="at-admin-od-kv-grid text-sm">
            <div><dt>Created by</dt><dd><?= Security::escape((string) ($assisted['created_by_admin_name'] ?? '—')) ?></dd></div>
            <?php if (!empty($delivery['mode']) && $delivery['mode'] !== 'calculated'): ?>
                <div><dt>Delivery override</dt><dd><?= Security::escape((string) $delivery['mode']) ?>
                    <?php if (!empty($delivery['free_delivery_reason'])): ?>
                        · <?= Security::escape((string) $delivery['free_delivery_reason']) ?>
                    <?php endif; ?>
                </dd></div>
            <?php endif; ?>
            <?php if (!empty($custom['enabled'])): ?>
                <div><dt>Custom discount</dt><dd>
                    <?= Security::escape(catalogue_format_price((float) ($custom['amount'] ?? 0))) ?>
                    <?php if (!empty($custom['reason'])): ?>
                        · <?= Security::escape((string) $custom['reason']) ?>
                    <?php endif; ?>
                </dd></div>
            <?php endif; ?>
            <div class="at-admin-od-kv-grid--wide"><dt>Customer payment link</dt><dd>
                <a class="underline break-all" href="<?= Security::escape((string) ($assisted['customer_payment_url'] ?? '')) ?>" target="_blank" rel="noopener"><?= Security::escape((string) ($assisted['customer_payment_url'] ?? '')) ?></a>
            </dd></div>
        </dl>
        <?php if (!empty($assisted['can_resend_payment_email'])): ?>
            <form method="post" class="mt-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="resend_assisted_payment_email">
                <button type="submit" class="at-btn at-btn--secondary at-btn--sm at-focusable">Resend payment email</button>
            </form>
        <?php endif; ?>
    </div>
</section>
