<?php

declare(strict_types=1);

/**
 * Shared order line-items list (admin + storefront).
 *
 * @var list<array<string, mixed>> $orderItems  keys: name, label|variant, qty, unit_price, line_total, image
 */
$orderItems = $orderItems ?? [];
?>
<div class="at-oi" role="table" aria-label="Order items">
    <div class="at-oi__head" role="row">
        <span role="columnheader">Product</span>
        <span role="columnheader" class="at-oi__num">Qty</span>
        <span role="columnheader" class="at-oi__num">Unit price</span>
        <span role="columnheader" class="at-oi__num">Total</span>
    </div>
    <?php foreach ($orderItems as $oi):
        $oiName = (string) ($oi['name'] ?? '');
        $oiVariant = (string) ($oi['variant'] ?? $oi['label'] ?? '');
        $oiQty = (int) ($oi['qty'] ?? 0);
        $oiUnit = (float) ($oi['unit_price'] ?? 0);
        $oiLine = array_key_exists('line_total', $oi) ? (float) $oi['line_total'] : $oiUnit * $oiQty;
        $oiImg = (string) ($oi['image'] ?? '');
        ?>
        <div class="at-oi__row" role="row">
            <div class="at-oi__product" role="cell">
                <span class="at-oi__thumb<?= $oiImg === '' ? ' is-empty' : '' ?>">
                    <?php if ($oiImg !== ''): ?>
                        <img src="<?= Security::escape($oiImg) ?>" alt="<?= Security::escape($oiName) ?>" loading="lazy" width="64" height="64"
                             onerror="this.parentNode.classList.add('is-empty');this.remove();">
                    <?php endif; ?>
                    <?= at_icon('basket', 'at-oi__ph') ?>
                </span>
                <span class="at-oi__info">
                    <strong class="at-oi__name"><?= Security::escape($oiName) ?></strong>
                    <?php if ($oiVariant !== ''): ?><span class="at-oi__variant"><?= Security::escape($oiVariant) ?></span><?php endif; ?>
                </span>
            </div>
            <div class="at-oi__num at-oi__qty" role="cell"><span class="at-oi__lbl">Qty</span><?= $oiQty ?></div>
            <div class="at-oi__num at-oi__unit" role="cell"><span class="at-oi__lbl">Unit price</span><?= Security::escape(catalogue_format_price($oiUnit)) ?></div>
            <div class="at-oi__num at-oi__total" role="cell"><span class="at-oi__lbl">Total</span><?= Security::escape(catalogue_format_price($oiLine)) ?></div>
        </div>
    <?php endforeach; ?>
</div>
