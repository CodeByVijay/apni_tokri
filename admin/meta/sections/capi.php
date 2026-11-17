<?php

declare(strict_types=1);

$actionUrl = MetaAdsSettings::sectionUrl('capi');
$tokenState = (string) ($metaForm['token_state'] ?? MetaAdsSettings::TOKEN_STATE_NOT_SET);
?>
<div class="grid gap-4 lg:grid-cols-2 max-w-5xl">
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-3">Conversions API</h3>
            <?php if ($tokenState === MetaAdsSettings::TOKEN_STATE_UNREADABLE): ?>
                <div class="at-alert at-alert--warning mb-3" role="alert">
                    An access token is stored but could not be decrypted — the encryption key may be
                    missing or rotated. Re-enter the token or remove it below.
                </div>
            <?php endif; ?>
            <form method="POST" action="<?= Security::escape($actionUrl) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="meta_action" value="save_capi">
                <input type="hidden" name="section" value="capi">
                <label class="flex items-center gap-2 text-sm mb-3">
                    <input type="checkbox" name="capi_enabled" value="1" <?= !empty($metaForm['capi_enabled']) ? 'checked' : '' ?>>
                    <span><strong>Enable Conversions API</strong><br>
                        <small class="text-slate-500">Stores the flag only — no events are sent to Meta yet (Phase 3).</small></span>
                </label>
                <div class="at-field">
                    <label class="at-label" for="meta-dataset-id">Dataset ID</label>
                    <input class="at-input" id="meta-dataset-id" type="text" name="dataset_id" inputmode="numeric"
                           value="<?= Security::escape((string) $metaForm['dataset_id']) ?>"
                           placeholder="Defaults to the Meta Pixel ID" maxlength="25">
                    <?php if (!empty($metaErrors['dataset_id'])): ?>
                        <p class="at-field-error"><?= Security::escape($metaErrors['dataset_id']) ?></p>
                    <?php endif; ?>
                    <small class="text-slate-500 mt-1 block">
                        Optional — Meta datasets share the Pixel ID, so leave empty to reuse it.
                    </small>
                </div>
                <div class="at-field">
                    <?php
                    $credId = 'meta-capi-access-token';
                    $credName = 'capi_access_token';
                    $credLabel = 'Conversions API access token';
                    $credSaved = $tokenState === MetaAdsSettings::TOKEN_STATE_CONFIGURED;
                    require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
                    ?>
                    <?php if (!empty($metaErrors['capi_access_token'])): ?>
                        <p class="at-field-error"><?= Security::escape($metaErrors['capi_access_token']) ?></p>
                    <?php endif; ?>
                    <small class="text-slate-500 mt-1 block">
                        Stored encrypted. Leave empty to keep the saved token — it is never displayed here.
                        Generate it in Meta Events Manager → dataset → Settings → “Generate access token”.
                    </small>
                </div>
                <div class="at-field">
                    <label class="at-label" for="meta-test-event-code">Test event code</label>
                    <input class="at-input" id="meta-test-event-code" type="text" name="test_event_code"
                           value="<?= Security::escape((string) $metaForm['test_event_code']) ?>"
                           placeholder="e.g. TEST12345" maxlength="32">
                    <?php if (!empty($metaErrors['test_event_code'])): ?>
                        <p class="at-field-error"><?= Security::escape($metaErrors['test_event_code']) ?></p>
                    <?php endif; ?>
                    <small class="text-slate-500 mt-1 block">
                        Optional — used by Meta Events Manager “Test events” during Phase 3 verification.
                    </small>
                </div>
                <div class="mt-4">
                    <button type="submit" class="at-btn at-btn--primary" <?= $useDb ? '' : 'disabled' ?>>Save Conversions API settings</button>
                </div>
            </form>
            <?php if ($tokenState !== MetaAdsSettings::TOKEN_STATE_NOT_SET): ?>
                <hr class="my-4 border-slate-100">
                <form method="POST" action="<?= Security::escape($actionUrl) ?>"
                      onsubmit="return confirm('Remove the stored Conversions API access token? This also disables the Conversions API.');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="meta_action" value="clear_token">
                    <input type="hidden" name="section" value="capi">
                    <input type="hidden" name="confirm_clear" value="1">
                    <button type="submit" class="at-btn at-btn--secondary" <?= $useDb ? '' : 'disabled' ?>>Remove stored access token</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="at-card">
        <div class="at-card__body">
            <h3 class="font-bold mb-2">Notes</h3>
            <ul class="list-disc pl-5 text-sm text-slate-600 space-y-1">
                <li>Enabling CAPI requires a dataset (explicit or the Pixel ID) and a saved access token.</li>
                <li>No outbound requests are made to Meta in Phase 1 — settings are stored only.</li>
                <li>The token is encrypted with the site secret store; removing it requires confirmation and also disables CAPI.</li>
            </ul>
        </div>
    </div>
</div>
