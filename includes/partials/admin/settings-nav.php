<?php

declare(strict_types=1);

/** Shared Settings section tabs. */
$settingsTab = $settingsTab ?? '';
$base = url_base() . '/admin/settings';
?>
<nav class="at-admin-tabs mb-4">
    <a href="<?= Security::escape($base) ?>/index.php" class="<?= $settingsTab === 'business' ? 'is-active' : '' ?> at-focusable">Business profile</a>
    <a href="<?= Security::escape($base) ?>/branding.php" class="<?= $settingsTab === 'branding' ? 'is-active' : '' ?> at-focusable">Website branding</a>
    <a href="<?= Security::escape($base) ?>/gateways.php" class="<?= $settingsTab === 'gateways' ? 'is-active' : '' ?> at-focusable">Payment gateways</a>
    <a href="<?= Security::escape($base) ?>/smtp.php" class="<?= $settingsTab === 'smtp' ? 'is-active' : '' ?> at-focusable">SMTP</a>
    <a href="<?= Security::escape($base) ?>/sms.php" class="<?= $settingsTab === 'sms' ? 'is-active' : '' ?> at-focusable">SMS</a>
    <a href="<?= Security::escape($base) ?>/invoices.php" class="<?= $settingsTab === 'invoices' ? 'is-active' : '' ?> at-focusable">Invoices</a>
    <a href="<?= Security::escape($base) ?>/delivery.php" class="<?= $settingsTab === 'delivery' ? 'is-active' : '' ?> at-focusable">Delivery</a>
</nav>
