<?php

declare(strict_types=1);

/** @var array<string, mixed> $a Attribute row for admin product form */
/** @var int|null $attrIndex 0-based index for position display */
$a = $a ?? [];
$attrIndex = $attrIndex ?? 0;
$label = (string) ($a['attr_key'] ?? '');
?>
<tr data-at-attribute-row data-at-product-reorder-row>
    <td class="at-dslots-reorder-col-handle">
        <button type="button" class="at-dslots-reorder-handle at-focusable" draggable="true" aria-label="Drag to reorder attribute" data-at-product-reorder-drag-handle>⋮⋮</button>
    </td>
    <td class="is-num at-dslots-reorder-col-seq" data-at-product-reorder-seq><?= $attrIndex + 1 ?></td>
    <td>
        <input name="attr_label[]" class="at-input at-focusable w-full" placeholder="Label" value="<?= Security::escape($label) ?>" aria-label="Attribute label">
    </td>
    <td>
        <input name="attr_value[]" class="at-input at-focusable w-full" placeholder="Value" value="<?= Security::escape((string) ($a['attr_value'] ?? '')) ?>" aria-label="Attribute value">
    </td>
    <td class="at-dslots-reorder-col-move">
        <span class="at-dslots-reorder-arrows">
            <button type="button" class="at-btn at-btn--sm at-btn--ghost at-focusable" data-at-product-reorder-up aria-label="Move attribute up">↑</button>
            <button type="button" class="at-btn at-btn--sm at-btn--ghost at-focusable" data-at-product-reorder-down aria-label="Move attribute down">↓</button>
        </span>
    </td>
    <td class="text-right whitespace-nowrap">
        <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable text-red-700" data-at-remove-attribute aria-label="Remove attribute">Remove</button>
    </td>
</tr>
