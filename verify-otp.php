<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

$mobile = $_SESSION['otp_pending_mobile'] ?? '';
if (!is_string($mobile) || $mobile === '') {
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$savedName = '';
if (Install::isSchemaReady()) {
    $savedName = CustomerAuth::savedNameForMobile($mobile);
}

$error = '';
if (AppEnvironment::allowsDebugOtpDisplay() && !empty($_SESSION['otp_debug_plain'])) {
    unset($_SESSION['otp_debug_plain']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $otp = isset($_POST['otp']) && is_string($_POST['otp']) ? preg_replace('/\D+/', '', $_POST['otp']) : '';
    $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
    if ($savedName !== '') {
        // Existing customer: the server-side name is authoritative; ignore any submitted value.
        $name = '';
    }
    if (strlen($otp) !== 6) {
        $error = 'Enter the 6-digit OTP.';
    } elseif (Install::isSchemaReady()) {
        if (CustomerAuth::verifyOtp($mobile, $otp, $name !== '' ? $name : null)) {
            unset($_SESSION['otp_pending_mobile']);
            $return = $_SESSION['checkout_return'] ?? url_base() . '/my-account.php';
            unset($_SESSION['checkout_return']);
            header('Location: ' . $return, true, 302);
            exit;
        }
        $error = 'Incorrect or expired OTP.';
    } elseif (!AppEnvironment::isProduction() && Security::hashEquals((string) ($_SESSION['otp_demo_code'] ?? '123456'), $otp)) {
        CustomerService::loginDemoSession($mobile, $name);
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

layout_public_start([
    'pageTitle' => 'Verify OTP — Apni Tokri',
    'activeNav' => 'account',
    'showBottomNav' => false,
]);
?>
<div class="at-container py-10 md:py-16">
    <div class="grid md:grid-cols-2 gap-8 lg:gap-16 items-center max-w-4xl mx-auto">
        <div class="hidden md:block rounded-[var(--at-radius-lg)] overflow-hidden aspect-[4/5] relative">
            <img src="<?= Security::escape(asset_url('assets/images/demo/p-tomato-2.jpg')) ?>" alt="" class="w-full h-full object-cover">
            <div class="absolute inset-x-0 bottom-0 p-6 text-white" style="background:linear-gradient(180deg,transparent,rgba(19,52,33,.9))">
                <p class="at-display !text-white text-2xl">Har Ghar Ki Taazgi</p>
                <p class="text-sm opacity-90 mt-1">One last step — enter the code we sent to your phone.</p>
            </div>
        </div>
        <div>
            <h1 class="at-h1">Verify your number</h1>
            <p class="mt-2 at-muted">Code sent to <strong class="text-[var(--at-ink)]">+91 <?= Security::escape($mobile) ?></strong>
                <?php if (!Install::isSchemaReady() && !AppEnvironment::isProduction()): ?>
                    <span class="at-badge at-badge--neutral">Demo code 123456</span>
                <?php elseif (OtpSmsService::operationMode() === OtpSmsService::MODE_TEST): ?>
                    <span class="at-badge at-badge--neutral">Test mode — no real SMS sent</span>
                <?php endif; ?>
            </p>
            <?php if ($error !== ''): ?>
                <div class="at-alert at-alert--error mt-4" role="alert"><?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($error) ?></span></div>
            <?php endif; ?>
            <form method="post" class="at-card mt-6" id="otp-form">
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
                    <?php if ($savedName !== ''): ?>
                        <div>
                            <label class="at-label" for="name">Your name</label>
                            <input id="name" type="text" class="at-input at-focusable" value="<?= Security::escape($savedName) ?>" readonly disabled aria-readonly="true">
                            <p class="text-xs at-muted mt-1">You can change your name later from your profile.</p>
                        </div>
                    <?php else: ?>
                        <div>
                            <label class="at-label" for="name">Your name <span class="at-muted font-normal">(optional)</span></label>
                            <input id="name" name="name" type="text" class="at-input at-focusable" autocomplete="name" maxlength="120">
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="at-btn at-btn--primary at-btn--lg at-btn--block at-focusable">Verify &amp; continue</button>
                    <p class="text-xs at-muted flex gap-2"><?= at_icon('lock', 'w-4 h-4 flex-shrink-0') ?>The code expires in 5 minutes. Need a new one? Go back and request again.</p>
                    <a href="<?= Security::escape(url_base()) ?>/login.php" class="at-btn at-btn--ghost at-btn--sm at-focusable self-center">Use a different number / resend OTP</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="<?= Security::escape(asset_url('assets/js/otp-input.js')) ?>"></script>
<?php layout_public_end(); ?>
