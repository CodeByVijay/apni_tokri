<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('payments');

admin_layout_start(['pageTitle' => 'Payments', 'activeAdminNav' => 'payments']);
$pageHeading = 'Payment history & reconciliation';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/payments/completion_exceptions.php">Payment completion exceptions (captured / slot conflict) →</a></p>
<?php
$rows = [];
if (Install::isSchemaReady() && AdminAuth::check()) {
    foreach (PaymentRepository::listRecent(50) as $p) {
        $rows[] = [
            'id' => (string) ($p['gateway_payment_id'] ?? $p['idempotency_key'] ?? $p['id']),
            'gw' => ucfirst((string) ($p['gateway'] ?? '')),
            'amount' => catalogue_format_price((float) ($p['amount'] ?? 0)),
            'status' => ucfirst((string) ($p['status'] ?? '')),
            'order' => (string) ($p['order_number'] ?? ''),
        ];
    }
}
if ($rows === []) {
    $rows = [
        ['id' => '—', 'gw' => '—', 'amount' => '—', 'status' => 'No payments yet', 'order' => ''],
    ];
}

$columns = [
    ['label' => 'Reference', 'key' => 'id'],
    ['label' => 'Order', 'key' => 'order'],
    ['label' => 'Gateway', 'key' => 'gw'],
    ['label' => 'Amount', 'key' => 'amount', 'align' => 'num'],
    ['label' => 'Status', 'key' => 'status'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
admin_layout_end();
