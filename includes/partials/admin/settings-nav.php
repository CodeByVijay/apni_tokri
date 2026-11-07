<?php

declare(strict_types=1);

/** Shared Settings section tabs. */
$settingsTab = $settingsTab ?? '';
$base = url_base() . '/admin/settings';
?>
<div class="at-admin-tabs-wrap at-admin-tabs-wrap--settings" data-at-settings-tabs>
<nav class="at-admin-tabs at-admin-tabs--settings" aria-label="Settings sections">
    <a href="<?= Security::escape($base) ?>/index.php" class="<?= $settingsTab === 'business' ? 'is-active' : '' ?> at-focusable">Business profile</a>
    <a href="<?= Security::escape($base) ?>/branding.php" class="<?= $settingsTab === 'branding' ? 'is-active' : '' ?> at-focusable">Website branding</a>
    <a href="<?= Security::escape($base) ?>/gateways.php" class="<?= $settingsTab === 'gateways' ? 'is-active' : '' ?> at-focusable">Payment gateways</a>
    <a href="<?= Security::escape($base) ?>/smtp.php" class="<?= $settingsTab === 'smtp' ? 'is-active' : '' ?> at-focusable">SMTP</a>
    <?php if (Permission::canModule('notifications')): ?>
    <a href="<?= Security::escape($base) ?>/email-templates/index.php" class="<?= $settingsTab === 'email_templates' ? 'is-active' : '' ?> at-focusable">Email templates</a>
    <?php endif; ?>
    <a href="<?= Security::escape($base) ?>/sms.php" class="<?= $settingsTab === 'sms' ? 'is-active' : '' ?> at-focusable">SMS</a>
    <a href="<?= Security::escape($base) ?>/orders.php" class="<?= $settingsTab === 'orders' ? 'is-active' : '' ?> at-focusable">Order settings</a>
    <a href="<?= Security::escape($base) ?>/invoices.php" class="<?= $settingsTab === 'invoices' ? 'is-active' : '' ?> at-focusable">Invoices</a>
    <a href="<?= Security::escape($base) ?>/delivery.php" class="<?= $settingsTab === 'delivery' ? 'is-active' : '' ?> at-focusable">Delivery</a>
    <a href="<?= Security::escape($base) ?>/map-geocoding.php" class="<?= $settingsTab === 'map_geocoding' ? 'is-active' : '' ?> at-focusable">Map &amp; Geocoding</a>
</nav>
</div>
