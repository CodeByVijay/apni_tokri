<?php

declare(strict_types=1);

/**
 * Peek next product SKU for add-product form (does not reserve).
 * GET — requires products admin permission.
 */
require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

Ajax::success(['sku' => ProductSkuService::peekNext()]);
