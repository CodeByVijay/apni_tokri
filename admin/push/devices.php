<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('firebase');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $id = (int) ($_POST['id'] ?? 0);
    if (isset($_POST['action']) && $_POST['action'] === 'revoke' && $id > 0) {
        $result = PushTokenService::adminRevokeById($id);
        admin_flash_set((string) ($result['message'] ?? 'Done.'), !empty($result['ok']) ? 'success' : 'error');
    }
    header('Location: ' . url_base() . '/admin/push/devices.php', true, 302);
    exit;
}

$rows = [];
if (PushSchema::tableReady('device_push_registrations')) {
    Install::ensureConnection();
    $stmt = Database::pdo()->query(
        'SELECT d.id, d.customer_id, d.platform, d.target_type, d.status, d.permission_status,
                d.last_refresh_at, d.created_at, LEFT(d.fcm_token, 12) AS token_prefix,
                p.name AS project_name, p.is_active_global
         FROM device_push_registrations d
         INNER JOIN firebase_projects p ON p.id = d.firebase_project_id
         ORDER BY d.id DESC LIMIT 200'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

admin_layout_start(['pageTitle' => 'Push devices', 'activeAdminNav' => 'firebase']);
$pageHeading = 'Device push registrations';
$pageDescription = 'Registered FIDs (Web) and FCM tokens (Android) tied to the Firebase project active at registration time.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>

<div class="at-alert at-alert--info mb-4 max-w-3xl text-sm">
    <p class="mb-2">Only a short <strong>token prefix</strong> is shown here — full tokens are never displayed. Use the prefix with Device test send when validating a single handset.</p>
    <p class="mb-0"><strong>Revoke</strong> marks a row inactive so it will not receive pushes until the customer registers again from the app or website.</p>
</div>

<div class="at-card">
    <div class="at-card__body overflow-x-auto">
        <table class="at-data-table w-full text-sm">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Project</th>
                    <th>Platform</th>
                    <th>Target</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Permission</th>
                    <th>Last refresh</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= Security::escape((string) $r['project_name']) ?><?php if (empty($r['is_active_global'])): ?> <span class="at-muted">(inactive)</span><?php endif; ?></td>
                    <td><?= Security::escape((string) $r['platform']) ?></td>
                    <td><?= Security::escape((string) ($r['target_type'] ?? 'fcm_token')) ?> · <?= Security::escape((string) ($r['token_prefix'] ?? '')) ?>…</td>
                    <td><?= $r['customer_id'] !== null ? (int) $r['customer_id'] : 'Guest' ?></td>
                    <td><?= Security::escape((string) $r['status']) ?></td>
                    <td><?= Security::escape((string) $r['permission_status']) ?></td>
                    <td><?= Security::escape((string) ($r['last_refresh_at'] ?? '—')) ?></td>
                    <td>
                        <?php if ((string) $r['status'] === 'active'): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Revoke this registration?');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" name="action" value="revoke" class="at-link">Revoke</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="9" class="at-muted">No registrations.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_layout_end(); ?>
