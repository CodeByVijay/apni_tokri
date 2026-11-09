<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$settingsBase = url_base() . '/admin/settings';
$opc = AdminServerCache::opcacheSummary();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
    if ($action === 'clear_opcache') {
        if (AdminServerCache::clearOpcache()) {
            admin_flash_set('PHP OPcache was reset. This does not clear browsers’ cached pages or images.', 'success');
        } else {
            admin_flash_set('Could not reset OPcache (not available or not permitted).', 'error');
        }
    } else {
        admin_flash_set('Unknown action.', 'error');
    }
    header('Location: ' . $settingsBase . '/cache.php', true, 302);
    exit;
}

if ($opc !== null && ($_GET['refresh'] ?? '') === '1') {
    // Allow bookmarked refresh after OPcache clear.
    $opc = AdminServerCache::opcacheSummary();
}

admin_layout_start(['pageTitle' => 'Server cache', 'activeAdminNav' => 'settings']);
$pageHeading = 'Server cache';
$pageDescription = 'PHP opcode cache on this server. Clearing OPcache does not refresh CSS, JS or images in your browser — use Refresh admin in the header for that.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$settingsTab = 'cache';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<div class="at-card max-w-2xl">
    <div class="at-card__body space-y-4">
        <p class="text-sm at-muted m-0">
            Browser caching is controlled per device. Admin pages send <code class="text-xs">no-store</code> headers;
            static CSS/JS use file-version query strings; product images use file modification time when the file exists on disk.
        </p>

        <?php if ($opc === null): ?>
            <p class="text-sm m-0">OPcache status is not available in this PHP build.</p>
        <?php else: ?>
            <dl class="grid sm:grid-cols-2 gap-3 text-sm m-0">
                <div>
                    <dt class="at-muted font-semibold">OPcache</dt>
                    <dd><?= !empty($opc['enabled']) ? 'Enabled' : 'Disabled' ?></dd>
                </div>
                <div>
                    <dt class="at-muted font-semibold">Cached scripts</dt>
                    <dd><?= (int) ($opc['cached_scripts'] ?? 0) ?></dd>
                </div>
                <?php if ($opc['memory_used_mb'] !== null): ?>
                <div>
                    <dt class="at-muted font-semibold">Memory used</dt>
                    <dd><?= Security::escape((string) $opc['memory_used_mb']) ?> MB</dd>
                </div>
                <?php endif; ?>
                <?php if ($opc['memory_free_mb'] !== null): ?>
                <div>
                    <dt class="at-muted font-semibold">Memory free</dt>
                    <dd><?= Security::escape((string) $opc['memory_free_mb']) ?> MB</dd>
                </div>
                <?php endif; ?>
            </dl>
            <?php if (AdminServerCache::canClearOpcache() && !empty($opc['enabled'])): ?>
            <form method="post" class="pt-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="clear_opcache">
                <button type="submit" class="at-btn at-btn--secondary at-focusable" data-at-submit-once>
                    Reset PHP OPcache
                </button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
admin_layout_end();
