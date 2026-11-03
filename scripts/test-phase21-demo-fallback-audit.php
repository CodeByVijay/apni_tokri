<?php

declare(strict_types=1);

/**
 * Phase 2.1 — regression: production must not enter session/demo catalogue/commerce fallbacks.
 * CLI: php scripts/test-phase21-demo-fallback-audit.php
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
    App::setConfig(array_replace_recursive($orig, $patch));
    try {
        $fn();
    } finally {
        App::setConfig($orig);
        CatalogueGateway::reset();
    }
}

withConfig(['app' => ['env' => 'production', 'debug' => false]], static function (): void {
    ok(!AppEnvironment::allowsSessionDemoCommerce(), 'Production: no session demo commerce');
    ok(!AppEnvironment::allowsDemoCatalogueFallback(), 'Production: no demo catalogue fallback');
    ok(!AppEnvironment::allowsSimulatedPaymentEndpoint(), 'Production: simulate AJAX blocked');
    ok(!AppEnvironment::allowsDebugOtpDisplay(), 'Production: no debug OTP display');
    ok(!PaymentGatewayConfig::useSimulatedCheckout(), 'Production: useSimulatedCheckout false');

    CatalogueGateway::reset();
    $cat = app_catalogue();
    ok(!$cat->isDemo(), 'Production: app_catalogue is DbCatalogue (not DemoCatalogue)');

    $blockers = StorefrontReadiness::productionBlockers();
    ok(is_array($blockers), 'Production: productionBlockers() returns list');
    if (Install::isSchemaReady() && ProductRepository::productCount() === 0) {
        ok(!StorefrontReadiness::isStorefrontOperational(), 'Production: empty catalogue blocks storefront');
    }

    $pay = AppEnvironment::assertProductionPaymentsReady();
    if (PaymentGatewayConfig::activeGateway() === 'simulate' || PaymentGatewayConfig::isTestMode()) {
        ok(empty($pay['ok']), 'Production: payment readiness fails when simulate/test mode configured');
    }

    ok(
        !AppEnvironment::isProduction() || !Install::isSchemaReady() || DbCatalogue::class === get_class($cat),
        'Production: catalogue instance class is DbCatalogue when schema ready'
    );
});

withConfig(['app' => ['env' => 'local', 'debug' => true]], static function (): void {
    if (!Install::isSchemaReady()) {
        ok(AppEnvironment::allowsDemoCatalogueFallback(), 'Local without schema: demo catalogue fallback allowed');
        ok(AppEnvironment::allowsSessionDemoCommerce(), 'Local without schema: session commerce allowed');
    } elseif (!CommerceGateway::useDatabase()) {
        ok(AppEnvironment::allowsDemoCatalogueFallback(), 'Local empty DB catalogue: demo fallback allowed');
    }
});

// Static wiring checks (no DB mutation)
$checkout = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/checkout.php');
ok(str_contains($checkout, 'StorefrontReadiness::isStorefrontOperational'), 'Checkout checks storefront readiness');
ok(str_contains($checkout, 'allowsSessionDemoCommerce'), 'Checkout gates session demo orders');

$customer = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/commerce/customer.php');
ok(str_contains($customer, 'usePersistedStorefront'), 'CustomerService uses persisted storefront in production path');

$gateway = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/catalogue/gateway.php');
ok(str_contains($gateway, 'AppEnvironment::isProduction()'), 'CatalogueGateway production branch present');

$dashboard = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/dashboard.php');
ok(str_contains($dashboard, 'AdminDashboardService'), 'Admin dashboard uses DB KPI service');
ok(!str_contains($dashboard, 'Demo Customer'), 'Admin dashboard has no hardcoded demo customer');

$customers = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/customers/index.php');
ok(str_contains($customers, 'AdminDashboardService::searchCustomersForAdmin'), 'Admin customers list is DB-driven');

$simulate = (string) file_get_contents(APNI_TOKRI_ROOT . '/ajax/payment/simulate.php');
ok(str_contains($simulate, 'allowsSimulatedPaymentEndpoint'), 'Simulate endpoint production guard');

echo "\n" . ($fail === 0 ? 'PASS' : "FAIL ($fail)") . "\n";
exit($fail === 0 ? 0 : 1);
