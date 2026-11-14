<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$userId = AdminAuth::id();
if ($userId === null || !AdminRbacService::userHasSuperAdminRole($userId)) {
    http_response_code(403);
    admin_layout_start(['pageTitle' => 'Access denied', 'activeAdminNav' => 'settings']);
    echo '<div class="at-alert at-alert--error">OTP &amp; SMS Security is available to Super Admin only.</div>';
    admin_layout_end();
    exit;
}

$errors = [];
$settingsBase = url_base() . '/admin/settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!Install::isSchemaReady()) {
        admin_flash_set('OTP & SMS security requires an installed database.', 'error');
        header('Location: ' . $settingsBase . '/otp-sms-security.php', true, 302);
        exit;
    }

    $result = OtpSecuritySettings::saveFromRequest($_POST);
    if (!empty($result['ok'])) {
        admin_flash_set((string) ($result['message'] ?? 'Settings saved.'));
    } else {
        if (!empty($result['errors']) && is_array($result['errors'])) {
            $_SESSION['otp_security_form_errors'] = $result['errors'];
            $_SESSION['otp_security_form_post'] = $_POST;
        }
        admin_flash_set((string) ($result['message'] ?? 'Could not save settings.'), 'error');
    }
    header('Location: ' . $settingsBase . '/otp-sms-security.php', true, 302);
    exit;
}

$form = OtpSecuritySettings::adminFormState();
$posted = [];
if (!empty($_SESSION['otp_security_form_post']) && is_array($_SESSION['otp_security_form_post'])) {
    $posted = $_SESSION['otp_security_form_post'];
    unset($_SESSION['otp_security_form_post']);
    $form['mobile_limit'] = (int) ($posted['mobile_limit'] ?? $form['mobile_limit']);
    $form['ip_limit'] = (int) ($posted['ip_limit'] ?? $form['ip_limit']);
    $form['daily_mobile_limit'] = (int) ($posted['daily_mobile_limit'] ?? $form['daily_mobile_limit']);
    $form['global_hourly_limit'] = (int) ($posted['global_hourly_limit'] ?? $form['global_hourly_limit']);
    $form['resend_cooldown_preset'] = (string) ($posted['resend_cooldown_preset'] ?? $form['resend_cooldown_preset']);
    $form['resend_cooldown_seconds'] = (int) ($posted['resend_cooldown_seconds'] ?? $form['resend_cooldown_seconds']);
}
if (!empty($_SESSION['otp_security_form_errors']) && is_array($_SESSION['otp_security_form_errors'])) {
    $errors = $_SESSION['otp_security_form_errors'];
    unset($_SESSION['otp_security_form_errors']);
}

admin_layout_start(['pageTitle' => 'OTP & SMS Security', 'activeAdminNav' => 'settings']);
$pageHeading = 'OTP & SMS Security';
$pageDescription = 'OTP abuse protection and SMS spend limits. All limits apply to successful SMS sends only — failed provider deliveries never consume quota.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'otp_security';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';

$bounds = $form['bounds'];
$numField = static function (string $id, string $label, int $value, array $bound, string $hint, array $errors): void {
    ?>
    <div>
        <label class="at-label" for="<?= Security::escape($id) ?>"><?= Security::escape($label) ?></label>
        <input id="<?= Security::escape($id) ?>" name="<?= Security::escape($id) ?>" type="number" class="at-input at-focusable"
               min="<?= (int) $bound['min'] ?>" max="<?= (int) $bound['max'] ?>" step="1" required
               value="<?= (int) $value ?>">
        <p class="text-xs at-muted mt-1"><?= Security::escape($hint) ?></p>
        <?php if (isset($errors[$id])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors[$id]) ?></p><?php endif; ?>
    </div>
    <?php
};
?>

<div class="at-alert max-w-3xl mb-4" role="status">
    <strong>Security controls.</strong> Changes take effect immediately for new OTP requests. The resend cooldown and all quota checks are enforced server-side from the database — clearing cookies or starting a new session does not reset them.
</div>

<form method="post" class="at-card max-w-3xl" id="otp-security-form">
    <div class="at-card__body space-y-5">
        <?= Csrf::field() ?>

        <h3 class="font-bold text-sm">Request limits</h3>
        <?php
        $numField(
            'mobile_limit',
            'OTP requests per mobile (rolling 15 minutes)',
            (int) $form['mobile_limit'],
            $bounds['mobile_limit'],
            'Successful sends per normalized mobile number in a 15-minute window. Range ' . $bounds['mobile_limit']['min'] . '–' . $bounds['mobile_limit']['max'] . '.',
            $errors
        );
        $numField(
            'ip_limit',
            'OTP requests per IP address (rolling 1 hour)',
            (int) $form['ip_limit'],
            $bounds['ip_limit'],
            'Successful sends from one client IP in a 1-hour window. Range ' . $bounds['ip_limit']['min'] . '–' . $bounds['ip_limit']['max'] . '.',
            $errors
        );
        ?>

        <hr>
        <h3 class="font-bold text-sm">SMS spend caps</h3>
        <?php
        $numField(
            'daily_mobile_limit',
            'Successful SMS per mobile per 24 hours',
            (int) $form['daily_mobile_limit'],
            $bounds['daily_mobile_limit'],
            'Anti-bombing cap per mobile across all sessions and IPs. Range ' . $bounds['daily_mobile_limit']['min'] . '–' . $bounds['daily_mobile_limit']['max'] . '.',
            $errors
        );
        $numField(
            'global_hourly_limit',
            'Global SMS per hour (all customers)',
            (int) $form['global_hourly_limit'],
            $bounds['global_hourly_limit'],
            'Total OTP SMS sends allowed per rolling hour. Range ' . $bounds['global_hourly_limit']['min'] . '–' . $bounds['global_hourly_limit']['max'] . '.',
            $errors
        );
        ?>
        <div class="at-alert at-alert--warning" role="note">
            <strong>Disabling the global ceiling.</strong> Entering <code>0</code> removes the last-resort spend cap — a targeted attack could then send OTP SMS up to the per-mobile and per-IP limits without any global bound. Normal OTP traffic is unaffected while the value stays above 0.
        </div>
        <label class="flex gap-2 text-sm items-start" id="global-disable-confirm-wrap">
            <input type="checkbox" name="global_limit_disable_confirm" value="1" class="mt-1"<?= !empty($posted['global_limit_disable_confirm']) ? ' checked' : '' ?>>
            <span><strong>I understand the risk</strong><br><span class="at-muted">Required only when setting the global limit to 0 (disabled).</span></span>
        </label>

        <hr>
        <h3 class="font-bold text-sm">Resend cooldown</h3>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="at-label" for="resend_cooldown_preset">Wait before a customer can request another OTP</label>
                <select id="resend_cooldown_preset" name="resend_cooldown_preset" class="at-input at-focusable">
                    <?php foreach ($form['cooldown_presets'] as $preset): ?>
                        <option value="<?= (int) $preset ?>" <?= (string) $preset === $form['resend_cooldown_preset'] ? 'selected' : '' ?>><?= (int) ($preset / 60) ?> minute<?= $preset === 60 ? '' : 's' ?></option>
                    <?php endforeach; ?>
                    <option value="custom" <?= $form['resend_cooldown_preset'] === 'custom' ? 'selected' : '' ?>>Custom duration</option>
                </select>
                <?php if (isset($errors['resend_cooldown_preset'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['resend_cooldown_preset']) ?></p><?php endif; ?>
            </div>
            <div id="cooldown-custom-wrap" class="<?= $form['resend_cooldown_preset'] === 'custom' ? '' : 'hidden' ?>">
                <label class="at-label" for="resend_cooldown_seconds">Custom cooldown (seconds)</label>
                <input id="resend_cooldown_seconds" name="resend_cooldown_seconds" type="number" class="at-input at-focusable"
                       min="<?= (int) $bounds['resend_cooldown_seconds']['min'] ?>" max="<?= (int) $bounds['resend_cooldown_seconds']['max'] ?>" step="1"
                       value="<?= (int) $form['resend_cooldown_seconds'] ?>">
                <p class="text-xs at-muted mt-1">Range <?= (int) $bounds['resend_cooldown_seconds']['min'] ?>–<?= (int) $bounds['resend_cooldown_seconds']['max'] ?> seconds (default 120).</p>
                <?php if (isset($errors['resend_cooldown_seconds'])): ?><p class="text-xs text-red-600 mt-1"><?= Security::escape($errors['resend_cooldown_seconds']) ?></p><?php endif; ?>
            </div>
        </div>
        <p class="text-xs at-muted">The countdown shown to customers follows this value, but the database-backed cooldown on the server is authoritative — the frontend timer is a visual hint only. Changing the cooldown never resets the request or SMS limits above.</p>

        <button type="submit" class="at-btn at-btn--primary at-focusable">Save Changes</button>
    </div>
</form>

<script <?= Security::cspNonceAttr() ?>>
(function () {
  var preset = document.getElementById('resend_cooldown_preset');
  var wrap = document.getElementById('cooldown-custom-wrap');
  var globalLimit = document.getElementById('global_hourly_limit');
  var confirmWrap = document.getElementById('global-disable-confirm-wrap');
  var confirmBox = confirmWrap ? confirmWrap.querySelector('input[type="checkbox"]') : null;
  function syncCooldown() {
    if (wrap && preset) wrap.classList.toggle('hidden', preset.value !== 'custom');
  }
  function syncGlobalConfirm() {
    if (!globalLimit || !confirmWrap) return;
    var disabling = parseInt(globalLimit.value, 10) === 0;
    confirmWrap.classList.toggle('hidden', !disabling);
    if (confirmBox) confirmBox.required = disabling;
  }
  if (preset) preset.addEventListener('change', syncCooldown);
  if (globalLimit) globalLimit.addEventListener('input', syncGlobalConfirm);
  syncCooldown();
  syncGlobalConfirm();
})();
</script>
<?php admin_layout_end(); ?>
