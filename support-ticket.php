<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
    exit;
}

$customerId = CustomerAuth::id();
$ticketNumber = isset($_GET['ticket']) && is_string($_GET['ticket']) ? trim($_GET['ticket']) : '';
$err = '';
$msg = '';

if ($customerId === null || !CommerceGateway::useDatabase() || $ticketNumber === '') {
    header('Location: ' . url_base() . '/my-support.php', true, 302);
    exit;
}

$ticket = SupportTicketService::byNumberForCustomer($ticketNumber, $customerId);
if ($ticket === null) {
    layout_public_start(['pageTitle' => 'Ticket — Veggie Tokri', 'activeNav' => 'account']);
    $emptyTitle = 'Ticket not found';
    $emptyMessage = 'This ticket does not belong to your account.';
    $emptyActionHref = url_base() . '/my-support.php';
    $emptyActionLabel = 'My tickets';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$ticketId = (int) $ticket['id'];
$orderNumber = '';
if (!empty($ticket['order_id'])) {
    Install::ensureConnection();
    $oStmt = Database::pdo()->prepare('SELECT order_number FROM orders WHERE id = ? AND customer_id = ? LIMIT 1');
    $oStmt->execute([(int) $ticket['order_id'], $customerId]);
    $orderNumber = (string) ($oStmt->fetchColumn() ?: '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $attachment = isset($_FILES['attachment']) ? $_FILES['attachment'] : null;
    $result = SupportTicketService::customerReply(
        $ticketId,
        $customerId,
        (string) ($_POST['message'] ?? ''),
        $attachment
    );
    if ($result['ok']) {
        $msg = 'Reply sent.';
    } else {
        $err = $result['message'] ?? 'Could not send reply.';
    }
}

$messages = SupportTicketService::messages($ticketId);
$base = url_base();
$statusLabel = ucwords(str_replace('_', ' ', (string) $ticket['status']));
$catLabel = SupportTicketService::categoryLabels()[(string) $ticket['category']] ?? (string) $ticket['category'];

layout_public_start(['pageTitle' => $ticketNumber . ' — Support', 'activeNav' => 'account']);
$accountActive = 'support';
$accountTitle = $ticketNumber;
$accountSub = (string) ($ticket['subject'] ?? '');
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
?>
<p class="text-sm"><a class="at-link-arrow !text-sm at-focusable" href="<?= Security::escape($base) ?>/my-support.php">← All tickets</a></p>
<?php if ($msg !== ''): ?><div class="at-alert at-alert--success mt-3" role="status"><span><?= Security::escape($msg) ?></span></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="at-alert at-alert--error mt-3" role="alert"><span><?= Security::escape($err) ?></span></div><?php endif; ?>

<section class="at-card mt-4">
    <div class="at-card__body">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="at-h3"><?= Security::escape((string) $ticket['subject']) ?></h2>
                <p class="text-sm at-muted mt-1"><?= Security::escape($catLabel) ?></p>
            </div>
            <?= customer_status_badge((string) $ticket['status'], $statusLabel, 'ticket') ?>
        </div>
        <dl class="at-ticket-detail__grid mt-4 text-sm">
            <div><dt class="at-muted">Created</dt><dd class="font-semibold mt-0.5"><?= Security::escape(customer_format_datetime((string) $ticket['created_at'])) ?></dd></div>
            <div><dt class="at-muted">Last updated</dt><dd class="font-semibold mt-0.5"><?= Security::escape(customer_format_datetime((string) ($ticket['updated_at'] ?? $ticket['created_at']))) ?></dd></div>
            <?php if ($orderNumber !== ''): ?>
                <div class="sm:col-span-2"><dt class="at-muted">Linked order</dt><dd class="font-mono font-bold mt-0.5"><a class="underline" href="<?= Security::escape($base) ?>/order-details.php?id=<?= rawurlencode($orderNumber) ?>"><?= Security::escape($orderNumber) ?></a></dd></div>
            <?php endif; ?>
        </dl>
    </div>
</section>

<section class="at-card mt-4">
    <div class="at-card__body">
        <h2 class="at-h3">Conversation</h2>
        <div class="at-thread mt-4">
            <?php foreach ($messages as $m):
                $isTeam = ($m['sender_type'] ?? '') === 'admin';
                if (($m['sender_type'] ?? '') !== 'customer' && !$isTeam) {
                    continue;
                }
                ?>
                <div class="at-thread-msg <?= $isTeam ? 'at-thread-msg--team' : 'at-thread-msg--customer' ?>">
                    <div class="at-thread-msg__head">
                        <span><?= $isTeam ? 'Veggie Tokri support' : 'You' ?></span>
                        <time datetime="<?= Security::escape((string) $m['created_at']) ?>"><?= Security::escape(customer_format_datetime((string) $m['created_at'])) ?></time>
                    </div>
                    <div class="at-thread-msg__body"><?= nl2br(Security::escape((string) $m['message'])) ?></div>
                    <?php $attachments = SupportTicketService::attachmentsForMessage((int) $m['id']); ?>
                    <?php foreach ($attachments as $att): ?>
                        <p class="mt-2 text-sm"><a class="underline font-semibold at-focusable" href="<?= Security::escape($base) ?>/ajax/support/attachment.php?id=<?= (int) $att['id'] ?>">Download attachment</a></p>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ((string) $ticket['status'] !== TicketStatus::CLOSED): ?>
<form method="post" enctype="multipart/form-data" class="at-card mt-4">
    <div class="at-card__body space-y-3">
        <h2 class="at-h3">Your reply</h2>
        <?= Csrf::field() ?>
        <div>
            <label class="at-label" for="reply-message">Message</label>
            <textarea id="reply-message" name="message" class="at-textarea at-focusable" required rows="4" placeholder="Type your reply"></textarea>
        </div>
        <div>
            <label class="at-label" for="attachment">Attachment <span class="at-muted font-normal">(optional, image or PDF, max 5 MB)</span></label>
            <input id="attachment" type="file" name="attachment" accept="image/*,application/pdf" class="at-input at-focusable !py-2">
        </div>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Send reply</button>
    </div>
</form>
<?php else: ?>
    <p class="text-sm at-muted mt-4">This ticket is closed. Open a new ticket if you need further help.</p>
<?php endif; ?>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
