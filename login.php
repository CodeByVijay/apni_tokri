<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $mobile = isset($_POST['mobile']) && is_string($_POST['mobile']) ? $_POST['mobile'] : '';
    $normalized = Validation::normalizeMobileIndia($mobile);
    if ($normalized === null) {
        $error = OtpSmsService::publicErrorMessage(OtpSmsService::ERR_INVALID_MOBILE);
    } elseif (Install::isSchemaReady()) {
        try {
            $issued = CustomerAuth::issueOtp($normalized);
            $_SESSION['otp_pending_mobile'] = $issued['mobile'];
            header('Location: ' . url_base() . '/verify-otp.php', true, 302);
            exit;
        } catch (OtpRequestException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('login OTP request failed: ' . get_class($e));
            $error = OtpSmsService::publicErrorMessage(OtpSmsService::ERR_SERVER_ERROR);
        }
    } else {
        $_SESSION['otp_pending_mobile'] = $normalized;
        $_SESSION['otp_demo_code'] = '123456';
        header('Location: ' . url_base() . '/verify-otp.php', true, 302);
        exit;
    }
}

layout_public_start([
    'pageTitle' => 'Login — Apni Tokri',
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
                <p class="text-sm opacity-90 mt-1">Log in to track orders, manage subscriptions and reorder in a tap.</p>
            </div>
        </div>
        <div>
            <h1 class="at-h1">Welcome back</h1>
            <p class="mt-2 at-muted">Enter your mobile number — we’ll send a one-time password. No password to remember.</p>
            <?php if ($error !== ''): ?>
                <div class="at-alert at-alert--error mt-4" role="alert"><?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($error) ?></span></div>
            <?php endif; ?>
            <form method="post" class="at-card mt-6">
                <div class="at-card__body grid gap-4">
                    <?= Csrf::field() ?>
                    <div>
                        <label class="at-label" for="mobile">Mobile number</label>
                        <div class="at-auth-mobile">
                            <span class="at-auth-mobile__prefix" aria-hidden="true">+91</span>
                            <input id="mobile" name="mobile" type="tel" class="at-input at-focusable flex-1 min-w-0" placeholder="98XXXXXXXX" inputmode="numeric" maxlength="10" autocomplete="tel-national" pattern="[6-9][0-9]{9}" required aria-describedby="mobile-hint">
                        </div>
                        <p id="mobile-hint" class="at-field-error hidden" role="alert">Enter a valid 10-digit mobile number starting with 6–9.</p>
                    </div>
                    <button type="submit" class="at-btn at-btn--primary at-btn--lg at-btn--block at-focusable">Send OTP</button>
                    <p class="text-xs at-muted flex gap-2"><?= at_icon('lock', 'w-4 h-4 flex-shrink-0') ?><?= Install::isSchemaReady() ? 'OTP is hashed in the database. Rate limits apply (3 per number / 15 min).' : 'Demo mode — use code 123456 on the next screen.' ?></p>
                </div>
            </form>
        </div>
    </div>
</div>
<?php layout_public_end(); ?>
