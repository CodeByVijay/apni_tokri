<?php

declare(strict_types=1);

/**
 * Admin slug row with live auto-generation (see assets/js/admin.js initAdminSlugSync).
 *
 * Expected vars:
 * - $slugSourceId, $slugSourceName, $slugSourceLabel, $slugSourceValue
 * - $slugTargetId, $slugTargetValue
 * - $slugManualId (hidden input id)
 * - $slugError (optional)
 * - $slugHint (optional)
 * - $slugPreviewId, $slugPreviewPrefix (optional CMS-style URL preview)
 */
$slugSourceId = (string) ($slugSourceId ?? 'name');
$slugSourceName = (string) ($slugSourceName ?? 'name');
$slugSourceLabel = (string) ($slugSourceLabel ?? 'Name');
$slugTargetId = (string) ($slugTargetId ?? 'slug');
$slugTargetValue = (string) ($slugTargetValue ?? '');
$slugManualId = (string) ($slugManualId ?? 'slug-manual');
$slugHint = (string) ($slugHint ?? 'Generated from the name as you type. Edit the slug to customize; clear it to resume auto-generation.');
$slugPreviewId = isset($slugPreviewId) ? (string) $slugPreviewId : '';
$slugSourceValue = (string) ($slugSourceValue ?? '');
$slugPreviewPrefix = isset($slugPreviewPrefix) ? (string) $slugPreviewPrefix : '';
$slugSourceMaxLength = isset($slugSourceMaxLength) ? (int) $slugSourceMaxLength : 0;
$slugSourcePlaceholder = isset($slugSourcePlaceholder) ? (string) $slugSourcePlaceholder : '';
$slugTargetPlaceholder = isset($slugTargetPlaceholder) ? (string) $slugTargetPlaceholder : '';
?>
<div>
    <label class="at-label" for="<?= Security::escape($slugSourceId) ?>"><?= Security::escape($slugSourceLabel) ?></label>
    <input id="<?= Security::escape($slugSourceId) ?>" name="<?= Security::escape($slugSourceName) ?>" class="at-input at-focusable w-full" value="<?= Security::escape($slugSourceValue) ?>" required data-at-slug-source<?= $slugSourceMaxLength > 0 ? ' maxlength="' . $slugSourceMaxLength . '"' : '' ?><?= $slugSourcePlaceholder !== '' ? ' placeholder="' . Security::escape($slugSourcePlaceholder) . '"' : '' ?>>
</div>
<input type="hidden" name="slug_manual" id="<?= Security::escape($slugManualId) ?>" value="0" data-at-slug-manual>
<div class="at-admin-slug-field" data-at-slug-group
     data-source="#<?= Security::escape($slugSourceId) ?>"
     data-target="#<?= Security::escape($slugTargetId) ?>"
     data-manual="#<?= Security::escape($slugManualId) ?>"
     <?php if ($slugPreviewId !== ''): ?>data-preview="#<?= Security::escape($slugPreviewId) ?>" data-preview-prefix="<?= Security::escape($slugPreviewPrefix) ?>"<?php endif; ?>>
    <label class="at-label" for="<?= Security::escape($slugTargetId) ?>">Slug</label>
    <?php if (!empty($slugError)): ?>
        <p class="text-sm text-red-600"><?= Security::escape((string) $slugError) ?></p>
    <?php endif; ?>
    <input id="<?= Security::escape($slugTargetId) ?>" name="slug" class="at-input at-focusable" value="<?= Security::escape($slugTargetValue) ?>" maxlength="<?= (int) ($slugTargetMaxLength ?? 191) ?>" autocomplete="off" pattern="[a-z0-9-]+" data-at-slug-target<?= $slugTargetPlaceholder !== '' ? ' placeholder="' . Security::escape($slugTargetPlaceholder) . '"' : '' ?>>
    <?php if ($slugPreviewId !== ''): ?>
        <p class="text-xs at-muted mt-1">Public URL: <span id="<?= Security::escape($slugPreviewId) ?>"><?= Security::escape($slugPreviewPrefix . ($slugTargetValue !== '' ? $slugTargetValue : '…')) ?></span></p>
    <?php else: ?>
        <p class="at-field-hint"><?= Security::escape($slugHint) ?></p>
    <?php endif; ?>
</div>
