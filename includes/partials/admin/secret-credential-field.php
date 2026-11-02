<?php

declare(strict_types=1);

/**
 * Admin secret credential row (never outputs stored plaintext).
 *
 * @var string $credId Unique id prefix for input/toggle
 * @var string $credName POST field name
 * @var string $credLabel Field label
 * @var bool $credSaved Whether an encrypted value exists server-side
 */
$credId = $credId ?? 'cred';
$credName = $credName ?? 'secret';
$credLabel = $credLabel ?? 'Secret';
$credSaved = !empty($credSaved);
?>
<div class="at-secret-field">
    <div class="at-secret-field__head">
        <label class="at-label at-secret-field__label" for="<?= Security::escape($credId) ?>"><?= Security::escape($credLabel) ?></label>
        <?php if ($credSaved): ?>
            <span class="at-cred-badge at-cred-badge--saved">Saved</span>
        <?php else: ?>
            <span class="at-cred-badge at-cred-badge--empty">Not configured</span>
        <?php endif; ?>
    </div>
    <?php if ($credSaved): ?>
        <p class="at-secret-field__mask" aria-hidden="true">••••••••••••</p>
        <p class="at-field-hint at-secret-field__hint">Enter a new value below to replace the saved credential.</p>
    <?php endif; ?>
    <div class="at-secret-input-wrap">
        <input id="<?= Security::escape($credId) ?>" name="<?= Security::escape($credName) ?>" type="password"
               class="at-input at-focusable at-secret-input" autocomplete="new-password"
               placeholder="<?= $credSaved ? 'New value (optional)' : 'Enter secret' ?>">
        <button type="button" class="at-secret-toggle at-focusable" data-secret-toggle
                aria-label="Show value" aria-pressed="false" aria-controls="<?= Security::escape($credId) ?>">
            <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.5 6.9A17 17 0 0 0 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
        </button>
    </div>
</div>
