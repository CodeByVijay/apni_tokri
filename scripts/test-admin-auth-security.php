<?php

declare(strict_types=1);

/**
 * Admin authentication / authorization security checks (HTTP + internal guards).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

if (AppEnvironment::isProduction()) {
    fwrite(STDERR, "ABORT: Refusing admin auth HTTP tests when app.env is production.\n");
    exit(2);
}

$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}

function skip(string $msg): void
{
    echo 'SKIP: ' . $msg . "\n";
}

/**
 * @param list<string> $headers
 * @return array{code: int, headers: string, body: string, location: string|null}
 */
function httpRequest(
    string $method,
    string $url,
    array $headers = [],
    ?string $cookieJarFile = null,
    ?string $postBody = null
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('curl extension required for HTTP tests');
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $postBody,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($cookieJarFile !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookieJarFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieJarFile;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('curl error: ' . curl_error($ch));
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerBlock = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);
    $location = null;
    if (preg_match('/^Location:\s*(.+)$/mi', $headerBlock, $m)) {
        $location = trim($m[1]);
    }

    return ['code' => $code, 'headers' => $headerBlock, 'body' => $body, 'location' => $location];
}

/** @return array{cookies: list<string>, csrf: string|null} */
function parseLoginPage(string $body, string $setCookieHeaders): array
{
    $csrf = null;
    if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $body, $m)) {
        $csrf = $m[1];
    }
    preg_match_all('/^Set-Cookie:\s*([^;=\s]+=[^;]*)/mi', $setCookieHeaders, $matches);
    $cookies = $matches[1] ?? [];

    return ['cookies' => $cookies, 'csrf' => $csrf];
}

$base = rtrim((string) (App::config()['app']['base_url'] ?? ''), '/');
if ($base === '') {
    fwrite(STDERR, "Set app.base_url in config.local.php for HTTP tests.\n");
    exit(1);
}

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — HTTP admin auth tests require DB.\n");
    exit(1);
}

$protected = AdminGuard::protectedAdminScripts();
ok(count($protected) >= 40, 'Protected admin script inventory (' . count($protected) . ' routes)');

ok(!AdminGuard::isPublicScript('dashboard.php'), 'Dashboard is not public');
ok(AdminGuard::isPublicScript('login.php'), 'Login is public');

// --- Unauthenticated HTTP ---
$dash = httpRequest('GET', $base . '/admin/dashboard.php');
ok($dash['code'] === 302 && $dash['location'] !== null && str_contains($dash['location'], 'login.php'), 'Unauthenticated dashboard redirects to login');

$sampleModules = [
    '/admin/orders/index.php',
    '/admin/refunds/index.php',
    '/admin/products/index.php',
    '/admin/settings/index.php',
    '/admin/users/index.php',
];
foreach ($sampleModules as $path) {
    $r = httpRequest('GET', $base . $path);
    ok($r['code'] === 302 && str_contains((string) $r['location'], 'login.php'), 'Unauthenticated GET blocked: ' . $path);
}

$ajax = httpRequest('GET', $base . '/admin/dashboard.php', ['X-Requested-With: XMLHttpRequest'], null);
ok($ajax['code'] === 401, 'Unauthenticated AJAX receives 401');

$post = httpRequest('POST', $base . '/admin/refunds/index.php', [], null, 'action=review&request_id=1');
ok($post['code'] === 302 && str_contains((string) $post['location'], 'login.php'), 'Unauthenticated POST redirected to login');

$demoRole = httpRequest('GET', $base . '/admin/dashboard.php?admin_role=super_admin');
ok($demoRole['code'] === 302 && str_contains((string) $demoRole['location'], 'login.php'), 'Demo role query cannot bypass login');

$storefront = httpRequest('GET', $base . '/index.php');
ok($storefront['code'] === 200, 'Customer storefront index remains reachable');

// --- Authenticated HTTP (optional local smoke; credentials from env only) ---
$httpAdminEmail = getenv('APNI_TEST_HTTP_ADMIN_EMAIL');
$httpAdminPassword = getenv('APNI_TEST_HTTP_ADMIN_PASSWORD');
if (!is_string($httpAdminEmail) || $httpAdminEmail === '' || !is_string($httpAdminPassword) || $httpAdminPassword === '') {
    skip('Authenticated HTTP tests (set APNI_TEST_HTTP_ADMIN_EMAIL and APNI_TEST_HTTP_ADMIN_PASSWORD on a non-production dev host)');
} else {
$jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'at_admin_auth_test_cookies.txt';
@unlink($jar);
$loginGet = httpRequest('GET', $base . '/admin/login.php', [], $jar);
$parsed = parseLoginPage($loginGet['body'], $loginGet['headers']);
if ($parsed['csrf'] === null) {
    skip('Could not parse CSRF on login page');
} else {
    $body = http_build_query([
        '_csrf_token' => $parsed['csrf'],
        'email' => $httpAdminEmail,
        'password' => $httpAdminPassword,
    ]);
    $loginPost = httpRequest(
        'POST',
        $base . '/admin/login.php',
        ['Content-Type: application/x-www-form-urlencoded'],
        $jar,
        $body
    );
    $loginDest = (string) ($loginPost['location'] ?? '');
    $expectChangePw = str_contains($loginDest, 'change-password.php');
    ok($loginPost['code'] === 302 && ($expectChangePw || str_contains($loginDest, 'dashboard.php')), 'Valid admin login redirects to dashboard or mandatory password change');

    $authed = httpRequest('GET', $base . ($expectChangePw ? '/admin/change-password.php' : '/admin/dashboard.php'), [], $jar);
    ok($authed['code'] === 200 && str_contains($authed['body'], 'Veggie Tokri'), 'Authenticated admin reaches post-login destination');

    if ($expectChangePw) {
        $dashBypass = httpRequest('GET', $base . '/admin/dashboard.php', [], $jar);
        ok($dashBypass['code'] === 302 && str_contains((string) $dashBypass['location'], 'change-password.php'), 'Dashboard blocked until password changed');

        $ajaxBypass = httpRequest('GET', $base . '/admin/dashboard.php', ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], $jar);
        ok($ajaxBypass['code'] === 403 && str_contains($ajaxBypass['body'], 'password_change_required'), 'AJAX blocked until password changed (dashboard)');

        $slugAjax = httpRequest('GET', $base . '/admin/ajax/slug-from-name.php?name=test', ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], $jar);
        ok($slugAjax['code'] === 403 && str_contains($slugAjax['body'], 'password_change_required'), 'AJAX slug helper blocked until password changed');
    }

    $logout = httpRequest('GET', $base . '/admin/logout.php', [], $jar);
    ok($logout['code'] === 302 && str_contains((string) $logout['location'], 'login.php'), 'Logout redirects to login');

    $afterLogout = httpRequest('GET', $base . '/admin/dashboard.php', [], $jar);
    ok($afterLogout['code'] === 302 && str_contains((string) $afterLogout['location'], 'login.php'), 'Dashboard blocked after logout (session invalidated)');
}
@unlink($jar);
}

// --- Internal guard (invalid session simulation) ---
$_SESSION = ['admin_user_id' => 999999999];
ok(!AdminAuth::check(), 'Invalid admin session rejected');
unset($_SESSION['admin_user_id']);

// --- Permission matrix requires auth ---
ok(!AdminDemoPerms::can('dashboard'), 'Demo permissions denied without login');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Admin authentication security checks.\n";
exit(0);
