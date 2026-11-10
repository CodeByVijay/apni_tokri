<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('firebase');
$rows = [];
if (PushSchema::tableReady('device_push_registrations')) {
    Install::ensureConnection();
    $stmt = Database::pdo()->query(
        'SELECT d.id, d.customer_id, d.platform, d.status, d.permission_status, d.last_refresh_at, d.created_at,
                p.name AS project_name
         FROM device_push_registrations d
         INNER JOIN firebase_projects p ON p.id = d.firebase_project_id
         ORDER BY d.id DESC LIMIT 200'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

admin_layout_start(['pageTitle' => 'Push devices', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Device push registrations';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<div class="at-card">
    <div class="at-card__body overflow-x-auto">
        <table class="at-data-table w-full text-sm">
            <thead><tr><th>ID</th><th>Project</th><th>Platform</th><th>Customer</th><th>Status</th><th>Permission</th><th>Last refresh</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= Security::escape((string) $r['project_name']) ?></td>
                    <td><?= Security::escape((string) $r['platform']) ?></td>
                    <td><?= $r['customer_id'] !== null ? (int) $r['customer_id'] : 'Guest' ?></td>
                    <td><?= Security::escape((string) $r['status']) ?></td>
                    <td><?= Security::escape((string) $r['permission_status']) ?></td>
                    <td><?= Security::escape((string) ($r['last_refresh_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="7" class="at-muted">No registrations.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_layout_end(); ?>
