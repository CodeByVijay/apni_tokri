<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
$addr = is_array($order['address'] ?? null) ? $order['address'] : null;
$customerId = (int) ($order['customer_id'] ?? 0);
$mobile = (string) ($order['customer_mobile'] ?? '');
$tel = $mobile !== '' ? AdminOrderDetailService::telUrlForMobile($mobile) : null;
$wa = $mobile !== '' ? AdminOrderDetailService::whatsappUrlForMobile($mobile) : null;
$copyText = $addr !== null && !empty($addr['formatted']) ? (string) $addr['formatted'] : (string) ($order['address_line'] ?? '');
?>
<section class="at-card" aria-labelledby="at-admin-od-customer-title">
    <div class="at-card__body at-admin-order-detail__customer text-sm">
        <div class="at-admin-order-detail__section-head">
            <h2 id="at-admin-od-customer-title" class="at-admin-od-section-title">Customer &amp; delivery</h2>
            <div class="flex flex-wrap gap-2">
                <?php if ($tel !== null): ?>
                    <a href="<?= Security::escape($tel) ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable">Call</a>
                <?php endif; ?>
                <?php if ($wa !== null): ?>
                    <a href="<?= Security::escape($wa) ?>" target="_blank" rel="noopener noreferrer" class="at-btn at-btn--secondary at-btn--sm at-focusable">WhatsApp</a>
                <?php endif; ?>
                <?php if ($customerId > 0): ?>
                    <a href="<?= Security::escape(url_base()) ?>/admin/customers/view.php?id=<?= $customerId ?>" class="at-btn at-btn--ghost at-btn--sm at-focusable">View profile</a>
                <?php endif; ?>
                <?php if ($copyText !== ''): ?>
                    <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-at-copy="<?= Security::escape($copyText) ?>" id="at-admin-od-copy-address">Copy address</button>
                <?php endif; ?>
            </div>
        </div>
        <dl class="at-admin-od-kv-grid mt-3">
            <div><dt>Name</dt><dd><?= Security::escape(trim((string) ($order['customer_name'] ?? '')) !== '' ? (string) $order['customer_name'] : 'Guest') ?></dd></div>
            <div><dt>Customer ID</dt><dd><?php if ($customerId > 0) {
                echo (int) $customerId;
            } else { ?><span class="at-muted">—</span><?php } ?></dd></div>
            <div><dt>Mobile</dt><dd><?php if ($mobile !== '') {
                echo Security::escape($mobile);
            } else { ?><span class="at-muted">—</span><?php } ?></dd></div>
            <div><dt>Email</dt><dd><?php if ((string) ($order['customer_email'] ?? '') !== '') {
                echo Security::escape((string) $order['customer_email']);
            } else { ?><span class="at-muted">—</span><?php } ?></dd></div>
        </dl>
        <?php if ($addr !== null): ?>
            <div class="mt-4 space-y-1">
                <p class="font-bold">Delivery address<?= !empty($order['address_label']) ? ' (' . Security::escape((string) $order['address_label']) . ')' : '' ?></p>
                <?php foreach (($addr['lines'] ?? []) as $line): ?>
                    <?php if (trim((string) $line) !== ''): ?><p><?= Security::escape((string) $line) ?></p><?php endif; ?>
                <?php endforeach; ?>
                <p class="at-muted"><?= Security::escape(trim((string) ($addr['city'] ?? '') . ', ' . (string) ($addr['state'] ?? '') . ' ' . (string) ($addr['pincode'] ?? ''))) ?></p>
                <?php if (!empty($addr['landmark'])): ?>
                    <p><strong>Landmark:</strong> <?= Security::escape((string) $addr['landmark']) ?></p>
                <?php endif; ?>
                <?php if (!empty($addr['delivery_instructions'])): ?>
                    <p class="mt-2 p-3 rounded-lg bg-[var(--at-surface-2)]"><strong>Instructions:</strong> <?= Security::escape((string) $addr['delivery_instructions']) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="mt-3"><strong>Address:</strong> <?= Security::escape((string) ($order['address_line'] ?? '—')) ?></p>
        <?php endif; ?>
    </div>
</section>
