<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps(), true);

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
    exit;
}

$customerId = CustomerAuth::id();
if ($customerId === null || !CommerceGateway::useDatabase()) {
    layout_public_start(['pageTitle' => 'Support — Apni Tokri', 'activeNav' => 'account']);
    echo '<div class="at-container py-10"><p>Support inbox requires a signed-in account with live orders.</p></div>';
    layout_public_end();
    exit;
}

$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
$catFilter = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
$tickets = SupportTicketService::listForCustomer(
    $customerId,
    $statusFilter !== '' ? $statusFilter : null,
    $catFilter !== '' ? $catFilter : null
);

layout_public_start(['pageTitle' => 'My support tickets — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'support';
$accountTitle = 'Support tickets';
$accountSub = 'Track replies and continue conversations with our team.';
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
$base = url_base();
?>
<div class="at-cp-toolbar">
    <p class="text-sm at-muted m-0">Need help with an order? Open a ticket and we will reply here.</p>
    <a href="<?= Security::escape($base) ?>/support.php" class="at-btn at-btn--primary at-focusable"><?= at_icon('plus', 'w-4 h-4') ?>Open new ticket</a>
</div>

<form method="get" class="at-card at-cp-filters mt-4">
    <div class="at-card__body">
        <h2 class="at-h3 sr-only">Filter tickets</h2>
        <div class="at-cp-filters__grid">
            <div>
                <label class="at-label" for="status">Status</label>
                <select id="status" name="status" class="at-select at-focusable">
                    <option value="">All statuses</option>
                    <?php foreach ([TicketStatus::OPEN, TicketStatus::ASSIGNED, TicketStatus::IN_PROGRESS, TicketStatus::RESOLVED, TicketStatus::CLOSED] as $st): ?>
                        <option value="<?= Security::escape($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= Security::escape(ucwords(str_replace('_', ' ', $st))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="at-label" for="category">Category</label>
                <select id="category" name="category" class="at-select at-focusable">
                    <option value="">All categories</option>
                    <?php foreach (SupportTicketService::categoryLabels() as $code => $label): ?>
                        <option value="<?= Security::escape($code) ?>" <?= $catFilter === $code ? 'selected' : '' ?>><?= Security::escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="at-btn at-btn--secondary at-focusable">Apply filters</button>
        </div>
    </div>
</form>

<div class="at-card mt-4">
    <div class="at-card__body">
        <?php if ($tickets === []): ?>
            <?php
            $emptyTitle = 'No tickets yet';
            $emptyMessage = 'When you contact support, your conversations will appear here.';
            $emptyIcon = 'chat';
            $emptyActionHref = $base . '/support.php';
            $emptyActionLabel = 'Open a ticket';
            require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
            ?>
        <?php else: ?>
            <ul class="at-ticket-list">
                <?php foreach ($tickets as $t):
                    $catLabel = SupportTicketService::categoryLabels()[(string) $t['category']] ?? (string) $t['category'];
                    $orderNum = (string) ($t['order_number'] ?? '');
                    ?>
                    <li class="at-ticket-row">
                        <div class="min-w-0 flex-1">
                            <a class="at-ticket-row__title at-focusable" href="<?= Security::escape($base) ?>/support-ticket.php?ticket=<?= rawurlencode((string) $t['ticket_number']) ?>"><?= Security::escape((string) $t['ticket_number']) ?></a>
                            <p class="at-ticket-row__subject"><?= Security::escape((string) $t['subject']) ?></p>
                            <div class="at-ticket-row__meta">
                                <span><?= Security::escape($catLabel) ?></span>
                                <?php if ($orderNum !== ''): ?>
                                    <span>Order <?= Security::escape($orderNum) ?></span>
                                <?php endif; ?>
                                <span>Created <?= Security::escape(customer_format_datetime((string) $t['created_at'])) ?></span>
                                <span>Updated <?= Security::escape(customer_format_datetime((string) ($t['updated_at'] ?? $t['created_at']))) ?></span>
                            </div>
                        </div>
                        <div class="at-ticket-row__aside">
                            <?= customer_status_badge((string) $t['status'], ucwords(str_replace('_', ' ', (string) $t['status'])), 'ticket') ?>
                            <a href="<?= Security::escape($base) ?>/support-ticket.php?ticket=<?= rawurlencode((string) $t['ticket_number']) ?>" class="at-link-arrow !text-sm at-focusable">View conversation</a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
