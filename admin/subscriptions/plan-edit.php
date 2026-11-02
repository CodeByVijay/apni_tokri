<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('subscriptions');
$useDb = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$plan = ($useDb && $id) ? SubscriptionPlanRepository::find($id) : null;
$errors = [];
$previewDates = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$useDb) {
        admin_flash_set('Plan saved (demo UI).');
        header('Location: ' . url_base() . '/admin/subscriptions/index.php', true, 302);
        exit;
    }
    $postId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    $result = SubscriptionPlanRepository::save($postId, $_POST);
    if (!$result['ok']) {
        $errors = $result['errors'] ?? ['Save failed.'];
        $plan = array_merge($plan ?? [], $_POST);
    } else {
        admin_flash_set('Subscription plan saved.');
        header('Location: ' . url_base() . '/admin/subscriptions/plan-edit.php?id=' . (int) $result['id'], true, 302);
        exit;
    }
}

if ($useDb && $plan) {
    $basket = json_decode((string) ($plan['basket_rules_json'] ?? '{}'), true) ?: [];
    $skip = json_decode((string) ($plan['skip_pause_policy_json'] ?? '{}'), true) ?: [];
    $valid = SubscriptionPlanValidator::validate(SubscriptionPlanValidator::normalizeInput(array_merge($plan, [
        'daily_allowance_inr' => $basket['daily_allowance_inr'] ?? 499,
        'max_line_items' => $basket['max_line_items'] ?? 10,
        'unused_allowance_policy' => $basket['unused_allowance_policy'] ?? 'expire',
        'over_allowance_requires_approval' => $basket['over_allowance_requires_approval'] ?? true,
        'max_skips' => $skip['max_skips'] ?? 2,
        'skip_cutoff_hours' => $skip['skip_cutoff_hours'] ?? 18,
        'pause_allowed' => $skip['pause_allowed'] ?? 1,
        'carry_forward_skips' => $skip['carry_forward_skips'] ?? 1,
        'preserve_remaining_on_pause' => $skip['preserve_remaining_on_pause'] ?? 1,
        'use_delivery_slots' => json_decode((string) ($plan['slot_policy_json'] ?? '{}'), true)['use_delivery_slots'] ?? 1,
    ])));
    $previewDates = $valid['preview_dates'] ?? [];
} elseif ($plan === null && !$useDb) {
    $plan = [];
}

$basket = is_array($plan) ? (json_decode((string) ($plan['basket_rules_json'] ?? '{}'), true) ?: []) : [];
$skip = is_array($plan) ? (json_decode((string) ($plan['skip_pause_policy_json'] ?? '{}'), true) ?: []) : [];

admin_layout_start(['pageTitle' => $id ? 'Edit plan' : 'New plan', 'activeAdminNav' => 'subscriptions']);
admin_flash_render();
$pageHeading = $id ? 'Edit subscription plan' : 'New subscription plan';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if ($errors !== []): ?>
    <div class="at-alert at-alert--error mb-4"><ul class="list-disc pl-5"><?php foreach ($errors as $e): ?><li><?= Security::escape($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="at-card max-w-3xl">
    <div class="at-card__body at-admin-form-grid at-admin-form-grid--2">
        <?= Csrf::field() ?>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="md:col-span-2"><label class="at-label">Plan name</label><input name="name" class="at-input at-focusable" required value="<?= Security::escape((string) ($plan['name'] ?? '')) ?>"></div>
        <div class="md:col-span-2"><label class="at-label">Description</label><textarea name="description" class="at-input at-focusable" rows="2"><?= Security::escape((string) ($plan['description'] ?? '')) ?></textarea></div>
        <div><label class="at-label">Calendar duration (days)</label><input name="calendar_duration_days" type="number" min="1" class="at-input at-focusable" value="<?= (int) ($plan['calendar_duration_days'] ?? 14) ?>"></div>
        <div><label class="at-label">Total delivery count</label><input name="delivery_count" type="number" min="1" class="at-input at-focusable" value="<?= (int) ($plan['delivery_count'] ?? 7) ?>"></div>
        <div><label class="at-label">Frequency</label><select name="frequency_type" class="at-select at-focusable">
            <?php foreach (['daily' => 'Daily', 'alternate' => 'Alternate days', 'weekdays' => 'Selected weekdays', 'custom_interval' => 'Custom interval'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= ($plan['frequency_type'] ?? 'daily') === $k ? 'selected' : '' ?>><?= Security::escape($lbl) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div><label class="at-label">Custom interval (days)</label><input name="interval_days" type="number" min="1" class="at-input at-focusable" value="1"></div>
        <div><label class="at-label">Price (₹)</label><input name="price" type="number" step="0.01" min="0" class="at-input at-focusable" value="<?= Security::escape((string) ($plan['price'] ?? '999')) ?>"></div>
        <div><label class="at-label">Compare at (₹)</label><input name="compare_at_price" type="number" step="0.01" class="at-input at-focusable" value="<?= Security::escape((string) ($plan['compare_at_price'] ?? '')) ?>"></div>
        <div><label class="at-label">Daily allowance (₹)</label><input name="daily_allowance_inr" type="number" step="0.01" class="at-input at-focusable" value="<?= Security::escape((string) ($basket['daily_allowance_inr'] ?? 499)) ?>"></div>
        <div><label class="at-label">Max line items / day</label><input name="max_line_items" type="number" min="1" class="at-input at-focusable" value="<?= (int) ($basket['max_line_items'] ?? 10) ?>"></div>
        <div><label class="at-label">Unused allowance</label><select name="unused_allowance_policy" class="at-select at-focusable">
            <option value="expire" <?= ($basket['unused_allowance_policy'] ?? '') === 'expire' ? 'selected' : '' ?>>Expires</option>
            <option value="carry_forward" <?= ($basket['unused_allowance_policy'] ?? '') === 'carry_forward' ? 'selected' : '' ?>>Carry forward</option>
        </select></div>
        <div class="md:col-span-2 flex flex-wrap gap-4 text-sm">
            <label class="flex gap-2"><input type="checkbox" name="over_allowance_requires_approval" value="1" <?= !empty($basket['over_allowance_requires_approval']) ? 'checked' : '' ?>> Over-allowance requires customer approval</label>
            <label class="flex gap-2"><input type="checkbox" name="pause_allowed" value="1" <?= !empty($skip['pause_allowed']) ? 'checked' : '' ?>> Pause allowed</label>
            <label class="flex gap-2"><input type="checkbox" name="preserve_remaining_on_pause" value="1" <?= !empty($skip['preserve_remaining_on_pause']) ? 'checked' : '' ?>> Preserve remaining deliveries on pause</label>
            <label class="flex gap-2"><input type="checkbox" name="use_delivery_slots" value="1" checked> Use delivery slots</label>
            <label class="flex gap-2"><input type="checkbox" name="is_active" value="1" <?= !empty($plan['is_active']) ? 'checked' : '' ?>> Active (published)</label>
        </div>
        <div><label class="at-label">Selection cutoff (hours before delivery)</label><input name="selection_cutoff_hours" type="number" min="1" class="at-input at-focusable" value="<?= (int) ($plan['selection_cutoff_hours'] ?? 18) ?>"></div>
        <div><label class="at-label">Sort order</label><input name="sort_order" type="number" class="at-input at-focusable" value="<?= (int) ($plan['sort_order'] ?? 0) ?>"></div>
        <div class="md:col-span-2 flex gap-3">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save plan</button>
            <a href="<?= Security::escape(url_base()) ?>/admin/subscriptions/index.php" class="at-btn at-btn--secondary at-focusable">Back</a>
        </div>
    </div>
</form>

<?php if ($previewDates !== []): ?>
    <div class="at-card max-w-3xl mt-4"><div class="at-card__body">
        <h3 class="font-bold text-sm">Schedule preview (sample start +1 day)</h3>
        <p class="text-xs at-muted mt-1"><?= count($previewDates) ?> dates — <?= Security::escape(implode(', ', array_slice($previewDates, 0, 8))) ?><?= count($previewDates) > 8 ? '…' : '' ?></p>
    </div></div>
<?php endif; ?>
<?php admin_layout_end(); ?>
