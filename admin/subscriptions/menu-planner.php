<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$base = url_base() . '/admin/subscriptions';
$esc = static function ($v): string {
    return Security::escape((string) $v);
};
$useDb = Install::isSchemaReady() && AdminAuth::check();

if ($useDb && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $act = isset($_POST['menu_plan_action']) ? (string) $_POST['menu_plan_action'] : '';
    $pid = isset($_POST['menu_plan_id']) ? (int) $_POST['menu_plan_id'] : 0;
    if ($pid > 0 && in_array($act, ['activate', 'deactivate', 'delete'], true)) {
        $res = $act === 'delete'
            ? SubscriptionMenuPlanService::delete($pid)
            : SubscriptionMenuPlanService::setActive($pid, $act === 'activate');
        admin_flash_set($res['message'], $res['ok'] ? 'success' : 'error');
    }
    header('Location: ' . $base . '/menu-planner.php', true, 303);
    exit;
}

admin_layout_start(['pageTitle' => 'Menu planner', 'activeAdminNav' => 'subscriptions']);
$pageHeading = 'Subscription Menu Planner';
$pageDescription = 'Decide which vegetables go into each delivery day of a subscription plan.';
$pageActionsHtml = '<a href="' . $esc($base) . '/menu-plan.php" class="at-btn at-btn--primary at-btn--sm at-focusable">Create menu plan</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$plans = $useDb ? SubscriptionMenuPlanService::listPlans() : [];
?>
<section class="at-card mb-4">
    <div class="at-card__body">
        <h3 class="font-bold mb-1">How it works</h3>
        <ol class="text-sm at-muted" style="list-style:decimal;padding-left:1.25rem">
            <li>Choose a plan length (7, 15, 30 or custom days).</li>
            <li>Add vegetables to each day.</li>
            <li>Review the whole menu.</li>
            <li>Save, and make it live for a subscription plan when you are ready.</li>
        </ol>
        <p class="text-sm at-muted mt-2">Menus you make live apply to new subscriptions started afterwards. Customers who already subscribed keep their saved baskets and menu.</p>
    </div>
</section>
<?php if (!$useDb): ?>
    <div class="at-alert at-alert--warning">The database is not available.</div>
<?php elseif ($plans === []): ?>
    <section class="at-card"><div class="at-card__body text-center" style="padding:2rem 1rem">
        <p class="font-bold">No menu plans yet</p>
        <p class="at-muted text-sm mt-1">The standard 7, 15 and 30-day menus keep working exactly as before. Create a menu plan to design a new one.</p>
        <p class="mt-3"><a class="at-btn at-btn--primary at-focusable" href="<?= $esc($base) ?>/menu-plan.php">Create your first menu plan</a></p>
    </div></section>
<?php else: ?>
    <div class="at-card"><div class="overflow-x-auto"><table class="at-table w-full">
        <thead><tr><th>Menu plan</th><th>Subscription plan</th><th>Duration</th><th>Days configured</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($plans as $p):
            $pid = (int) $p['id'];
            $dur = (int) $p['duration_days'];
            $conf = (int) $p['configured_days'];
            ?>
            <tr>
                <td><a class="font-semibold underline" href="<?= $esc($base) ?>/menu-plan.php?id=<?= $pid ?>&amp;step=3"><?= $esc($p['name']) ?></a></td>
                <td><?= $p['subscription_plan_name'] !== null ? $esc($p['subscription_plan_name']) : '<span class="at-muted">Not linked</span>' ?></td>
                <td><?= $dur ?> days</td>
                <td><?= $conf ?> of <?= $dur ?><?= $conf < $dur ? ' <span class="mp-badge mp-badge--partial">Incomplete</span>' : '' ?></td>
                <td><?= !empty($p['is_active']) ? '<span class="mp-badge mp-badge--ready">Live</span>' : '<span class="mp-badge mp-badge--not_configured">Draft</span>' ?></td>
                <td class="text-right">
                    <a class="at-btn at-btn--ghost at-btn--sm at-focusable" href="<?= $esc($base) ?>/menu-plan.php?id=<?= $pid ?>">Edit</a>
                    <form method="post" class="inline" style="display:inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="menu_plan_id" value="<?= $pid ?>">
                        <?php if (!empty($p['is_active'])): ?>
                            <button class="at-btn at-btn--ghost at-btn--sm at-focusable" name="menu_plan_action" value="deactivate" onclick="return confirm('Stop using this menu for new subscriptions?');">Deactivate</button>
                        <?php elseif ($p['subscription_plan_id'] !== null): ?>
                            <button class="at-btn at-btn--ghost at-btn--sm at-focusable" name="menu_plan_action" value="activate">Make live</button>
                        <?php endif; ?>
                        <?php if ($p['activated_at'] === null): ?>
                            <button class="at-btn at-btn--ghost at-btn--sm at-focusable" name="menu_plan_action" value="delete" onclick="return confirm('Delete this menu plan? This cannot be undone.');">Delete</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>
<p class="mt-4 text-sm at-muted">
    <a class="underline font-semibold" href="<?= $esc($base) ?>/menu-templates.php">Advanced day templates</a>
    ·
    <a class="underline font-semibold" href="<?= $esc($base) ?>/customer-baskets.php">Customer baskets</a>
    ·
    <a class="underline font-semibold" href="<?= $esc($base) ?>/planned-quantities.php">Planned quantities</a>
    ·
    <a class="underline font-semibold" href="<?= $esc($base) ?>/basket-override.php">Basket override</a>
</p>
<?php admin_layout_end(); ?>
