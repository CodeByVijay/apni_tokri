<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$base = url_base() . '/admin/subscriptions';
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$errors = [];
$template = [
    'name' => '',
    'planner_type' => 'weekly',
    'template_date' => '',
    'weekday' => '1',
    'day_index' => '1',
    'notes' => '',
    'is_active' => '1',
];
$items = [];

if ($id !== null && Install::isSchemaReady()) {
    $row = SubscriptionMenuRepository::find($id);
    if ($row === null) {
        header('Location: ' . $base . '/menu-planner.php', true, 302);
        exit;
    }
    $template = array_merge($template, $row);
    $items = SubscriptionMenuRepository::itemsForTemplate($id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    $postItems = [];
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $postItems[] = $it;
        }
    }
    $result = SubscriptionMenuRepository::save($id, $_POST, $postItems);
    if (!$result['ok']) {
        $errors = $result['errors'] ?? ['Could not save template.'];
        $template = array_merge($template, $_POST);
    } else {
        admin_flash_set('Menu template saved.', 'success');
        header('Location: ' . $base . '/menu-edit.php?id=' . (int) $result['id'], true, 302);
        exit;
    }
}

$variants = InventoryService::variantsForSelect();

admin_layout_start(['pageTitle' => 'Menu template', 'activeAdminNav' => 'subscriptions']);
$pageHeading = $id ? 'Edit menu template' : 'New menu template';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<?php if ($errors !== []): ?>
    <div class="at-alert at-alert--error"><?= Security::escape(implode(' ', $errors)) ?></div>
<?php endif; ?>
<form method="post" class="at-card mt-4">
    <div class="at-card__body grid gap-4 max-w-3xl">
        <?= Csrf::field() ?>
        <div><label class="at-label">Name</label><input class="at-input at-focusable w-full" name="name" required value="<?= Security::escape((string) $template['name']) ?>"></div>
        <div>
            <label class="at-label">Planner type</label>
            <select class="at-input at-focusable" name="planner_type">
                <?php foreach (['date' => 'Specific date', 'weekly' => 'Weekly (weekday)', 'day_index' => 'Delivery day index'] as $k => $lbl): ?>
                    <option value="<?= Security::escape($k) ?>" <?= ($template['planner_type'] ?? '') === $k ? 'selected' : '' ?>><?= Security::escape($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="grid sm:grid-cols-3 gap-3">
            <div><label class="at-label">Date (date type)</label><input type="date" class="at-input at-focusable w-full" name="template_date" value="<?= Security::escape((string) ($template['template_date'] ?? '')) ?>"></div>
            <div><label class="at-label">Weekday 1–7</label><input type="number" min="1" max="7" class="at-input at-focusable w-full" name="weekday" value="<?= Security::escape((string) ($template['weekday'] ?? '')) ?>"></div>
            <div><label class="at-label">Day index</label><input type="number" min="1" class="at-input at-focusable w-full" name="day_index" value="<?= Security::escape((string) ($template['day_index'] ?? '')) ?>"></div>
        </div>
        <div><label class="at-label"><input type="checkbox" name="is_active" value="1" <?= !empty($template['is_active']) ? 'checked' : '' ?>> Active</label></div>
        <div><label class="at-label">Notes</label><textarea class="at-input at-focusable w-full" name="notes" rows="2"><?= Security::escape((string) ($template['notes'] ?? '')) ?></textarea></div>

        <h3 class="at-h3 mt-4">Vegetable lines</h3>
        <p class="text-sm at-muted">Unit prices are snapshotted into suggested baskets. Stock must be real — unavailable SKUs are excluded from suggestions.</p>
        <div id="menu-items" class="grid gap-3">
            <?php
            $rows = $items !== [] ? $items : [['variant_id' => '', 'default_qty' => 1, 'unit_label' => 'kg', 'unit_price_inr' => '', 'include_in_rotation' => 1, 'max_qty' => '']];
            foreach ($rows as $i => $it):
                ?>
                <div class="grid sm:grid-cols-6 gap-2 border border-[var(--at-line)] p-3 rounded-lg menu-item-row">
                    <div class="sm:col-span-2">
                        <label class="at-label text-xs">Variant</label>
                        <select class="at-input at-focusable w-full" name="items[<?= (int) $i ?>][variant_id]">
                            <option value="">—</option>
                            <?php foreach ($variants as $v): ?>
                                <option value="<?= (int) $v['id'] ?>" <?= (int) ($it['variant_id'] ?? 0) === (int) $v['id'] ? 'selected' : '' ?>>
                                    <?= Security::escape($v['product_name'] . ' — ' . $v['name'] . ' (stock ' . $v['stock_qty'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label class="at-label text-xs">Qty</label><input class="at-input at-focusable w-full" name="items[<?= (int) $i ?>][default_qty]" type="number" step="0.001" min="0.001" value="<?= Security::escape((string) ($it['default_qty'] ?? '1')) ?>"></div>
                    <div><label class="at-label text-xs">Unit</label><input class="at-input at-focusable w-full" name="items[<?= (int) $i ?>][unit_label]" value="<?= Security::escape((string) ($it['unit_label'] ?? 'kg')) ?>"></div>
                    <div><label class="at-label text-xs">Price (INR)</label><input class="at-input at-focusable w-full" name="items[<?= (int) $i ?>][unit_price_inr]" type="number" step="0.01" min="0" value="<?= Security::escape((string) ($it['unit_price_inr'] ?? '')) ?>"></div>
                    <div><label class="at-label text-xs">Max qty</label><input class="at-input at-focusable w-full" name="items[<?= (int) $i ?>][max_qty]" type="number" step="0.001" value="<?= Security::escape((string) ($it['max_qty'] ?? '')) ?>"></div>
                    <div class="flex items-end"><label class="text-sm"><input type="checkbox" name="items[<?= (int) $i ?>][include_in_rotation]" value="1" <?= !empty($it['include_in_rotation']) ? 'checked' : '' ?>> Rotation</label></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="flex gap-3 mt-4">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save template</button>
            <a href="<?= Security::escape($base) ?>/menu-planner.php" class="at-btn at-btn--secondary at-focusable">Back</a>
        </div>
    </div>
</form>
<?php admin_layout_end(); ?>
