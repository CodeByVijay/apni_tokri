<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('payments');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at', 'amount' => 'amount', 'status' => 'status',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

admin_layout_start(['pageTitle' => 'Payments', 'activeAdminNav' => 'payments']);
$pageHeading = 'Payment history & reconciliation';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/payments/completion_exceptions.php">Payment completion exceptions (captured / slot conflict) →</a></p>
<?php if (!Install::isSchemaReady() || !AdminAuth::check()): ?>
<div class="at-alert at-alert--info">Database admin session required.</div>
<?php else:
    $list = PaymentRepository::searchForAdmin($listQ, $listPage, $listPerPage, $sort, $dir);
    $base = url_base();
    $listQs = http_build_query(array_filter([
        'q' => $listQ !== '' ? $listQ : null,
        'page' => $listPage > 1 ? $listPage : null,
        'per_page' => $listPerPage !== 25 ? $listPerPage : null,
    ]));
    $viewQs = $listQs !== '' ? '&' . $listQs : '';
    $rows = [];
    foreach ($list['rows'] as $p) {
        $paymentId = (int) ($p['id'] ?? 0);
        $orderId = (int) ($p['order_id'] ?? 0);
        $orderNumber = (string) ($p['order_number'] ?? '');
        $viewHref = $base . '/admin/payments/view.php?id=' . $paymentId . $viewQs;
        $orderHref = $orderId > 0 ? $base . '/admin/orders/view.php?id=' . $orderId : '';
        $orderCell = $orderNumber !== '' && $orderHref !== ''
            ? '<a class="underline font-semibold at-focusable" href="' . Security::escape($orderHref) . '">' . Security::escape($orderNumber) . '</a>'
            : Security::escape($orderNumber !== '' ? $orderNumber : '—');
        $rows[] = [
            'id' => PaymentAdminDetailService::publicReference($p),
            'order' => $orderCell,
            'gw' => ucfirst((string) ($p['gateway'] ?? '')),
            'amount' => catalogue_format_price((float) ($p['amount'] ?? 0)),
            'status' => (string) ($p['status'] ?? ''),
            'actions' => '<span class="at-admin-actions"><a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="'
                . Security::escape($viewHref) . '">View</a></span>',
        ];
    }
    $columns = [
        ['label' => 'Reference', 'key' => 'id'],
        ['label' => 'Order', 'key' => 'order', 'raw' => true],
        ['label' => 'Gateway', 'key' => 'gw'],
        ['label' => 'Amount', 'key' => 'amount', 'align' => 'num'],
        ['label' => 'Status', 'key' => 'status'],
        ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
    ];
    require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
    if ((int) $list['total_pages'] > 1) {
        admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, url_base() . '/admin/payments/index.php');
    }
endif;
admin_layout_end();
