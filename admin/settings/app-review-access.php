<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$userId = AdminAuth::id();
if ($userId === null || !AdminRbacService::userHasSuperAdminRole($userId)) {
    http_response_code(403);
    admin_layout_start(['pageTitle' => 'Access denied', 'activeAdminNav' => 'settings']);
    echo '<div class="at-alert at-alert--error">App Review Access is available to Super Admin only.</div>';
    admin_layout_end();
    exit;
}

$errors = [];
$settingsBase = url_base() . '/admin/settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!Install::isSchemaReady()) {
        admin_flash_set('App Review Access requires an installed database.', 'error');
        header('Location: ' . $settingsBase . '/app-review-access.php', true, 302);
        exit;
    }

    $result = PlayReviewAccess::saveFromRequest($_POST);
    if (!empty($result['ok'])) {
        admin_flash_set((string) ($result['message'] ?? 'Settings saved.'));
    } else {
        if (!empty($result['errors']) && is_array($result['errors'])) {
            $_SESSION['play_review_form_errors'] = $result['errors'];
            $_SESSION['play_review_form_post'] = $_POST;
        }
        admin_flash_set((string) ($result['message'] ?? 'Could not save settings.'), 'error');
    }
    header('Location: ' . $settingsBase . '/app-review-access.php', true, 302);
    exit;
}

$form = PlayReviewAccess::adminFormState();
if (!empty($_SESSION['play_review_form_post']) && is_array($_SESSION['play_review_form_post'])) {
    $posted = $_SESSION['play_review_form_post'];
    unset($_SESSION['play_review_form_post']);
    $form['enabled'] = !empty($posted['play_review_enabled']);
    $form['mobile'] = trim((string) ($posted['play_review_mobile'] ?? $form['mobile']));
}
if (!empty($_SESSION['play_review_form_errors']) && is_array($_SESSION['play_review_form_errors'])) {
    $errors = $_SESSION['play_review_form_errors'];
    unset($_SESSION['play_review_form_errors']);
}

admin_layout_start(['pageTitle' => 'App Review Access', 'activeAdminNav' => 'settings']);
$pageHeading = 'App Review Access';
$pageDescription = 'Allow Google Play reviewers to sign in with a dedicated mobile number and fixed OTP — no SMS. Disabled by default. Normal customer OTP login is unchanged.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'play_review';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<div class="at-alert max-w-3xl mb-4" role="status">
    <strong>Sensitive.</strong> Only enable while the app is under store review. Create the reviewer customer in <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/admin/customers/index.php">Customers</a> before saving an enabled configuration.
</div>

<form method="post" class="at-card max-w-3xl">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <label class="flex gap-2 text-sm items-start">
            <input type="checkbox" name="play_review_enabled" value="1" class="mt-1"<?= $form['enabled'] ? ' checked' : '' ?>>
            <span><strong>Enable App Review Access</strong><br><span class="at-muted">When off, the configured number uses normal OTP + SMS like any other customer.</span></span>
        </label>
        <?php if (isset($errors['play_review_enabled'])): ?><p class="text-xs text-red-600"><?= Security::escape($errors['play_review_enabled']) ?></p><?php endif; ?>

        <div>
            <label class="at-label" for="play_review_mobile">Reviewer mobile number</label>
            <input id="play_review_mobile" name="play_review_mobile" type="tel" class="at-input at-focusable" inputmode="numeric" maxlength="14" autocomplete="off"
                   placeholder="10-digit Indian mobile" value="<?= Security::escape($form['mobile']) ?>">
            <p class="text-xs at-muted mt-1">Must match an existing active customer. <?= Security::escape($form['customer_label']) ?></p>
            <?php if (isset($errors['play_review_mobile'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['play_review_mobile']) ?></p><?php endif; ?>
        </div>

        <div>
            <label class="at-label" for="play_review_otp">Reusable review OTP</label>
            <input id="play_review_otp" name="play_review_otp" type="password" class="at-input at-focusable" inputmode="numeric" maxlength="6" autocomplete="new-password"
                   placeholder="<?= $form['has_otp'] ? '•••••• (enter new code to replace)' : '6-digit code' ?>">
            <p class="text-xs at-muted mt-1">
                Stored as a secure hash only.
                <?php if ($form['has_otp']): ?>
                    A review OTP is configured — leave blank to keep the current code.
                <?php else: ?>
                    Required when enabling review access.
                <?php endif; ?>
            </p>
            <?php if (isset($errors['play_review_otp'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['play_review_otp']) ?></p><?php endif; ?>
        </div>

        <button type="submit" class="at-btn at-btn--primary at-focusable">Save Review Access</button>
    </div>
</form>
<?php admin_layout_end(); ?>
