<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');

if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed.', 405);
}
Csrf::requireValid();

$action = (string) ($_POST['action'] ?? '');
$scope = (string) ($_POST['scope'] ?? ProductBulkExport::SCOPE_FILTERED);
$rawIds = $_POST['product_ids'] ?? [];
$productIds = is_array($rawIds) ? ProductRepository::normalizePositiveIntIds($rawIds) : [];

$filters = [
    'scope' => $scope,
    'query' => trim((string) ($_POST['query'] ?? '')),
    'category' => trim((string) ($_POST['category'] ?? '')),
    'status' => trim((string) ($_POST['status'] ?? '')),
    'product_ids' => $productIds,
    'include_images' => !empty($_POST['include_images']),
];

if ($action === 'count') {
    $count = ProductBulkExport::resolveExportCount($filters);
    if (empty($count['ok'])) {
        Ajax::error((string) ($count['message'] ?? 'Could not count products.'));
    }
    Ajax::success([
        'count' => (int) ($count['count'] ?? 0),
        'capped' => !empty($count['capped']),
        'max' => ProductBulkExport::MAX_EXPORT,
    ]);
}

Ajax::error('Unknown action.', 400);
