<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$lineKey = isset($_POST['line_key']) && is_string($_POST['line_key']) ? trim($_POST['line_key']) : '';
$qty = isset($_POST['qty']) ? (int) $_POST['qty'] : 1;

$result = DemoCart::updateQty($lineKey, $qty);
if (!$result['ok']) {
    Ajax::error($result['message']);
}

Ajax::success([
    'count' => DemoCart::itemCount(),
    'totals' => $result['totals'] ?? DemoCart::totals(),
], 'Cart updated');
