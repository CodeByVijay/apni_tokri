<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
    exit;
}

$orderId = isset($_GET['order']) && is_string($_GET['order']) ? trim($_GET['order']) : '';
$msg = '';
$err = '';
$eligible = [];
if ($orderId !== '' && CommerceGateway::useDatabase() && CustomerAuth::id() !== null) {
    $eligible = ReviewService::eligibleOrderItems(CustomerAuth::id(), $orderId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!CommerceGateway::useDatabase() || CustomerAuth::id() === null) {
        $err = 'Reviews require a delivered order from your account.';
    } else {
        $result = ReviewService::submit(
            CustomerAuth::id(),
            (int) ($_POST['order_item_id'] ?? 0),
            (int) ($_POST['rating'] ?? 0),
            (string) ($_POST['review'] ?? ''),
            null,
            isset($_FILES['photos']) ? $_FILES['photos'] : null
        );
        if ($result['ok']) {
            $msg = (string) ($result['message'] ?? 'Review submitted for moderation.');
        } else {
            $err = (string) ($result['message'] ?? 'Could not submit review.');
        }
    }
}

layout_public_start(['pageTitle' => 'Submit review — Veggie Tokri', 'activeNav' => 'account']);
$accountActive = 'reviews';
$accountTitle = 'Write a review';
$accountSub = 'Reviews come from delivered orders only and appear after approval — good or critical.';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<?php if ($msg !== ''): ?>
    <div class="at-alert at-alert--success" role="status"><?= at_icon('check', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($msg) ?></span></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <div class="at-alert at-alert--error" role="status"><span><?= Security::escape($err) ?></span></div>
<?php endif; ?>
<?php if ($eligible === []): ?>
    <p class="text-sm at-muted">No reviewable items for this order. Open this page from a <strong>delivered</strong> order in <a class="underline" href="<?= Security::escape(url_base()) ?>/my-orders.php">My orders</a>.</p>
<?php else: ?>
<form method="post" class="at-card max-w-2xl" enctype="multipart/form-data">
    <div class="at-card__body grid gap-5">
        <?= Csrf::field() ?>
        <p class="text-sm flex items-center gap-2"><?= at_icon('receipt', 'w-4 h-4 text-[var(--at-green)]') ?>Order <span class="font-mono font-bold"><?= Security::escape($orderId) ?></span></p>
        <div>
            <label class="at-label" for="order_item_id">Product</label>
            <select id="order_item_id" name="order_item_id" class="at-select at-focusable" required>
                <?php foreach ($eligible as $item): ?>
                    <option value="<?= (int) $item['order_item_id'] ?>">
                        <?= Security::escape((string) $item['product_name']) ?> — <?= Security::escape((string) $item['variant_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <fieldset>
            <legend class="at-label">Your rating</legend>
            <div class="at-star-input" role="radiogroup" aria-label="Rating">
                <?php for ($r = 5; $r >= 1; $r--): ?>
                    <input type="radio" id="star<?= $r ?>" name="rating" value="<?= $r ?>" <?= $r === 5 ? 'checked' : '' ?>><label for="star<?= $r ?>" title="<?= $r ?> star<?= $r === 1 ? '' : 's' ?>">★</label>
                <?php endfor; ?>
            </div>
        </fieldset>
        <div>
            <label class="at-label" for="review">Your review</label>
            <textarea id="review" name="review" class="at-textarea at-focusable" required placeholder="What was the quality like? Was it fresh on arrival?"></textarea>
        </div>
        <div>
            <label class="at-label" for="photos">Photos <span class="at-muted font-normal">(optional, max 3)</span></label>
            <input id="photos" name="photos[]" type="file" accept="image/*" multiple class="at-input at-focusable !py-2">
        </div>
        <div><button type="submit" class="at-btn at-btn--primary at-focusable">Submit for approval</button></div>
    </div>
</form>
<?php endif; ?>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
