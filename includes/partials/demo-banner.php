<?php

declare(strict_types=1);

if (storefront_catalogue_coming_soon()): ?>
<div class="at-demo-strip at-demo-strip--warn" role="status">
    <strong>Products coming soon</strong> · We're preparing our catalogue. Checkout will open when items are published.
</div>
<?php elseif (storefront_uses_demo_catalogue()): ?>
<div class="at-demo-strip" role="status">
    <strong>Demo catalogue</strong> · Sample products, images and reviews for UI preview — not live inventory.
</div>
<?php endif; ?>
