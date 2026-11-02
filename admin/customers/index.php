<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('customers');

admin_layout_start(['pageTitle' => 'Customers', 'activeAdminNav' => 'customers']);
$pageHeading = 'Customers';
$pageDescription = 'Admin cannot view customer OTPs or passwords.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$columns = [
    ['label' => 'Name', 'key' => 'name'],
    ['label' => 'Mobile', 'key' => 'mobile'],
    ['label' => 'Orders', 'key' => 'orders', 'align' => 'num'],
    ['label' => 'Joined', 'key' => 'joined'],
];
$rows = [['name' => 'Demo Customer', 'mobile' => '98XXXXXX01', 'orders' => '3', 'joined' => 'Jan 2026']];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
admin_layout_end();
