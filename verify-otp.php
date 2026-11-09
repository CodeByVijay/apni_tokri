<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

$mobile = $_SESSION['otp_pending_mobile'] ?? '';
if (!is_string($mobile) || $mobile === '') {
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$accountInactive = Install::isSchemaReady() && CustomerAuth::isExistingInactiveCustomer($mobile);
if ($accountInactive) {
    unset($_SESSION['otp_pending_mobile'], $_SESSION['otp_debug_plain'], $_SESSION['otp_demo_code']);
}

$otpUi = Install::isSchemaReady() && !$accountInactive
    ? CustomerOtpRegistration::uiContextForMobile($mobile)
    : [
        'mode' => CustomerOtpRegistration::MODE_NEW,
        'name' => '',
        'email' => '',
        'show_consent' => true,
        'customer_id' => null,
    ];

$termsUrl = CmsPageService::publicPageUrl('terms');
$privacyUrl = CmsPageService::publicPageUrl('privacy-policy');

$error = '';
$fieldErrors = [];
$resendNotice = '';
$verifyPageTestOtp = $accountInactive ? null : OtpSmsService::fixedTestOtpForVerifyPageDisplay();
$resendRemaining = Install::isSchemaReady() && !$accountInactive ? OtpResendCooldown::remainingSeconds($mobile) : 0;
$resendUntil = $resendRemaining > 0 ? time() + $resendRemaining : 0;
if (AppEnvironment::allowsDebugOtpDisplay() && !empty($_SESSION['otp_debug_plain'])) {
    unset($_SESSION['otp_debug_plain']);
}

$postName = '';
$postEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($accountInactive) {
        // Blocked account — ignore OTP resend and verification attempts.
    } elseif (!empty($_POST['resend_otp']) && Install::isSchemaReady()) {
        try {
            CustomerAuth::issueOtp($mobile);
            $resendNotice = 'A new OTP has been sent.';
            $resendRemaining = OtpResendCooldown::remainingSeconds($mobile);
            $resendUntil = $resendRemaining > 0 ? time() + $resendRemaining : 0;
        } catch (OtpRequestException $e) {
            if ($e->errorCode() === OtpSmsService::ERR_ACCOUNT_INACTIVE) {
                $accountInactive = true;
                unset($_SESSION['otp_pending_mobile'], $_SESSION['otp_debug_plain'], $_SESSION['otp_demo_code']);
            }
            $error = $e->getMessage();
            $resendRemaining = $e->errorCode() === OtpSmsService::ERR_RESEND_COOLDOWN
                ? $e->remainingSeconds()
                : OtpResendCooldown::remainingSeconds($mobile);
            $resendUntil = $resendRemaining > 0 ? time() + $resendRemaining : 0;
        } catch (Throwable $e) {
            error_log('verify-otp resend: ' . get_class($e));
            $error = OtpSmsService::publicErrorMessage(OtpSmsService::ERR_SERVER_ERROR);
        }
    }
    $otp = isset($_POST['otp']) && is_string($_POST['otp']) ? preg_replace('/\D+/', '', $_POST['otp']) : '';
    if ($accountInactive) {
        // No OTP processing for deactivated accounts.
    } elseif (!empty($_POST['resend_otp'])) {
        // Resend-only submit; skip OTP verification on this request.
    } elseif (strlen($otp) !== 6) {
        $error = 'Enter the 6-digit OTP.';
    } elseif (Install::isSchemaReady()) {
        $otpUi = CustomerOtpRegistration::uiContextForMobile($mobile);
        if (CustomerAuth::isExistingInactiveCustomer($mobile)) {
            $accountInactive = true;
            unset($_SESSION['otp_pending_mobile'], $_SESSION['otp_debug_plain'], $_SESSION['otp_demo_code']);
        } else {
            $registration = [];
            if ($otpUi['mode'] !== CustomerOtpRegistration::MODE_RETURNING) {
                $postName = isset($_POST['name']) && is_string($_POST['name']) ? $_POST['name'] : '';
                $postEmail = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
                $registration['name'] = $postName;
                $registration['email'] = $postEmail;
                if ($otpUi['show_consent']) {
                    $registration['consent'] = !empty($_POST['consent']) ? '1' : '';
                }
            }

            $result = CustomerAuth::verifyOtpWithRegistration($mobile, $otp, $registration);
            if (!empty($result['ok'])) {
                unset($_SESSION['otp_pending_mobile']);
                OtpResendCooldown::clear();
                $return = $_SESSION['checkout_return'] ?? url_base() . '/my-account.php';
                unset($_SESSION['checkout_return']);
                header('Location: ' . $return, true, 302);
                exit;
            }
            if (CustomerAuth::isExistingInactiveCustomer($mobile)) {
                $accountInactive = true;
                unset($_SESSION['otp_pending_mobile'], $_SESSION['otp_debug_plain'], $_SESSION['otp_demo_code']);
            } elseif (!empty($result['field_errors']) && is_array($result['field_errors'])) {
                $fieldErrors = $result['field_errors'];
                $error = 'Please correct the highlighted fields.';
            } else {
                $error = (string) ($result['error'] ?? 'Incorrect or expired OTP.');
            }
        }
    } elseif (!AppEnvironment::isProduction() && Security::hashEquals((string) ($_SESSION['otp_demo_code'] ?? '123456'), $otp)) {
        $demoName = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
        CustomerService::loginDemoSession($mobile, $demoName);
        unset($_SESSION['otp_pending_mobile'], $_SESSION['otp_demo_code']);
        $return = $_SESSION['checkout_return'] ?? url_base() . '/my-account.php';
        unset($_SESSION['checkout_return']);
        header('Location: ' . $return, true, 302);
        exit;
    } else {
        $error = AppEnvironment::isProduction()
            ? 'Incorrect or expired OTP.'
            : 'Incorrect OTP. Demo code: 123456';
    }
}

$displayName = $postName !== '' ? $postName : (string) ($otpUi['name'] ?? '');
$displayEmail = $postEmail !== '' ? $postEmail : (string) ($otpUi['email'] ?? '');
$isReturning = ($otpUi['mode'] ?? '') === CustomerOtpRegistration::MODE_RETURNING;
$isLegacy = ($otpUi['mode'] ?? '') === CustomerOtpRegistration::MODE_LEGACY_PROFILE;
$showConsent = !empty($otpUi['show_consent']);
$fieldsEditable = !$isReturning;

layout_public_start([
    'pageTitle' => 'Verify OTP — Veggie Tokri',
    'activeNav' => 'account',
    'showBottomNav' => false,
]);
?>
<div class="at-container py-10 md:py-16">
    <div class="grid md:grid-cols-2 gap-8 lg:gap-16 items-center max-w-4xl mx-auto">
        <div class="hidden md:block rounded-[var(--at-radius-lg)] overflow-hidden aspect-[4/5] relative">
            <img src="<?= Security::escape(asset_url('assets/images/demo/p-tomato-2.jpg')) ?>" alt="" class="w-full h-full object-cover">
            <div class="absolute inset-x-0 bottom-0 p-6 text-white" style="background:linear-gradient(180deg,transparent,rgba(19,52,33,.9))">
                <p class="at-display !text-white text-2xl">Freshness, Ready for Your Kitchen.</p>
                <p class="text-sm opacity-90 mt-1">One last step — enter the code we sent to your phone.</p>
            </div>
        </div>
        <div>
            <?php if ($accountInactive): ?>
            <h1 class="at-h1"><?= Security::escape(OtpSmsService::inactiveAccountHeading()) ?></h1>
            <div class="at-card mt-6">
                <div class="at-card__body grid gap-4 text-sm">
                    <p><?= Security::escape(OtpSmsService::inactiveAccountCustomerMessage()) ?></p>
                    <a href="<?= Security::escape(url_base()) ?>/login.php" class="at-btn at-btn--primary at-btn--lg at-btn--block at-focusable">Back to login</a>
                </div>
            </div>
            <?php else: ?>
            <h1 class="at-h1">Verify your number</h1>
            <p class="mt-2 at-muted">Code sent to <strong class="text-[var(--at-ink)]">+91 <?= Security::escape($mobile) ?></strong></p>
            <?php if ($verifyPageTestOtp !== null): ?>
                <div class="at-otp-test-hint mt-3" role="note">
                    <p class="at-otp-test-hint__code">Test OTP: <?= Security::escape($verifyPageTestOtp) ?></p>
                    <p class="at-otp-test-hint__sub">Development testing only</p>
                </div>
            <?php elseif (Install::isSchemaReady() && OtpSmsService::operationMode() === OtpSmsService::MODE_TEST): ?>
                <p class="mt-2"><span class="at-badge at-badge--neutral">Test mode — no real SMS sent</span></p>
            <?php endif; ?>
            <?php if ($isLegacy): ?>
                <p class="mt-2 text-sm at-muted">Please add your name and email to finish setting up your account.</p>
            <?php endif; ?>
            <?php if ($resendNotice !== ''): ?>
                <div class="at-alert mt-4" role="status"><?= Security::escape($resendNotice) ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="at-alert at-alert--error mt-4" role="alert"><?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($error) ?></span></div>
            <?php endif; ?>
            <form method="post" class="at-card mt-6" id="otp-form" data-otp-reg-mode="<?= Security::escape((string) ($otpUi['mode'] ?? 'new')) ?>">
                <div class="at-card__body grid gap-4">
                    <?= Csrf::field() ?>
                    <div>
                        <label class="at-label" id="otp-label" for="otp-d1">6-digit OTP</label>
                        <input id="otp" name="otp" type="hidden" value="">
                        <div class="at-otp" role="group" aria-labelledby="otp-label">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <input id="otp-d<?= $i ?>" data-otp-box type="text" class="at-input at-focusable at-otp__box"
                                    inputmode="numeric" pattern="[0-9]*" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>"
                                    aria-label="Digit <?= $i ?> of 6" aria-describedby="otp-hint" enterkeyhint="<?= $i === 6 ? 'done' : 'next' ?>"
                                    autocapitalize="off" spellcheck="false">
                            <?php endfor; ?>
                        </div>
                        <p id="otp-hint" class="at-field-error hidden" role="alert">Enter the 6-digit OTP.</p>
                    </div>
                    <div>
                        <label class="at-label" for="reg-name">Full name<?php if ($fieldsEditable): ?> <span class="at-muted font-normal">(required)</span><?php endif; ?></label>
                        <?php if ($fieldsEditable): ?>
                            <input id="reg-name" name="name" type="text" class="at-input at-focusable<?= isset($fieldErrors['name']) ? ' at-input--error' : '' ?>"
                                autocomplete="name" maxlength="120" required
                                value="<?= Security::escape($displayName) ?>"
                                aria-invalid="<?= isset($fieldErrors['name']) ? 'true' : 'false' ?>"
                                aria-describedby="<?= isset($fieldErrors['name']) ? 'reg-name-error' : '' ?>">
                            <?php if (isset($fieldErrors['name'])): ?>
                                <p id="reg-name-error" class="at-field-error" role="alert"><?= Security::escape($fieldErrors['name']) ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <input id="reg-name" type="text" class="at-input at-focusable" value="<?= Security::escape($displayName) ?>" readonly disabled aria-readonly="true">
                            <p class="text-xs at-muted mt-1">You can update your profile from My account after signing in.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="at-label" for="reg-email">Email address<?php if ($fieldsEditable): ?> <span class="at-muted font-normal">(required)</span><?php endif; ?></label>
                        <?php if ($fieldsEditable): ?>
                            <input id="reg-email" name="email" type="email" inputmode="email" class="at-input at-focusable<?= isset($fieldErrors['email']) ? ' at-input--error' : '' ?>"
                                autocomplete="email" maxlength="191" required
                                value="<?= Security::escape($displayEmail) ?>"
                                aria-invalid="<?= isset($fieldErrors['email']) ? 'true' : 'false' ?>"
                                aria-describedby="<?= isset($fieldErrors['email']) ? 'reg-email-error' : '' ?>">
                            <?php if (isset($fieldErrors['email'])): ?>
                                <p id="reg-email-error" class="at-field-error" role="alert"><?= Security::escape($fieldErrors['email']) ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <input id="reg-email" type="email" class="at-input at-focusable" value="<?= Security::escape($displayEmail) ?>" readonly disabled aria-readonly="true">
                        <?php endif; ?>
                    </div>
                    <?php if ($showConsent): ?>
                        <label class="flex items-start gap-3 text-sm<?= isset($fieldErrors['consent']) ? ' text-[var(--at-error)]' : '' ?>">
                            <input type="checkbox" name="consent" value="1" class="mt-0.5" id="reg-consent" data-reg-consent
                                <?= !empty($_POST['consent']) ? 'checked' : '' ?>>
                            <span>I agree to the
                                <?php if ($termsUrl !== null): ?><a class="underline font-semibold" href="<?= Security::escape($termsUrl) ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a><?php else: ?>Terms &amp; Conditions<?php endif; ?>
                                and
                                <?php if ($privacyUrl !== null): ?><a class="underline font-semibold" href="<?= Security::escape($privacyUrl) ?>" target="_blank" rel="noopener">Privacy Policy</a><?php else: ?>Privacy Policy<?php endif; ?>.</span>
                        </label>
                        <?php if (isset($fieldErrors['consent'])): ?>
                            <p class="at-field-error" role="alert"><?= Security::escape($fieldErrors['consent']) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <button type="submit" class="at-btn at-btn--primary at-btn--lg at-btn--block at-focusable" id="otp-submit" data-otp-submit>Verify &amp; continue</button>
                    <p class="text-xs at-muted flex gap-2"><?= at_icon('lock', 'w-4 h-4 flex-shrink-0') ?>The code expires in 5 minutes.</p>
                    <?php if (Install::isSchemaReady()): ?>
                        <div
                            id="otp-resend-wrap"
                            class="flex flex-col items-center gap-2"
                            data-resend-until="<?= (int) $resendUntil ?>"
                            data-resend-url="<?= Security::escape(url_base()) ?>/ajax/resend-otp.php"
                            data-csrf-token="<?= Security::escape(Csrf::token()) ?>"
                        >
                            <button
                                type="button"
                                class="at-btn at-btn--ghost at-btn--sm at-focusable"
                                data-otp-resend
                                <?= $resendRemaining > 0 ? 'disabled aria-disabled="true"' : '' ?>
                            ><?= $resendRemaining > 0
                                ? Security::escape(OtpResendCooldown::formatResendLabel($resendRemaining))
                                : 'Resend OTP' ?></button>
                            <p class="text-xs at-muted hidden" data-otp-resend-status role="status" aria-live="polite"></p>
                            <noscript>
                                <button type="submit" name="resend_otp" value="1" class="at-btn at-btn--ghost at-btn--sm" <?= $resendRemaining > 0 ? 'disabled' : '' ?>>Resend OTP</button>
                            </noscript>
                        </div>
                    <?php endif; ?>
                    <a href="<?= Security::escape(url_base()) ?>/login.php" class="at-btn at-btn--ghost at-btn--sm at-focusable self-center">Use a different number</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if (!$accountInactive): ?>
<script src="<?= Security::escape(asset_url('assets/js/otp-input.js')) ?>"></script>
<script src="<?= Security::escape(asset_url('assets/js/otp-resend.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.AtOtpResend) {
    window.AtOtpResend.init(document.getElementById('otp-resend-wrap'));
  }
  var form = document.getElementById('otp-form');
  var submit = document.getElementById('otp-submit');
  if (!form || !submit) {
    return;
  }
  var mode = form.getAttribute('data-otp-reg-mode') || 'new';
  var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  function syncSubmit() {
    if (mode === 'returning') {
      submit.disabled = false;
      return;
    }
    var name = form.querySelector('[name="name"]');
    var email = form.querySelector('[name="email"]');
    var consent = form.querySelector('[data-reg-consent]');
    var nameOk = name && name.value.trim().length >= 2;
    var emailOk = email && emailRe.test(email.value.trim());
    var consentOk = !consent || consent.checked;
    submit.disabled = !(nameOk && emailOk && consentOk);
  }
  form.addEventListener('input', syncSubmit);
  form.addEventListener('change', syncSubmit);
  syncSubmit();
});
</script>
<?php endif; ?>
<?php layout_public_end(); ?>
