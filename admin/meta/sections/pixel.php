<?php

declare(strict_types=1);

$actionUrl = MetaAdsSettings::sectionUrl('pixel');
?>
<div class="grid gap-4 lg:grid-cols-2 max-w-5xl">
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-3">Meta Pixel</h3>
            <form method="POST" action="<?= Security::escape($actionUrl) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="meta_action" value="save_pixel">
                <input type="hidden" name="section" value="pixel">
                <label class="flex items-center gap-2 text-sm mb-3">
                    <input type="checkbox" name="pixel_enabled" value="1" <?= !empty($metaForm['pixel_enabled']) ? 'checked' : '' ?>>
                    <span><strong>Enable Meta Pixel</strong><br>
                        <small class="text-slate-500">Stores the flag only — the pixel script is not loaded yet (Phase 2).</small></span>
                </label>
                <div class="at-field">
                    <label class="at-label" for="meta-pixel-id">Meta Pixel ID</label>
                    <input class="at-input" id="meta-pixel-id" type="text" name="pixel_id" inputmode="numeric"
                           value="<?= Security::escape((string) $metaForm['pixel_id']) ?>"
                           placeholder="e.g. 123456789012345" maxlength="25">
                    <?php if (!empty($metaErrors['pixel_id'])): ?>
                        <p class="at-field-error"><?= Security::escape($metaErrors['pixel_id']) ?></p>
                    <?php endif; ?>
                    <small class="text-slate-500 mt-1 block">
                        Found in Meta Events Manager → Data sources → select your dataset → Settings → “Dataset ID”.
                        Numbers only.
                    </small>
                </div>
                <div class="mt-4">
                    <button type="submit" class="at-btn at-btn--primary" <?= $useDb ? '' : 'disabled' ?>>Save pixel settings</button>
                </div>
            </form>
        </div>
    </div>
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-2">Notes</h3>
            <ul class="list-disc pl-5 text-sm text-slate-600 space-y-1">
                <li>Enabling the pixel here does <strong>not</strong> add any script to the storefront in Phase 1.</li>
                <li>The same numeric ID is normally reused as the Conversions API dataset ID.</li>
                <li>Phase 2 will inject the base pixel snippet and standard events using this ID.</li>
            </ul>
        </div>
    </div>
</div>
