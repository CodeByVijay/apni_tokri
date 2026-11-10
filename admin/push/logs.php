<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('firebase');
$rows = [];
if (PushSchema::tableReady('push_notification_logs')) {
    Install::ensureConnection();
    $stmt = Database::pdo()->query(
        'SELECT id, event_id, platform, customer_id, notification_type, status, http_status,
                failure_category, idempotency_key, title_redacted, created_at
         FROM push_notification_logs ORDER BY id DESC LIMIT 200'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

admin_layout_start(['pageTitle' => 'Push logs', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Push notification logs';
$pageDescription = 'Delivery outcomes only — titles are redacted; secrets and full device tokens are never logged.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<div class="at-card">
    <div class="at-card__body overflow-x-auto">
        <table class="at-data-table w-full text-sm">
            <thead><tr><th>When</th><th>Type</th><th>Status</th><th>Customer</th><th>Title</th><th>Idempotency</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= Security::escape((string) $r['created_at']) ?></td>
                    <td><?= Security::escape((string) $r['notification_type']) ?></td>
                    <td><?= Security::escape((string) $r['status']) ?><?php if (!empty($r['failure_category'])): ?> <span class="at-muted">(<?= Security::escape((string) $r['failure_category']) ?>)</span><?php endif; ?></td>
                    <td><?= $r['customer_id'] !== null ? (int) $r['customer_id'] : '—' ?></td>
                    <td><?= Security::escape((string) ($r['title_redacted'] ?? '')) ?></td>
                    <td class="text-xs"><code><?= Security::escape((string) $r['idempotency_key']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="6" class="at-muted">No logs yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_layout_end(); ?>
