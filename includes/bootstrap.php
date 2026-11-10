<?php

declare(strict_types=1);

/**
 * Single entry bootstrap for web and CLI (cron) scripts.
 */

if (!defined('APNI_TOKRI_ROOT')) {
    define('APNI_TOKRI_ROOT', dirname(__DIR__));
}

require_once APNI_TOKRI_ROOT . '/includes/constants.php';
require_once APNI_TOKRI_ROOT . '/includes/compat/php73.php';

// AT-SEC-001: block HTTP execution of /scripts/*.php when .htaccess is not applied.
if (PHP_SAPI !== 'cli') {
    $scriptFile = isset($_SERVER['SCRIPT_FILENAME'])
        ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_FILENAME'])
        : '';
    $scriptsRoot = str_replace('\\', '/', APNI_TOKRI_ROOT . '/scripts/');
    if ($scriptFile !== '' && str_starts_with($scriptFile, $scriptsRoot)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden';
        exit;
    }
}
require_once APNI_TOKRI_ROOT . '/includes/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/app_environment.php';
require_once APNI_TOKRI_ROOT . '/includes/storefront/readiness.php';
require_once APNI_TOKRI_ROOT . '/includes/storefront/design_preview.php';
require_once APNI_TOKRI_ROOT . '/includes/account/list_pagination.php';
require_once APNI_TOKRI_ROOT . '/includes/lang.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/public.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/download_response.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/http_cache.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/server_cache.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/customer_admin.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/dashboard_service.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/list_actions.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/server_list.php';
require_once APNI_TOKRI_ROOT . '/includes/layout/admin.php';
require_once APNI_TOKRI_ROOT . '/includes/security.php';
require_once APNI_TOKRI_ROOT . '/includes/validation.php';
require_once APNI_TOKRI_ROOT . '/includes/csrf.php';
require_once APNI_TOKRI_ROOT . '/includes/ajax.php';
require_once APNI_TOKRI_ROOT . '/includes/database.php';
require_once APNI_TOKRI_ROOT . '/includes/install/hosting_paths.php';
require_once APNI_TOKRI_ROOT . '/includes/install/sql_importer.php';
require_once APNI_TOKRI_ROOT . '/includes/install/wizard_service.php';
require_once APNI_TOKRI_ROOT . '/includes/install.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/login_security.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/password_recovery.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/customer_otp_registration.php';
require_once APNI_TOKRI_ROOT . '/includes/auth.php';
require_once APNI_TOKRI_ROOT . '/includes/auth/customer_remember_auth.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/persistence.php';
require_once APNI_TOKRI_ROOT . '/includes/permissions.php';
require_once APNI_TOKRI_ROOT . '/includes/audit.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/rbac.php';
require_once APNI_TOKRI_ROOT . '/includes/upload.php';
require_once APNI_TOKRI_ROOT . '/includes/demo/catalogue.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/slug.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/category_repository.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/category_cover_image.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_repository.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_share.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_bulk_import.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_bulk_export.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_image_admin.php';
require_once APNI_TOKRI_ROOT . '/includes/media/media_library.php';
require_once APNI_TOKRI_ROOT . '/includes/media/media_library_zip_import.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_sku.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_type_repository.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/inventory.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/gateway.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/config.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/entitlement.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/restock.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/slots.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/status_transitions.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/assignment.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/customer_timeline.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/sms_environment.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/sms_diagnostics.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/sms_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/sms_gateway.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/startmessaging_sms.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/otp_sms.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/otp_resend_cooldown.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/otp_rate_limit.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/smtp_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/smtp_mailer.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/sms_http_policy.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/sms_send_lock.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/delivery_sms.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/delivery_email.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/template_repository.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/template_defaults.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/template_variables.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/email_html_sanitize.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/customer_email_layout.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/order_email_context.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/template_admin_service.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/template_renderer.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/order_environment_badge.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/queue_service.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/queue_admin_detail.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/dispatcher.php';
require_once APNI_TOKRI_ROOT . '/includes/notifications/admin_notifications.php';
require_once APNI_TOKRI_ROOT . '/includes/push/schema.php';
require_once APNI_TOKRI_ROOT . '/includes/push/url_validator.php';
require_once APNI_TOKRI_ROOT . '/includes/push/firebase_oauth.php';
require_once APNI_TOKRI_ROOT . '/includes/push/firebase_fcm_client.php';
require_once APNI_TOKRI_ROOT . '/includes/push/firebase_credential_manager.php';
require_once APNI_TOKRI_ROOT . '/includes/push/firebase_project_manager.php';
require_once APNI_TOKRI_ROOT . '/includes/push/push_token_service.php';
require_once APNI_TOKRI_ROOT . '/includes/push/push_template_repository.php';
require_once APNI_TOKRI_ROOT . '/includes/push/push_notification_service.php';
require_once APNI_TOKRI_ROOT . '/includes/push/push_event_bridge.php';
require_once APNI_TOKRI_ROOT . '/includes/push/firebase_admin_service.php';
require_once APNI_TOKRI_ROOT . '/includes/reports/date_range.php';
require_once APNI_TOKRI_ROOT . '/includes/reports/csv_export.php';
require_once APNI_TOKRI_ROOT . '/includes/reports/sales_report.php';
require_once APNI_TOKRI_ROOT . '/includes/reports/inventory_report.php';
require_once APNI_TOKRI_ROOT . '/includes/reports/payment_report.php';
require_once APNI_TOKRI_ROOT . '/includes/reports/refund_report.php';
require_once APNI_TOKRI_ROOT . '/includes/reports/subscription_report.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/slot_reservations.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/payment_slot_hold.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/slot_admin.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/gateway.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/delivery.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/distance.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/customer_messages.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/zone_resolver.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/quote.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/admin_ui.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/slot_display.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/order_snapshot.php';
require_once APNI_TOKRI_ROOT . '/includes/map/config.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_admin.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/address_input.php';
require_once APNI_TOKRI_ROOT . '/includes/delivery/address_snapshot.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/coupon.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/offer.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/coupon_admin.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/pricing.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/cart_quantity.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/cart_delivery_banner.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/cart.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/cart_coupon_handler.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/coupon_storefront.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/coupon_offers.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/offer_storefront.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/customer.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/account.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/order_cancellation.php';
require_once APNI_TOKRI_ROOT . '/includes/encryption.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/store.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/branding.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/branding_social.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/branding_app_stores.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/business.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/order_number.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/marketing.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/diagnostics.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/http.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/razorpay.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/cashfree.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/config.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/gateway_diagnostic_state.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/service.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/payment_admin_detail.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/unpaid_order_expiry.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/refund_gateway.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/completion_exception.php';
require_once APNI_TOKRI_ROOT . '/includes/payment/fulfilment_reconciliation.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/order_fulfillment.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/review.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/refund.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/refund_reconciliation.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/refund_completion.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/cashfree_refund_webhook.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/refund_webhook.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/support.php';
require_once APNI_TOKRI_ROOT . '/includes/trust/testimonial.php';
require_once APNI_TOKRI_ROOT . '/includes/cms/sanitize.php';
require_once APNI_TOKRI_ROOT . '/includes/cms/page_repository.php';
require_once APNI_TOKRI_ROOT . '/includes/cms/install_safety.php';
require_once APNI_TOKRI_ROOT . '/includes/cms/page_service.php';
require_once APNI_TOKRI_ROOT . '/includes/faq/repository.php';
require_once APNI_TOKRI_ROOT . '/includes/faq/service.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/url.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/metadata.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/resolver.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/redirect_path.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/redirect_repository.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/redirect_service.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/not_found_log.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/admin_center.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/sitemap.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/llms_txt.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/robots.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/json_ld.php';
require_once APNI_TOKRI_ROOT . '/includes/seo/structured_data.php';
require_once APNI_TOKRI_ROOT . '/includes/homepage/trust.php';
require_once APNI_TOKRI_ROOT . '/includes/homepage/trust_icons.php';
require_once APNI_TOKRI_ROOT . '/includes/homepage/trust_render.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/schedule.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/plan.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/cutoff.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/audit.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/menu.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/menu_plan.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/basket.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/auto_confirm.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/credit_schedule.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/lifecycle.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/cron_lock.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/notify.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/notification_dedupe.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/inventory_reserve.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/substitution.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/fulfilment.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/reservation_recovery.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/recovery_log.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/refund.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/slot.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/substitution_payment.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/activation.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/admin_lists.php';
require_once APNI_TOKRI_ROOT . '/includes/subscription/checkout.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/checkout_idempotency.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/checkout.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/customer_order_detail.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/payment_gateway_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/admin_orders.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/admin_order_detail.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/order_delivery_reschedule.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/order_status_timeline.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/format.php';
require_once APNI_TOKRI_ROOT . '/includes/commerce/order_number.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/production_security.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/pdf_document.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/storage.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/settings.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/pdf_renderer.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/service.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/email.php';
require_once APNI_TOKRI_ROOT . '/includes/invoice/query.php';
require_once APNI_TOKRI_ROOT . '/includes/demo/cart.php';
require_once APNI_TOKRI_ROOT . '/includes/demo/customer.php';
require_once APNI_TOKRI_ROOT . '/includes/error_handler.php';

/** @var array<string, mixed> $config */
$config = require APNI_TOKRI_ROOT . '/includes/config.php';
$config = InstallWizardService::withRequestDerivedAppUrls($config);

App::setConfig($config);
AppErrorHandler::register($config);

if (Install::hasLocalConfig()) {
    try {
        Database::connect($config['database']);
        if (Install::isSchemaReady()) {
            BusinessSettings::migrateLegacyInvoiceIdentity();
        }
    } catch (Throwable $e) {
        if (App::isDebug()) {
            error_log('DB connect: ' . $e->getMessage());
        }
    }
}

if (PHP_SAPI === 'cli') {
    AppEnvironment::guardCliScript((string) ($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? '')));
    if (str_contains(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? '')), '/cron/')) {
        AppEnvironment::assertCronEnvironment();
    }
} else {
    AppSession::start($config['session'] ?? []);
    CustomerRememberAuth::maybeRestoreFromCookie();
    Lang::bootstrapFromRequest();
    require_once APNI_TOKRI_ROOT . '/includes/seo/redirect_request.php';
}
