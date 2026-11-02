<?php

declare(strict_types=1);

/** @var array<string, mixed> $detail from SupportTicketService::adminDetailFor() */
/** @var int $ticketId */

require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

$ticket = $detail['ticket'];
$messages = $detail['messages'] ?? [];
$orderItems = $detail['order_items'] ?? [];
$labels = SupportTicketService::categoryLabels();
$categoryCode = (string) ($ticket['category'] ?? '');
$categoryLabel = SupportTicketService::categoryLabel($categoryCode);
$status = (string) ($ticket['status'] ?? '');
$statusLabel = ucwords(str_replace('_', ' ', $status));
$createdAt = (string) ($ticket['created_at'] ?? '');
$updatedAt = (string) ($ticket['updated_at'] ?? $createdAt);
$orderNumber = (string) ($ticket['order_number'] ?? '');
$orderInternalId = (int) ($ticket['order_id'] ?? 0);
$adminBase = url_base() . '/admin';
?>
<div class="grid lg:grid-cols-3 gap-4 items-start">
    <div class="lg:col-span-2 space-y-4">
        <div class="at-card">
            <div class="at-card__body text-sm grid gap-2 sm:grid-cols-2">
                <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-2">
                    <p class="font-mono font-bold text-base"><?= Security::escape((string) ($ticket['ticket_number'] ?? '')) ?></p>
                    <span class="at-status"><?= Security::escape($statusLabel) ?></span>
                </div>
                <div><span class="at-muted">Subject</span><br><strong><?= Security::escape((string) ($ticket['subject'] ?? '')) ?></strong></div>
                <div><span class="at-muted">Category</span><br><?= Security::escape($categoryLabel) ?> <span class="text-xs at-muted">(<?= Security::escape($categoryCode) ?>)</span></div>
                <div><span class="at-muted">Created</span><br><?= Security::escape($createdAt) ?></div>
                <div><span class="at-muted">Last updated</span><br><?= Security::escape($updatedAt) ?></div>
            </div>
        </div>

        <div class="at-card">
            <div class="at-card__body text-sm">
                <h3 class="font-bold mb-3">Customer</h3>
                <?php if (!empty($ticket['customer_id'])): ?>
                    <dl class="grid sm:grid-cols-2 gap-2">
                        <div><dt class="at-muted">Name</dt><dd><?= Security::escape((string) ($ticket['customer_name'] ?? '—')) ?></dd></div>
                        <div><dt class="at-muted">Phone</dt><dd><?= Security::escape((string) ($ticket['customer_mobile'] ?? '—')) ?></dd></div>
                        <div class="sm:col-span-2"><dt class="at-muted">Email</dt><dd><?= Security::escape((string) ($ticket['customer_email'] ?? '—')) ?></dd></div>
                    </dl>
                    <?php if (AdminDemoPerms::can('customers')): ?>
                        <p class="mt-3 text-xs"><a class="underline at-focusable" href="<?= Security::escape($adminBase) ?>/customers/index.php">Customer directory</a> (customer #<?= (int) $ticket['customer_id'] ?>)</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="at-muted">Guest ticket — no customer account linked.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="at-card">
            <div class="at-card__body text-sm">
                <h3 class="font-bold mb-3">Linked order</h3>
                <?php if ($orderInternalId > 0 && $orderNumber !== ''): ?>
                    <dl class="grid sm:grid-cols-2 gap-2">
                        <div><dt class="at-muted">Order</dt><dd class="font-mono font-bold"><?= Security::escape($orderNumber) ?></dd></div>
                        <div><dt class="at-muted">Order date</dt><dd><?= Security::escape((string) ($ticket['order_placed_at'] ?? $ticket['order_created_at'] ?? '—')) ?></dd></div>
                        <div><dt class="at-muted">Order status</dt><dd><?= Security::escape(ucwords(str_replace('_', ' ', (string) ($ticket['order_status'] ?? '')))) ?></dd></div>
                        <div><dt class="at-muted">Payment</dt><dd><?= Security::escape(ucwords(str_replace('_', ' ', (string) ($ticket['payment_status'] ?? '')))) ?></dd></div>
                        <div><dt class="at-muted">Gateway</dt><dd><?= Security::escape((string) ($ticket['payment_gateway'] ?? '—')) ?></dd></div>
                        <div><dt class="at-muted">Total</dt><dd class="font-bold"><?= Security::escape(catalogue_format_price((float) ($ticket['grand_total'] ?? 0))) ?></dd></div>
                        <?php if ((string) ($ticket['delivery_slot'] ?? '') !== '' || (string) ($ticket['delivery_date'] ?? '') !== ''): ?>
                            <div class="sm:col-span-2"><dt class="at-muted">Delivery</dt><dd><?= Security::escape((string) ($ticket['delivery_date'] ?? '')) ?> <?= Security::escape((string) ($ticket['delivery_slot'] ?? '')) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <?php if (AdminDemoPerms::can('orders')): ?>
                        <p class="mt-3"><a class="underline at-focusable text-sm" href="<?= Security::escape($adminBase) ?>/orders/view.php?id=<?= $orderInternalId ?>">Open order in admin</a></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="at-muted">No linked order — general enquiry.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($orderItems !== []): ?>
            <div class="at-card">
                <div class="at-card__body">
                    <h3 class="font-bold mb-3">Order items</h3>
                    <ul class="divide-y divide-[var(--at-line)]">
                        <?php foreach ($orderItems as $item): ?>
                            <li class="py-3 flex gap-3 items-start">
                                <?php if (($item['image'] ?? '') !== ''): ?>
                                    <img src="<?= Security::escape((string) $item['image']) ?>" alt="" class="w-12 h-12 rounded-lg object-cover bg-[var(--at-cream-2)] flex-shrink-0" width="48" height="48">
                                <?php else: ?>
                                    <span class="w-12 h-12 rounded-lg bg-[var(--at-cream-2)] flex-shrink-0" aria-hidden="true"></span>
                                <?php endif; ?>
                                <div class="min-w-0 flex-1 text-sm">
                                    <p class="font-semibold"><?= Security::escape((string) $item['name']) ?></p>
                                    <?php if ((string) ($item['variant'] ?? '') !== ''): ?>
                                        <p class="text-xs at-muted"><?= Security::escape((string) $item['variant']) ?></p>
                                    <?php endif; ?>
                                    <p class="mt-1">Qty <?= (int) $item['quantity'] ?> · <?= Security::escape(catalogue_format_price((float) $item['unit_price'])) ?> each · <?= Security::escape(catalogue_format_price((float) $item['line_total'])) ?></p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="at-card">
            <div class="at-card__body space-y-3">
                <h3 class="font-bold">Conversation</h3>
                <?php if ($messages === []): ?>
                    <p class="text-sm at-muted">No messages.</p>
                <?php else: ?>
                    <?php foreach ($messages as $m):
                        $sender = (string) ($m['sender_type'] ?? '');
                        $isAdmin = $sender === 'admin';
                        ?>
                        <div class="p-3 rounded-lg <?= $isAdmin ? 'bg-[var(--at-cream-2)]' : 'border border-[var(--at-line)]' ?>">
                            <p class="text-xs at-muted"><?= $isAdmin ? 'Support team' : 'Customer' ?> · <?= Security::escape((string) ($m['created_at'] ?? '')) ?></p>
                            <p class="text-sm mt-1"><?= nl2br(Security::escape((string) ($m['message'] ?? ''))) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        <form method="post" class="at-card">
            <div class="at-card__body space-y-3">
                <h3 class="font-bold text-sm">Reply</h3>
                <?= Csrf::field() ?>
                <input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>">
                <textarea name="message" class="at-textarea at-focusable" required placeholder="Reply to customer"></textarea>
                <label class="at-label" for="support-status">Update status</label>
                <select id="support-status" name="status" class="at-select at-focusable">
                    <option value="">Keep status</option>
                    <option value="<?= TicketStatus::IN_PROGRESS ?>">In progress</option>
                    <option value="<?= TicketStatus::RESOLVED ?>">Resolved</option>
                    <option value="<?= TicketStatus::CLOSED ?>">Closed</option>
                </select>
                <button type="submit" class="at-btn at-btn--primary at-focusable at-btn--block">Send reply</button>
            </div>
        </form>
        <p><a class="underline text-sm at-focusable" href="<?= Security::escape($adminBase) ?>/support/index.php">← All tickets</a></p>
    </div>
</div>
