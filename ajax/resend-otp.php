<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed.', 405, 'method_not_allowed');
}

Csrf::requireValid();

$mobile = $_SESSION['otp_pending_mobile'] ?? '';
if (!is_string($mobile) || $mobile === '') {
    Ajax::error('Session expired. Please start again from login.', 401, 'session_expired');
}

if (!Install::isSchemaReady()) {
    Ajax::error('OTP resend is unavailable.', 503, 'unavailable');
}

try {
    CustomerAuth::issueOtp($mobile);
    Ajax::success([
        'remaining_seconds' => OtpResendCooldown::remainingSeconds($mobile),
        'cooldown_seconds' => OtpResendCooldown::cooldownSeconds(),
    ], 'A new OTP has been sent.');
} catch (OtpRequestException $e) {
    if ($e->errorCode() === OtpSmsService::ERR_ACCOUNT_INACTIVE) {
        unset($_SESSION['otp_pending_mobile'], $_SESSION['otp_debug_plain'], $_SESSION['otp_demo_code']);
        Ajax::error($e->getMessage(), 403, 'account_inactive');
    }
    if ($e->errorCode() === OtpSmsService::ERR_RESEND_COOLDOWN
        || $e->errorCode() === OtpSmsService::ERR_RATE_LIMITED) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $code = $e->errorCode() === OtpSmsService::ERR_RESEND_COOLDOWN ? 'resend_cooldown' : 'rate_limited';
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $e->getMessage(),
            ],
            'data' => [
                'remaining_seconds' => $e->remainingSeconds(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    Ajax::error($e->getMessage(), 400, strtolower($e->errorCode()));
} catch (Throwable $e) {
    error_log('ajax/resend-otp: ' . get_class($e));
    Ajax::error(OtpSmsService::publicErrorMessage(OtpSmsService::ERR_SERVER_ERROR), 500, 'server_error');
}
