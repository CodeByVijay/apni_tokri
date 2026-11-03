<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

require_once APNI_TOKRI_ROOT . '/includes/partials/admin/flash-messages.php';

t(admin_flash_normalize_type('error') === 'error', 'Normalize error type');
t(admin_flash_normalize_type('WARNING') === 'warning', 'Normalize warning type');
t(admin_flash_normalize_type('unknown') === 'info', 'Unknown type maps to info');

$html = admin_flash_render_html([
    ['message' => 'Saved.', 'type' => 'success'],
    ['message' => 'Could not save.', 'type' => 'error'],
    ['message' => 'Check settings.', 'type' => 'warning'],
    ['message' => 'Tip.', 'type' => 'info'],
]);
t(substr_count($html, ' at-admin-flash"') === 4, 'Renders four dismissible flashes');
t(strpos($html, 'at-alert--success') !== false && strpos($html, 'at-alert--error') !== false, 'Success and error styles');
t(strpos($html, 'at-alert--warning') !== false && strpos($html, 'at-alert--info') !== false, 'Warning and info styles');
t(strpos($html, 'at-admin-flash__close') !== false && substr_count($html, 'Dismiss message') === 4, 'Close buttons with aria-label');
t(strpos($html, 'role="alert"') !== false && strpos($html, 'role="status"') !== false, 'ARIA roles for alert vs status');
t(strpos($html, 'data-at-admin-flash') !== false, 'Flash dismiss hooks present');

$_SESSION['admin_flashes'] = [];
admin_flash_set('One', 'success');
admin_flash_set('Two', 'error');
$consumed = admin_flash_consume();
t(count($consumed) === 2 && $consumed[0]['message'] === 'One' && $consumed[1]['type'] === 'error', 'Multiple session flashes consumed in order');

$_SESSION['admin_flash'] = ['message' => 'Legacy', 'type' => 'warning'];
$legacy = admin_flash_consume();
t(count($legacy) === 1 && $legacy[0]['message'] === 'Legacy' && $legacy[0]['type'] === 'warning', 'Legacy single admin_flash key supported');

$boot = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/admin/bootstrap.php');
t(strpos($boot, 'static $rendered') !== false && strpos($boot, 'admin_flash_render') !== false, 'admin_flash_render guards against duplicate output');

$layout = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/admin.php');
t(strpos($layout, 'admin_flash_render()') !== false, 'Layout renders flashes centrally');
$adminJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');
t(strpos($adminJs, 'initAdminFlashDismiss') !== false, 'Admin JS dismiss handler registered');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
