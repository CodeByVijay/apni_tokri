<?php

declare(strict_types=1);

/** @var string $trustIconFieldName */
/** @var string $trustIconValue */
$trustIconFieldName = $trustIconFieldName ?? '';
$trustIconValue = $trustIconValue ?? '';
$trustIconCanonical = HomepageTrustIcons::normalizeStoredId($trustIconValue) ?? ($trustIconValue !== '' ? $trustIconValue : 'truck');
$trustIconPreviewId = HomepageTrustIcons::resolveForRender($trustIconCanonical);
$trustIconLabel = HomepageTrustIcons::labelFor($trustIconPreviewId);
$trustIconInputId = 'trust-icon-' . preg_replace('/[^a-z0-9_]/', '-', $trustIconFieldName);
?>
<div class="at-trust-icon-field" data-trust-icon-field>
    <input
        type="hidden"
        name="<?= Security::escape($trustIconFieldName) ?>"
        id="<?= Security::escape($trustIconInputId) ?>"
        class="at-trust-icon-field__input"
        value="<?= Security::escape(HomepageTrustIcons::normalizeStoredId($trustIconValue) ?? $trustIconValue) ?>"
        data-trust-icon-input
    >
    <div class="at-trust-icon-field__selected" data-trust-icon-selected>
        <span class="at-trust-icon-field__preview" aria-hidden="true"><?= at_icon($trustIconPreviewId, 'w-6 h-6') ?></span>
        <span class="at-trust-icon-field__meta">
            <span class="at-trust-icon-field__label" data-trust-icon-label><?= Security::escape($trustIconLabel) ?></span>
            <code class="at-trust-icon-field__id at-muted text-xs" data-trust-icon-id><?= Security::escape($trustIconPreviewId) ?></code>
        </span>
        <button type="button" class="at-btn at-btn--ghost at-btn--sm at-focusable" data-trust-icon-toggle aria-expanded="false" aria-controls="<?= Security::escape($trustIconInputId) ?>-picker">
            Choose icon
        </button>
    </div>
    <div
        id="<?= Security::escape($trustIconInputId) ?>-picker"
        class="at-trust-icon-field__picker hidden"
        data-trust-icon-picker
        hidden
    >
        <input type="search" class="at-input at-trust-icon-field__search at-focusable" placeholder="Search icons" data-trust-icon-search autocomplete="off">
        <div class="at-trust-icon-field__grid" role="listbox" aria-label="Icon options">
            <?php foreach (HomepageTrustIcons::pickerOptions() as $opt): ?>
                <button
                    type="button"
                    role="option"
                    class="at-trust-icon-field__option at-focusable<?= $trustIconPreviewId === $opt['id'] ? ' is-selected' : '' ?>"
                    data-trust-icon-option
                    data-icon-id="<?= Security::escape($opt['id']) ?>"
                    data-icon-label="<?= Security::escape($opt['label']) ?>"
                    aria-selected="<?= $trustIconPreviewId === $opt['id'] ? 'true' : 'false' ?>"
                >
                    <span class="at-trust-icon-field__option-icon" aria-hidden="true"><?= at_icon($opt['id'], 'w-6 h-6') ?></span>
                    <span class="at-trust-icon-field__option-text">
                        <span class="at-trust-icon-field__option-label"><?= Security::escape($opt['label']) ?></span>
                        <code class="at-trust-icon-field__option-id"><?= Security::escape($opt['id']) ?></code>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>
