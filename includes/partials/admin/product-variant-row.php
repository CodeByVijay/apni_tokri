<?php

declare(strict_types=1);

/** @var array<string, mixed> $v Variant row for admin product form */
$v = $v ?? [];
?>
<tr data-at-variant-row>
    <td>
        <input type="hidden" name="variant_id[]" value="<?= Security::escape((string) ($v['id'] ?? '')) ?>">
        <input name="variant_name[]" class="at-input at-focusable w-full min-w-[5rem]" value="<?= Security::escape((string) ($v['name'] ?? '')) ?>" aria-label="Variant name" placeholder="e.g. 500 g">
    </td>
    <td><input name="variant_sku[]" class="at-input at-focusable" value="<?= Security::escape((string) ($v['sku'] ?? '')) ?>" placeholder="Optional"></td>
    <td><input name="variant_price[]" type="number" step="0.01" min="0" class="at-input at-focusable" value="<?= Security::escape((string) ($v['price'] ?? '')) ?>" inputmode="decimal"></td>
    <td><input name="variant_sale[]" type="number" step="0.01" min="0" class="at-input at-focusable" value="<?= isset($v['sale_price']) && $v['sale_price'] !== null && (string) $v['sale_price'] !== '' ? Security::escape((string) $v['sale_price']) : '' ?>" placeholder="—"></td>
    <td><input name="variant_stock[]" type="number" min="0" class="at-input at-focusable" value="<?= (int) ($v['stock_qty'] ?? 0) ?>"></td>
    <td><input name="variant_low[]" type="number" min="0" class="at-input at-focusable" value="<?= (int) ($v['low_stock_threshold'] ?? 5) ?>"></td>
    <td class="text-right whitespace-nowrap">
        <button type="button" class="at-btn at-btn--ghost at-btn--xs at-focusable text-red-700" data-at-remove-variant aria-label="Remove variant">Remove</button>
    </td>
</tr>
