<?php

declare(strict_types=1);

/**
 * Subscription Menu Planner wizard: Choose Your Plan -> Build Daily Menus -> Review -> Save.
 * GET  ?id=N [&step=3]   edit / review an existing menu plan
 * GET  ?id=N&saved=1     success screen
 * POST payload=<json>    server-side validation and transactional save
 */
require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('subscriptions');

$base = url_base() . '/admin/subscriptions';
$esc = static function ($v): string {
    return Security::escape((string) $v);
};
$ready = Install::isSchemaReady() && AdminAuth::check();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
if ($id !== null && $id <= 0) {
    $id = null;
}
$errors = [];
$initial = null;
$draftFromPost = null;

if ($ready && $id !== null) {
    $initial = SubscriptionMenuPlanService::load($id);
    if ($initial === null) {
        admin_flash_set('Menu plan not found.', 'error');
        header('Location: ' . $base . '/menu-planner.php', true, 302);
        exit;
    }
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $raw = isset($_POST['payload']) && is_string($_POST['payload']) ? $_POST['payload'] : '';
    $payload = json_decode($raw, true);
    if (!is_array($payload) || strlen($raw) > 4000000) {
        $errors = ['The menu data could not be read. Please try saving again.'];
    } else {
        $result = SubscriptionMenuPlanService::save($id, $payload);
        if ($result['ok']) {
            header('Location: ' . $base . '/menu-plan.php?id=' . (int) $result['id'] . '&saved=1', true, 302);
            exit;
        }
        $errors = $result['errors'] ?? ['Could not save the menu plan.'];
        $draftFromPost = $payload;
    }
}

$plans = [];
if ($ready) {
    foreach (SubscriptionPlanRepository::allForAdmin() as $p) {
        $plans[] = [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'template_key' => (string) ($p['template_key'] ?? ''),
            'delivery_count' => (int) $p['delivery_count'],
        ];
    }
}

// Success screen
if ($ready && $initial !== null && isset($_GET['saved']) && $_GET['saved'] === '1' && $errors === []) {
    admin_layout_start(['pageTitle' => 'Menu plan saved', 'activeAdminNav' => 'subscriptions']);
    $pageHeading = 'Subscription Menu Planner';
    $pageDescription = '';
    require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
    ?>
    <section class="at-card" role="status">
        <div class="at-card__body text-center" style="padding:2rem 1rem">
            <div class="mp-success__icon" aria-hidden="true">✓</div>
            <h3 class="text-xl font-extrabold mt-2">Menu plan saved</h3>
            <p class="mt-2"><strong><?= $esc($initial['name']) ?></strong> · <?= (int) $initial['duration_days'] ?>-day plan</p>
            <p class="at-muted mt-1"><?= (int) $initial['duration_days'] ?> days saved · <?= (int) $initial['configured_days'] ?> with vegetables · <?= (int) $initial['total_entries'] ?> vegetable entries</p>
            <p class="at-muted text-sm mt-1"><?= !empty($initial['is_active']) ? 'This menu is live for new ' . $esc($initial['subscription_plan_name'] ?? 'subscription') . ' subscriptions.' : 'Saved as a draft. It is not used for customer baskets until you make it live.' ?></p>
            <div class="mt-5 flex flex-wrap gap-2 justify-center">
                <a class="at-btn at-btn--primary at-focusable" href="<?= $esc($base) ?>/menu-plan.php?id=<?= (int) $id ?>&amp;step=3">View Menu Plan</a>
                <a class="at-btn at-btn--secondary at-focusable" href="<?= $esc($base) ?>/menu-plan.php?id=<?= (int) $id ?>">Edit Menu Plan</a>
                <a class="at-btn at-btn--secondary at-focusable" href="<?= $esc($base) ?>/menu-plan.php">Create Another Menu</a>
            </div>
            <p class="mt-4 text-sm"><a class="underline" href="<?= $esc($base) ?>/menu-planner.php">Back to all menu plans</a></p>
        </div>
    </section>
    <?php
    admin_layout_end();
    exit;
}

$config = [
    'plans' => $plans,
    'searchUrl' => url_base() . '/admin/ajax/menu-variant-search.php',
    'saveUrl' => $base . '/menu-plan.php' . ($id !== null ? '?id=' . $id : ''),
    'listUrl' => $base . '/menu-planner.php',
    'csrf' => Csrf::token(),
    'csrfName' => Csrf::fieldName(),
    'errors' => $errors,
    'step' => 1,
    'initial' => null,
];
if ($initial !== null) {
    $config['initial'] = [
        'id' => (int) $initial['id'],
        'name' => (string) $initial['name'],
        'duration_days' => (int) $initial['duration_days'],
        'subscription_plan_id' => $initial['subscription_plan_id'] !== null ? (int) $initial['subscription_plan_id'] : null,
        'repeat_mode' => (string) $initial['repeat_mode'],
        'repeat_every' => $initial['repeat_every'] !== null ? (int) $initial['repeat_every'] : null,
        'is_active' => !empty($initial['is_active']),
        'days' => $initial['days'],
    ];
    $config['step'] = isset($_GET['step']) && in_array((int) $_GET['step'], [2, 3], true) ? (int) $_GET['step'] : 2;
}
if ($draftFromPost !== null) {
    // Re-open the wizard at the Save step with the admin's unsaved draft so nothing is lost.
    $dur = (int) ($draftFromPost['duration_days'] ?? 0);
    if ($dur >= 1 && $dur <= SubscriptionMenuPlanService::MAX_DAYS) {
        $days = [];
        $ids = [];
        foreach (($draftFromPost['days'] ?? []) as $dk => $dv) {
            foreach (($dv['items'] ?? []) as $it) {
                $ids[(int) ($it['variant_id'] ?? 0)] = true;
            }
        }
        $meta = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Database::pdo()->prepare(
                'SELECT v.id, v.name, v.sku, v.price, v.sale_price, v.stock_qty, v.is_active, p.id AS product_id, p.name AS product_name,
                        p.status AS product_status,
                        (SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image_path
                 FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.id IN (' . $in . ')'
            );
            $stmt->execute(array_keys($ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['variant_name'] = $r['name'];
                $meta[(int) $r['id']] = SubscriptionMenuPlanService::decorateVariant($r);
            }
        }
        for ($d = 1; $d <= $dur; $d++) {
            $src = $draftFromPost['days'][$d] ?? $draftFromPost['days'][(string) $d] ?? [];
            $lines = [];
            foreach (($src['items'] ?? []) as $it) {
                $m = $meta[(int) ($it['variant_id'] ?? 0)] ?? null;
                if ($m === null) {
                    continue;
                }
                $lines[] = [
                    'variant_id' => $m['variant_id'], 'product_name' => $m['product_name'], 'variant_name' => $m['variant_name'],
                    'unit_label' => $m['unit_label'], 'price' => $m['price'], 'stock_qty' => $m['stock_qty'],
                    'available' => $m['available'], 'in_stock' => $m['in_stock'], 'image_url' => $m['image_url'],
                    'qty' => (float) ($it['qty'] ?? 1),
                    'max_qty' => isset($it['max_qty']) && $it['max_qty'] !== '' && $it['max_qty'] !== null ? (float) $it['max_qty'] : null,
                ];
            }
            $days[$d] = ['customized' => !empty($src['customized']), 'items' => $lines];
        }
        $config['initial'] = [
            'id' => $id,
            'name' => (string) ($draftFromPost['name'] ?? ''),
            'duration_days' => $dur,
            'subscription_plan_id' => !empty($draftFromPost['subscription_plan_id']) ? (int) $draftFromPost['subscription_plan_id'] : null,
            'repeat_mode' => (string) ($draftFromPost['repeat_mode'] ?? 'none'),
            'repeat_every' => !empty($draftFromPost['repeat_every']) ? (int) $draftFromPost['repeat_every'] : null,
            'is_active' => !empty($draftFromPost['is_active']),
            'days' => $days,
        ];
        $config['step'] = 4;
    }
}

admin_layout_start(['pageTitle' => 'Menu planner', 'activeAdminNav' => 'subscriptions']);
$pageHeading = $id !== null ? 'Edit menu plan' : 'New menu plan';
$pageDescription = 'Plan which vegetables go into each delivery day, then review and save.';
$pageActionsHtml = '<a href="' . $esc($base) . '/menu-planner.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">All menu plans</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

if (!$ready): ?>
    <div class="at-alert at-alert--warning">The menu planner needs the database. Sign in and make sure the schema is installed.</div>
<?php else: ?>
    <div id="menu-planner-root" class="mp-root" data-config="<?= $esc(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
        <noscript><div class="at-alert at-alert--warning">JavaScript is required for the menu planner wizard.</div></noscript>
    </div>
    <script src="<?= $esc(asset_url('assets/js/menu-planner-ops.js')) ?>"></script>
    <script src="<?= $esc(asset_url('assets/js/menu-planner.js')) ?>"></script>
<?php endif;
admin_layout_end();
