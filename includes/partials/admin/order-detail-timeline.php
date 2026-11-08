<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
$history = $order['history'] ?? [];
if ($history === []) {
    return;
}
?>
<details class="at-card at-admin-od-collapsible" open>
    <summary class="at-admin-od-collapsible__summary at-card__body">
        <h2 class="at-admin-od-section-title">Order activity timeline</h2>
    </summary>
    <div class="at-card__body pt-0">
        <?php require APNI_TOKRI_ROOT . '/includes/partials/admin/order-status-timeline.php'; ?>
    </div>
</details>
