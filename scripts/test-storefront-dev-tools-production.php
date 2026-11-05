<?php

declare(strict_types=1);

/** Hide/block styleguide + health-check in production; keep ajax/ping for liveness. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

/** @param array<string, mixed> $patch */
function withConfig(array $patch, callable $fn): void
{
    $orig = App::config();
    App::setConfig(array_replace_recursive($orig, $patch));
    try {
        $fn();
    } finally {
        App::setConfig($orig);
    }
}

$footerSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/footer-public.php');
$styleSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/styleguide.php');
$healthSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/health-check.php');
$pingSrc = (string) file_get_contents(APNI_TOKRI_ROOT . '/ajax/ping.php');

t(strpos($footerSrc, 'allowsStorefrontDevelopmentLinks') !== false, 'Footer gates dev links via AppEnvironment');

withConfig(['app' => ['env' => 'production']], static function (): void {
    global $pass, $fail;
    t(!AppEnvironment::allowsStorefrontDevelopmentLinks(), 'production: dev footer links off');
    t(AppEnvironment::isProduction(), 'production env detected');
});

withConfig(['app' => ['env' => 'prod']], static function (): void {
    t(!AppEnvironment::allowsStorefrontDevelopmentLinks(), 'prod alias: dev footer links off');
});

foreach (['local', 'development', 'dev', 'staging'] as $env) {
    withConfig(['app' => ['env' => $env]], static function () use ($env): void {
        t(AppEnvironment::allowsStorefrontDevelopmentLinks(), $env . ': dev footer links on');
    });
}

t(strpos($styleSrc, 'denyProductionDevelopmentSurface') !== false, 'styleguide.php production guard');
t(strpos($healthSrc, 'denyProductionDevelopmentSurface') !== false, 'health-check.php production guard');

// Simulated production: dev pages must exit before rendering (subprocess).
$root = APNI_TOKRI_ROOT;
$runner = '<?php
require ' . var_export($root . '/includes/bootstrap.php', true) . ';
App::setConfig(array_replace_recursive(App::config(), ["app" => ["env" => "production"]]));
$target = $argv[1] ?? "";
if ($target === "styleguide") {
    include ' . var_export($root . '/styleguide.php', true) . ';
} elseif ($target === "health") {
    include ' . var_export($root . '/health-check.php', true) . ';
}
echo "RENDERED";
';
$tmp = sys_get_temp_dir() . '/at-dev-surface-test-' . getmypid() . '.php';
file_put_contents($tmp, $runner);
foreach (['styleguide' => 'styleguide', 'health' => 'health'] as $label => $arg) {
    $cmd = 'php7.3 ' . escapeshellarg($tmp) . ' ' . escapeshellarg($arg) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    t($out !== null && strpos($out, 'Not found') !== false, 'production ' . $label . ' returns plain 404 body');
    t($out !== null && strpos($out, 'RENDERED') === false && strpos($out, 'Installation health') === false && strpos($out, 'design system') === false, 'production ' . $label . ' does not render HTML');
}
@unlink($tmp);

t(strpos($pingSrc, 'Ajax::success') !== false, 'ajax/ping.php liveness endpoint unchanged');

withConfig(['app' => ['env' => 'production']], static function (): void {
    ob_start();
    $showBottomNav = false;
    $activeNav = '';
    require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
    $html = (string) ob_get_clean();
    t(strpos($html, 'styleguide.php') === false, 'production footer HTML omits styleguide');
    t(strpos($html, 'health-check.php') === false, 'production footer HTML omits health-check');
});

withConfig(['app' => ['env' => 'local']], static function (): void {
    ob_start();
    $showBottomNav = false;
    $activeNav = '';
    require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
    $html = (string) ob_get_clean();
    t(strpos($html, 'styleguide.php') !== false, 'local footer HTML includes styleguide');
    t(strpos($html, 'health-check.php') !== false, 'local footer HTML includes health-check');
});

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
