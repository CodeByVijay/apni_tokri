<?php

declare(strict_types=1);

/** @var array<string, mixed> $a Attribute row for admin product form */
$a = $a ?? [];
?>
<div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 items-start" data-at-attribute-row>
    <input name="attr_label[]" class="at-input at-focusable" placeholder="Label" value="<?= Security::escape((string) ($a['attr_key'] ?? '')) ?>">
    <input name="attr_value[]" class="at-input at-focusable" placeholder="Value" value="<?= Security::escape((string) ($a['attr_value'] ?? '')) ?>">
    <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable text-red-700 sm:mt-0" data-at-remove-attribute aria-label="Remove attribute">Remove</button>
</div>
