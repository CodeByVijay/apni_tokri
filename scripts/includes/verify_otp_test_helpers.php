<?php

declare(strict_types=1);

/**
 * @param array<string, string> $extra
 */
function verify_otp_test_proc(string $childScript, array $extra): int
{
    $env = [];
    foreach ($_ENV as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $env[$k] = $v;
        }
    }
    foreach ($_SERVER as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $env[$k] = $v;
        }
    }
    foreach ($extra as $k => $v) {
        $env[$k] = $v;
    }

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['file', 'NUL', 'w'],
    ];
    if (PHP_OS_FAMILY !== 'Windows') {
        $desc[1] = ['file', '/dev/null', 'w'];
        $desc[2] = ['file', '/dev/null', 'w'];
    }

    $proc = proc_open([PHP_BINARY, $childScript], $desc, $pipes, null, $env);
    if (!is_resource($proc)) {
        return 1;
    }
    $code = proc_close($proc);

    return (int) $code;
}

/** Child-process helper: render verify-otp.php for a faked request. */
function verify_otp_test_run_page(string $childScript, string $mobile, string $method, array $post = []): array
{
    $out = tempnam(sys_get_temp_dir(), 'atv');
    verify_otp_test_proc($childScript, [
        'AT_VERIFY_CHILD' => '1',
        'AT_VERIFY_OUT' => $out,
        'AT_VERIFY_INPUT' => json_encode(['mobile' => $mobile, 'method' => $method, 'post' => $post]),
    ]);
    $data = json_decode((string) file_get_contents($out), true);
    @unlink($out);

    return is_array($data) ? $data : ['logged_in' => false, 'html' => ''];
}

/** Child-process helper: render verify-otp.php with env/SMS options. */
function verify_otp_test_render_html(string $childScript, array $opts): string
{
    $out = tempnam(sys_get_temp_dir(), 'otpd');
    verify_otp_test_proc($childScript, [
        'AT_OTP_DISP_CHILD' => '1',
        'AT_OTP_DISP_OUT' => $out,
        'AT_OTP_DISP_INPUT' => json_encode($opts),
    ]);

    return (string) file_get_contents($out);
}
