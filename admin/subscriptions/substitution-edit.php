<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $result = SubscriptionSubstitutionService::adminPropose(
        (int) ($_POST['substitution_id'] ?? 0),
        (int) ($_POST['proposed_variant_id'] ?? 0),
        (float) ($_POST['proposed_qty'] ?? 1),
        (float) ($_POST['proposed_unit_price'] ?? 0),
        isset($_POST['admin_note']) && is_string($_POST['admin_note']) ? trim($_POST['admin_note']) : null
    );
    $message = $result['ok'] ? 'Proposal saved — customer must approve and pay any extra amount.' : ($result['message'] ?? 'Failed');
}

Install::ensureConnection();
$row = null;
if ($id > 0) {
    $stmt = Database::pdo()->prepare('SELECT * FROM subscription_basket_substitutions WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

admin_layout_start(['pageTitle' => 'Paid substitution', 'activeAdminNav' => 'subscriptions']);
?>
<div class="at-admin-card">
    <?php if ($message !== ''): ?>
        <div class="at-alert at-alert--info"><?= Security::escape($message) ?></div>
    <?php endif; ?>
    <?php if ($row === null): ?>
        <p class="at-muted">Substitution #<?= (int) $id ?> not found.</p>
    <?php else: ?>
        <form method="post" class="grid gap-3 max-w-lg">
            <?= Csrf::field() ?>
            <input type="hidden" name="substitution_id" value="<?= (int) $row['id'] ?>">
            <label class="at-label">Proposed variant ID</label>
            <input class="at-input" name="proposed_variant_id" type="number" required value="<?= (int) $row['proposed_variant_id'] ?>">
            <label class="at-label">Qty</label>
            <input class="at-input" name="proposed_qty" type="number" step="0.001" value="<?= Security::escape((string) $row['proposed_qty']) ?>">
            <label class="at-label">Unit price (INR)</label>
            <input class="at-input" name="proposed_unit_price" type="number" step="0.01" value="<?= Security::escape((string) $row['proposed_unit_price_inr']) ?>">
            <label class="at-label">Admin note</label>
            <textarea class="at-textarea" name="admin_note" rows="2"><?= Security::escape((string) ($row['admin_note'] ?? '')) ?></textarea>
            <button type="submit" class="at-btn at-btn--primary">Save proposal</button>
        </form>
    <?php endif; ?>
</div>
<?php
admin_layout_end();
