<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$orderId = isset($_GET['order']) && is_string($_GET['order']) ? trim($_GET['order']) : '';
$msg = '';
$err = '';
$customerOrders = [];
if (CustomerAuth::check() && CommerceGateway::useDatabase() && CustomerAuth::id() !== null) {
    $orderPage = OrderRepository::listForCustomer((int) CustomerAuth::id(), 1, 25);
    $customerOrders = $orderPage['rows'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $customerId = CustomerAuth::check() ? CustomerAuth::id() : null;
    $orderNumber = isset($_POST['order_id']) ? trim((string) $_POST['order_id']) : null;
    if ($orderNumber === '') {
        $orderNumber = null;
    }
    $result = SupportTicketService::create(
        $customerId,
        (string) ($_POST['category'] ?? 'other'),
        (string) ($_POST['subject'] ?? ''),
        (string) ($_POST['message'] ?? ''),
        $orderNumber
    );
    if ($result['ok']) {
        $msg = 'Ticket ' . ($result['ticket_number'] ?? '') . ' created. Our team will respond soon.';
    } else {
        $err = $result['message'] ?? 'Could not create ticket.';
    }
}

$base = url_base();
$linkedOrder = $orderId !== '' ? $orderId : '';

layout_public_start(['pageTitle' => 'Support — Veggie Tokri', 'activeNav' => 'account']);
$accountActive = 'support';
$accountTitle = 'Support';
$accountSub = 'Tell us what happened — we’ll get back to you. WhatsApp and phone are configured in admin.';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<?php if ($msg !== ''): ?>
    <div class="at-alert at-alert--success" role="status"><?= at_icon('check', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($msg) ?></span></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <div class="at-alert at-alert--error" role="alert"><span><?= Security::escape($err) ?></span></div>
<?php endif; ?>
<div class="grid xl:grid-cols-[1fr_20rem] gap-5 items-start">
    <form method="post" class="at-card">
        <div class="at-card__body grid gap-4">
            <h2 class="at-h3">Open a ticket</h2>
            <?= Csrf::field() ?>
            <div>
                <label class="at-label" for="category">Issue type</label>
                <select id="category" name="category" class="at-select at-focusable" required>
                    <?php
                    $ticketCategories = SupportTicketService::categoryLabelsForCustomer(
                        CustomerAuth::check() ? CustomerAuth::id() : null
                    );
                    foreach ($ticketCategories as $code => $label):
                    ?>
                        <option value="<?= Security::escape($code) ?>"><?= Security::escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($linkedOrder !== ''): ?>
                <input type="hidden" name="order_id" value="<?= Security::escape($linkedOrder) ?>">
                <div class="at-card at-card--tint !shadow-none">
                    <div class="at-card__body !py-3 text-sm flex items-center gap-2">
                        <?= at_icon('receipt', 'w-4 h-4 text-[var(--at-green)]') ?>
                        <span>Linked order: <span class="font-mono font-bold"><?= Security::escape($linkedOrder) ?></span></span>
                    </div>
                </div>
            <?php elseif ($customerOrders !== []): ?>
                <div>
                    <label class="at-label" for="order_id">Related order <span class="at-muted font-normal">(optional)</span></label>
                    <select id="order_id" name="order_id" class="at-select at-focusable">
                        <option value="">Not related to a specific order</option>
                        <?php foreach ($customerOrders as $o): ?>
                            <option value="<?= Security::escape((string) ($o['id'] ?? '')) ?>"><?= Security::escape((string) ($o['id'] ?? '')) ?> · <?= Security::escape(catalogue_format_price((float) (($o['totals']['total'] ?? 0)))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <label class="at-label" for="subject">Subject <span class="at-muted font-normal">(optional)</span></label>
                <input id="subject" name="subject" class="at-input at-focusable" maxlength="191" placeholder="Short summary of the issue">
            </div>
            <div>
                <label class="at-label" for="message">Message</label>
                <textarea id="message" name="message" class="at-textarea at-focusable" required rows="5" placeholder="Describe the issue — include the item name if it’s about a product."></textarea>
            </div>
            <div><button type="submit" class="at-btn at-btn--primary at-focusable">Open ticket</button></div>
        </div>
    </form>
    <div class="grid gap-4">
        <div class="at-card"><div class="at-card__body">
            <h2 class="at-h3">How tickets progress</h2>
            <ol class="at-timeline mt-4 text-sm">
                <li class="is-done"><strong>Open</strong><span class="block at-muted text-xs">You raise the issue</span></li>
                <li><strong>Assigned</strong><span class="block at-muted text-xs">A team member picks it up</span></li>
                <li><strong>In progress</strong><span class="block at-muted text-xs">We investigate or arrange a fix</span></li>
                <li><strong>Resolved</strong><span class="block at-muted text-xs">You get the outcome</span></li>
            </ol>
        </div></div>
        <div class="at-card at-card--tint"><div class="at-card__body text-sm">
            <p class="font-bold">Faster answers</p>
            <p class="at-muted mt-1">Check the <a class="underline font-semibold" href="<?= Security::escape($base) ?>/faqs.php">FAQs</a> or the <a class="underline font-semibold" href="<?= Security::escape($base) ?>/page.php?slug=refund-policy">refund policy</a> first.</p>
            <?php if (CustomerService::isLoggedIn()): ?>
                <p class="mt-2"><a class="at-link-arrow !text-sm" href="<?= Security::escape($base) ?>/my-support.php">View my tickets</a></p>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
