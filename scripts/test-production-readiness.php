<?php

declare(strict_types=1);

/**
 * Production hardening checks (no DB mutations).
 * CLI: php scripts/test-production-readiness.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}

/** @param array<string, mixed> $patch */
function withConfig(array $patch, callable $fn): void
{
    $orig = App::config();
    $next = array_replace_recursive($orig, $patch);
    App::setConfig($next);
    try {
        $fn();
    } finally {
        App::setConfig($orig);
        CatalogueGateway::reset();
    }
}

ok(!AppEnvironment::isProduction(), 'Default config is not production (local dev)');

withConfig(['app' => ['env' => 'production']], static function (): void {
    global $fail;
    ok(AppEnvironment::isProduction(), 'production env detected');
    ok(!AppEnvironment::allowsSessionDemoCommerce(), 'session demo commerce off in production');
    ok(!AppEnvironment::allowsDemoCatalogueFallback(), 'demo catalogue fallback off in production');
    ok(!AppEnvironment::allowsSimulatedPaymentEndpoint(), 'simulate endpoint off in production');
    ok(!AppEnvironment::allowsDebugOtpDisplay(), 'debug OTP display off in production');
    ok(AppEnvironment::usePersistedStorefront() === Install::isSchemaReady(), 'persisted storefront follows schema in production');

    CatalogueGateway::reset();
    $cat = CatalogueGateway::instance();
    ok(!$cat->isDemo(), 'CatalogueGateway uses DbCatalogue in production even if empty');

    if (Install::isSchemaReady() && ProductRepository::productCount() === 0) {
        ok(!StorefrontReadiness::isStorefrontOperational(), 'empty catalogue blocks storefront in production');
        $blockers = StorefrontReadiness::productionBlockers();
        ok($blockers !== [], 'productionBlockers lists empty catalogue');
    }
});

withConfig(['app' => ['env' => 'local']], static function (): void {
    if (Install::isSchemaReady()) {
        $db = new DbCatalogue();
        $faqs = $db->faqs(5);
        ok(is_array($faqs), 'DbCatalogue::faqs returns array in database mode');
        foreach ($faqs as $f) {
            ok(isset($f['q'], $f['a']), 'FAQ shape from repository');
            break;
        }
    }
});

$probe = __DIR__ . '/test-cli-guard-probe.php';
foreach (['app-env-production', 'db-role-production'] as $mode) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($mode) . ' 2>&1';
    exec($cmd, $lines, $exitCode);
    $out = implode("\n", $lines);
    $lines = [];
    ok($exitCode === 1 && str_contains($out, 'Refusing to run'), 'CLI guard blocks test scripts (' . $mode . ')');
}

ok(method_exists(CustomerSubscriptionRepository::class, 'countActiveForCustomer'), 'subscription active count API');
ok(method_exists(AdminDashboardService::class, 'customerIndexRows'), 'admin customer list API');

echo "\n" . ($fail === 0 ? 'PASS' : "FAIL ($fail)") . "\n";
exit($fail === 0 ? 0 : 1);
