<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('testimonials');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'moderate') {
        $result = TestimonialService::moderate(
            (int) ($_POST['testimonial_id'] ?? 0),
            (string) ($_POST['moderate_action'] ?? ''),
            (string) ($_POST['reject_reason'] ?? '')
        );
    } else {
        $result = TestimonialService::saveAdmin(
            isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null,
            (string) ($_POST['display_name'] ?? ''),
            (string) ($_POST['body'] ?? ''),
            !empty($_POST['consent']),
            (string) ($_POST['consent_note'] ?? ''),
            !empty($_POST['featured']),
            (int) ($_POST['sort_order'] ?? 0)
        );
    }
    admin_flash_set($result['ok'] ? 'Saved.' : ($result['message'] ?? 'Failed.'), $result['ok'] ? 'success' : 'error');
    header('Location: ' . url_base() . '/admin/testimonials/index.php');
    exit;
}

admin_layout_start(['pageTitle' => 'Testimonials', 'activeAdminNav' => 'testimonials']);
admin_flash_render();
$pageHeading = 'Testimonials';
$pageDescription = 'Consent required before publication. No fake testimonials.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<form method="post" class="at-card">
    <div class="at-card__body space-y-3">
        <?= Csrf::field() ?>
        <div><label class="at-label">Customer display name</label><input name="display_name" class="at-input at-focusable" required></div>
        <div><label class="at-label">Story</label><textarea name="body" class="at-textarea at-focusable" rows="4" required></textarea></div>
        <div><label class="at-label">Consent note</label><input name="consent_note" class="at-input at-focusable" placeholder="Phone/email reference for consent record"></div>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="consent" required> Customer consent recorded</label>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="featured"> Feature on homepage</label>
        <div><label class="at-label">Sort order</label><input type="number" name="sort_order" value="0" class="at-input at-focusable w-24"></div>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save (pending approval)</button>
    </div>
</form>
<?php if (Install::isSchemaReady()): ?>
<div class="at-card mt-6"><div class="at-card__body">
    <h3 class="at-h3">Pending approval</h3>
    <ul class="mt-3 space-y-3">
        <?php foreach (TestimonialService::listForAdmin(ReviewStatus::PENDING, 20) as $t): ?>
            <li class="border border-[var(--at-line)] rounded-lg p-3 text-sm">
                <strong><?= Security::escape((string) $t['customer_display_name']) ?></strong>
                <p class="mt-1"><?= Security::escape((string) $t['body']) ?></p>
                <form method="post" class="mt-2 flex flex-wrap gap-2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="moderate">
                    <input type="hidden" name="testimonial_id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" name="moderate_action" value="approve" class="at-btn at-btn--primary at-btn--sm">Approve</button>
                    <input type="text" name="reject_reason" placeholder="Reject reason" class="at-input at-btn--sm">
                    <button type="submit" name="moderate_action" value="reject" class="at-btn at-btn--secondary at-btn--sm">Reject</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
</div></div>
<?php endif; ?>
<?php admin_layout_end(); ?>
