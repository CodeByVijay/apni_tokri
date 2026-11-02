<?php

declare(strict_types=1);

/**
 * Customer OTP SMS — operation mode (test / live / disabled) and safe customer errors.
 */
final class OtpRequestException extends RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, ?string $customerMessage = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($customerMessage ?? OtpSmsService::publicErrorMessage($errorCode));
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

final class OtpSmsService
{
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';
    public const MODE_DISABLED = 'disabled';

    public const ERR_INVALID_MOBILE = 'INVALID_MOBILE';
    public const ERR_RATE_LIMITED = 'RATE_LIMITED';
    public const ERR_SMS_DISABLED = 'SMS_DISABLED';
    public const ERR_SMS_CONFIGURATION_MISSING = 'SMS_CONFIGURATION_MISSING';
    public const ERR_SMS_PROVIDER_UNAVAILABLE = 'SMS_PROVIDER_UNAVAILABLE';
    public const ERR_SMS_PROVIDER_REJECTED = 'SMS_PROVIDER_REJECTED';
    public const ERR_SERVER_ERROR = 'SERVER_ERROR';
    public const ERR_SMS_TEST_NOT_ALLOWED = 'SMS_TEST_NOT_ALLOWED';

    public static function operationMode(): string
    {
        $mode = strtolower(trim((string) (AppSettings::get('sms.operation_mode', self::MODE_DISABLED) ?? self::MODE_DISABLED)));
        if (!in_array($mode, [self::MODE_TEST, self::MODE_LIVE, self::MODE_DISABLED], true)) {
            return self::MODE_DISABLED;
        }
        if ($mode === self::MODE_TEST && !SmsEnvironmentService::allowsTestMode()) {
            return self::MODE_DISABLED;
        }

        return $mode;
    }

    /** Plain OTP for hashing — fixed test code only in permitted test mode (never in production). */
    public static function generateOtpPlain(): string
    {
        if (self::operationMode() === self::MODE_TEST) {
            $fixed = preg_replace('/\D+/', '', (string) (AppSettings::get('sms.test.fixed_otp', '') ?? '')) ?? '';
            if (preg_match('/^\d{6}$/', $fixed)) {
                return $fixed;
            }
        }

        return (string) random_int(100000, 999999);
    }

    public static function sendLoginOtp(string $mobile, string $otpPlain, int $ttlSeconds): array
    {
        $mobile = Validation::normalizeMobileIndia($mobile) ?? '';
        if ($mobile === '' || !preg_match('/^\d{6}$/', $otpPlain)) {
            return ['ok' => false, 'error' => self::ERR_INVALID_MOBILE];
        }

        $mode = self::operationMode();

        if ($mode === self::MODE_DISABLED) {
            SmsDiagnosticsService::record(false, 'login_otp', [
                'provider' => 'disabled',
                'error_code' => 'sms_disabled',
                'error_message' => 'SMS operation mode is disabled',
                'mobile_suffix' => substr($mobile, -4),
            ]);

            return ['ok' => false, 'error' => self::ERR_SMS_DISABLED];
        }

        if ($mode === self::MODE_TEST) {
            SmsDiagnosticsService::record(true, 'login_otp', [
                'provider' => 'test_mode',
                'mobile_suffix' => substr($mobile, -4),
            ]);
            AuditLog::write('sms.otp_test_mode', 'customer_otp', substr($mobile, -4), [
                'mobile_suffix' => substr($mobile, -4),
                'attempt_ttl' => $ttlSeconds,
            ]);

            return ['ok' => true, 'message_id' => 'test_mode_' . bin2hex(random_bytes(4))];
        }

        // Live mode — real provider only; never simulate or expose test OTP.
        if ((AppSettings::get('sms.enabled', '0') ?? '0') !== '1') {
            SmsDiagnosticsService::record(false, 'login_otp', [
                'provider' => 'live',
                'error_code' => 'sms_disabled',
                'error_message' => 'SMS enabled flag is off',
                'mobile_suffix' => substr($mobile, -4),
            ]);

            return ['ok' => false, 'error' => self::ERR_SMS_DISABLED];
        }

        $ready = SmsSettingsService::readinessErrorForSend();
        if ($ready !== null) {
            SmsDiagnosticsService::record(false, 'login_otp', [
                'provider' => (string) (AppSettings::get('sms.provider', '') ?? 'http'),
                'error_code' => 'configuration_missing',
                'error_message' => $ready,
                'mobile_suffix' => substr($mobile, -4),
            ]);

            return ['ok' => false, 'error' => self::ERR_SMS_CONFIGURATION_MISSING];
        }

        if ((AppSettings::get('sms.provider', '') ?? '') !== SmsSettingsService::PROVIDER_HTTP) {
            SmsDiagnosticsService::record(false, 'login_otp', [
                'provider' => (string) (AppSettings::get('sms.provider', '') ?? ''),
                'error_code' => 'configuration_missing',
                'error_message' => 'Live mode requires HTTP provider',
                'mobile_suffix' => substr($mobile, -4),
            ]);

            return ['ok' => false, 'error' => self::ERR_SMS_CONFIGURATION_MISSING];
        }

        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        $template = trim(AppSettings::get('sms.template.otp', '') ?? '');
        if ($template === '') {
            $template = 'Your Apni Tokri login code is {otp}. Valid for {minutes} minutes. Do not share it.';
        }
        $message = str_replace(
            ['{otp}', '{minutes}'],
            [$otpPlain, (string) $minutes],
            $template
        );

        $sender = trim(AppSettings::get('sms.sender_id', '') ?? '');
        $dlt = trim(AppSettings::get('sms.dlt.otp', '') ?? '');
        $adapter = SmsGatewayFactory::adapter();
        $result = $adapter->send($mobile, $message, $sender, $dlt !== '' ? $dlt : null);

        $httpStatus = isset($result['http_status']) ? (int) $result['http_status'] : null;
        SmsDiagnosticsService::recordFromGateway(
            SmsSettingsService::PROVIDER_HTTP,
            $httpStatus,
            $result,
            'login_otp',
            $mobile
        );

        if (empty($result['ok']) || trim((string) ($result['message_id'] ?? '')) === '') {
            $code = self::ERR_SMS_PROVIDER_REJECTED;
            $err = strtolower((string) ($result['error'] ?? ''));
            if ($httpStatus === 0 || strpos($err, 'timeout') !== false || strpos($err, 'transport') !== false) {
                $code = self::ERR_SMS_PROVIDER_UNAVAILABLE;
            }

            error_log('OtpSmsService: live OTP SMS not accepted by provider');

            return ['ok' => false, 'error' => $code];
        }

        return ['ok' => true, 'message_id' => (string) $result['message_id']];
    }

    public static function publicErrorMessage(string $errorCode): string
    {
        $code = strtoupper(trim($errorCode));
        switch ($code) {
            case self::ERR_INVALID_MOBILE:
                return 'Please enter a valid 10-digit mobile number.';
            case self::ERR_RATE_LIMITED:
                return 'Too many OTP requests. Please wait a few minutes before trying again.';
            case self::ERR_SMS_DISABLED:
                return 'Login using OTP is temporarily unavailable. Please try again later.';
            case self::ERR_SMS_CONFIGURATION_MISSING:
                return 'SMS service is not configured correctly. Please contact support.';
            case self::ERR_SMS_PROVIDER_UNAVAILABLE:
                return 'We couldn\'t send your OTP right now. Please try again shortly.';
            case self::ERR_SMS_PROVIDER_REJECTED:
                return 'Your OTP could not be delivered. Please check your mobile number or try again later.';
            case self::ERR_SMS_TEST_NOT_ALLOWED:
                return 'Login using OTP is temporarily unavailable. Please try again later.';
            case self::ERR_SERVER_ERROR:
                return 'Something went wrong while requesting your OTP. Please try again.';
            default:
                if (strpos(strtolower($errorCode), 'too many otp') !== false) {
                    return self::publicErrorMessage(self::ERR_RATE_LIMITED);
                }

                return self::publicErrorMessage(self::ERR_SERVER_ERROR);
        }
    }

    /**
     * Delivery / queue SMS — not login OTP. Disabled mode blocks outbound; test uses simulate adapter.
     */
    public static function isOutboundAllowed(): bool
    {
        $mode = self::operationMode();
        if ($mode === self::MODE_DISABLED) {
            return false;
        }
        if ($mode === self::MODE_TEST) {
            return true;
        }

        return (AppSettings::get('sms.enabled', '0') ?? '0') === '1';
    }
}
