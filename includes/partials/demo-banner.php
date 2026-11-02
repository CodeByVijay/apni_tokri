<?php

declare(strict_types=1);

if (!function_exists('app_catalogue') || app_catalogue()->isDemo()) {
    ?>
<div class="at-demo-strip" role="status">
    <strong>Demo catalogue</strong> · Sample products, images and reviews for UI preview — not live inventory.
</div>
    <?php
}
