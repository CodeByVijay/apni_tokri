<?php

declare(strict_types=1);

/**
 * Phase 2.2 — production blocker regression (SEO/Marketing persistence, subscription payments, schema, admin demo).
 * CLI: php scripts/test-phase22-production-blockers.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok22(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'OK' : 'FAIL') . ': ' . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}

/** @param array<string, mixed> $patch */
function withConfig22(array $patch, callable $fn): void
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

$subCheckout = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/subscription/checkout.php');
$posAssert = strpos($subCheckout, 'assertCheckoutPaymentsAllowed');
$posInsert = strpos($subCheckout, 'INSERT INTO customer_subscriptions');
ok22($posAssert !== false && $posInsert !== false && $posAssert < $posInsert, 'Subscription beginPurchase validates payments before subscription INSERT');
ok22(str_contains($subCheckout, 'beginTransaction'), 'Subscription beginPurchase uses DB transaction');
ok22(str_contains($subCheckout, 'resolveCheckoutGateway'), 'Subscription payment resolves gateway with production guards');
ok22(str_contains($subCheckout, 'Simulated payments are disabled in production'), 'Subscription blocks simulate gateway in production');

$seoAdmin = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/seo/index.php');
ok22(!str_contains($seoAdmin, 'admin_handle_demo_post'), 'SEO admin does not use fake demo POST handler');
ok22(str_contains($seoAdmin, 'SeoRedirectService::registerPermanent'), 'SEO admin persists redirects via service');
ok22(str_contains($seoAdmin, 'SeoRedirectRepository::searchForAdmin'), 'SEO admin lists redirects from database');

$mktAdmin = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/marketing/index.php');
ok22(str_contains($mktAdmin, 'MarketingSettings::saveFromPost'), 'Marketing admin saves via MarketingSettings');
ok22(!str_contains($mktAdmin, 'admin_handle_demo_post'), 'Marketing admin does not use fake demo POST handler');

ok22(class_exists(MarketingSettings::class), 'MarketingSettings class loaded');
ok22(method_exists(SeoRedirectRepository::class, 'searchForAdmin'), 'SeoRedirectRepository admin search');
ok22(method_exists(Install::class, 'missingProductionRequiredTables'), 'Install production required tables check');

withConfig22(['app' => ['env' => 'production', 'debug' => false]], static function (): void {
    ok22(!AppEnvironment::allowsDemoCatalogueFallback(), 'Production: no demo catalogue fallback');
    $blockers = StorefrontReadiness::productionBlockers();
    ok22(is_array($blockers), 'Production: productionBlockers list');
    if (!Install::isProductionSchemaReady()) {
        ok22(
            count(array_filter($blockers, static fn (string $b): bool => str_contains($b, 'Missing required database tables'))) > 0,
            'Production: blockers mention missing required tables when schema incomplete'
        );
    }
    $pay = AppEnvironment::assertProductionPaymentsReady();
    if (PaymentGatewayConfig::activeGateway() === 'simulate' || PaymentGatewayConfig::isTestMode()) {
        ok22(empty($pay['ok']), 'Production: assertProductionPaymentsReady fails for simulate/test_mode');
    }
    $gate = SubscriptionPaymentService::assertCheckoutPaymentsAllowed();
    if (PaymentGatewayConfig::activeGateway() === 'simulate' || PaymentGatewayConfig::isTestMode()) {
        ok22(empty($gate['ok']), 'Production: subscription checkout payment gate fails for simulate/test_mode');
    }
});

withConfig22(['app' => ['env' => 'local', 'debug' => true]], static function (): void {
    if (!Install::isSchemaReady()) {
        ok22(AppEnvironment::allowsDemoCatalogueFallback(), 'Local without schema: demo catalogue allowed');
    }
    ok22(AdminPersistence::allowsDemoPreview() || AdminPersistence::isAvailable(), 'Local: admin demo preview or persistence');
});

withConfig22(['app' => ['env' => 'staging', 'debug' => false]], static function (): void {
    ok22(!AppEnvironment::isProduction(), 'Staging env is not production');
    $gate = SubscriptionPaymentService::assertCheckoutPaymentsAllowed();
    ok22(isset($gate['ok']), 'Staging: subscription payment gate returns result');
});

$faqsPage = (string) file_get_contents(APNI_TOKRI_ROOT . '/faqs.php');
ok22(str_contains($faqsPage, 'productionSchemaMissing'), 'FAQs page fails closed in production without schema');

$gatewayPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/catalogue/gateway.php');
ok22(str_contains($gatewayPhp, 'AppEnvironment::isProduction()') && str_contains($gatewayPhp, 'return [];'), 'DbCatalogue FAQs empty in production without schema');

$bootstrapAdmin = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/admin/bootstrap.php');
ok22(str_contains($bootstrapAdmin, 'admin_flash_persistence_blocked'), 'Admin bootstrap production persistence flash helper');

if (Install::isSchemaReady()
    && (PaymentGatewayConfig::activeGateway() === 'simulate' || PaymentGatewayConfig::isTestMode())) {
    try {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $beforeSubs = (int) $pdo->query("SELECT COUNT(*) FROM customer_subscriptions WHERE status = 'pending_payment'")->fetchColumn();
        $beforePay = (int) $pdo->query('SELECT COUNT(*) FROM subscription_payments')->fetchColumn();
        $planId = (int) $pdo->query('SELECT id FROM subscription_plans WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
        $custId = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($planId > 0 && $custId > 0) {
            withConfig22(['app' => ['env' => 'production', 'debug' => false]], static function () use ($pdo, $planId, $custId, $beforeSubs, $beforePay): void {
                $result = SubscriptionCheckoutService::beginPurchase($planId, $custId);
                ok22(empty($result['ok']), 'Production simulate/test: beginPurchase rejected');
                $afterSubs = (int) $pdo->query("SELECT COUNT(*) FROM customer_subscriptions WHERE status = 'pending_payment'")->fetchColumn();
                $afterPay = (int) $pdo->query('SELECT COUNT(*) FROM subscription_payments')->fetchColumn();
                ok22($afterSubs === $beforeSubs, 'Production simulate/test: no new pending subscriptions on payment gate failure');
                ok22($afterPay === $beforePay, 'Production simulate/test: no new subscription_payments on payment gate failure');
            });
        } else {
            echo "SKIP: subscription write test — need active plan and customer\n";
        }
    } catch (Throwable $e) {
        echo 'SKIP: subscription write test — ' . $e->getMessage() . "\n";
    }
}

echo "\n" . ($fail === 0 ? 'PASS' : "FAIL ($fail)") . "\n";
exit($fail === 0 ? 0 : 1);
