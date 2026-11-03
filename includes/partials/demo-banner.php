<?php

declare(strict_types=1);

if (
    AppEnvironment::isProduction()
    && Install::isSchemaReady()
    && !StorefrontReadiness::cataloguePublished()
): ?>
<div class="at-demo-strip at-demo-strip--warn" role="alert">
    <strong>Store not live</strong> · No products are published yet. Customers cannot check out until the catalogue is ready.
</div>
<?php elseif (!function_exists('app_catalogue') || app_catalogue()->isDemo()): ?>
<div class="at-demo-strip" role="status">
    <strong>Demo catalogue</strong> · Sample products, images and reviews for UI preview — not live inventory.
</div>
<?php endif; ?>
