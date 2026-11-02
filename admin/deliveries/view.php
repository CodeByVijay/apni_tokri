<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('deliveries');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$delivery = $id > 0 && Install::isSchemaReady() ? DeliveryAssignmentService::findById($id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $delivery !== null) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? 'update');
    if ($action === 'retry_sms') {
        $logId = (int) ($_POST['sms_log_id'] ?? 0);
        if ($logId > 0 && DeliverySmsService::retryLog($logId)) {
            admin_flash_set('SMS retry queued.');
        } else {
            admin_flash_set('Could not retry SMS.', 'error');
        }
    } else {
        $input = [
            'status' => (string) ($_POST['status'] ?? ''),
            'courier_service_name' => (string) ($_POST['courier_service_name'] ?? ''),
            'courier_other' => (string) ($_POST['courier_other'] ?? ''),
            'booking_reference' => (string) ($_POST['booking_reference'] ?? ''),
            'driver_name' => (string) ($_POST['driver_name'] ?? ''),
            'delivery_person_phone' => (string) ($_POST['delivery_person_phone'] ?? ''),
            'dispatched_at' => (string) ($_POST['dispatched_at'] ?? ''),
            'internal_remarks' => (string) ($_POST['internal_remarks'] ?? ''),
            'retry' => $action === 'retry',
            'keep_booking_reference' => !empty($_POST['keep_booking_reference']),
        ];
        if ($input['courier_service_name'] === 'Other' && trim($input['courier_other']) !== '') {
            $input['courier_service_name'] = trim($input['courier_other']);
        }
        $res = DeliveryAssignmentService::updateFromAdmin($id, $input);
        if (!empty($res['ok'])) {
            admin_flash_set('Delivery updated.');
        } else {
            admin_flash_set($res['message'] ?? 'Update failed.', 'error');
        }
    }
    header('Location: ' . url_base() . '/admin/deliveries/view.php?id=' . $id, true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Delivery detail', 'activeAdminNav' => 'deliveries']);
admin_flash_render();

if ($delivery === null) {
    echo '<p class="at-alert at-alert--error">Delivery not found.</p>';
    admin_layout_end();
    exit;
}

$current = DeliveryStatus::normalize((string) $delivery['status']);
$next = DeliveryStatusTransitions::allowedNext($current);
$presets = DeliveryCourierPresets::list();
$history = DeliveryAssignmentService::statusHistory($id);
$smsLogs = DeliverySmsService::logsForAdmin($id);
$orderQuoteRow = null;
if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $oq = Database::pdo()->prepare('SELECT delivery_fee, delivery_snapshot_json, delivery_zone_id FROM orders WHERE id = ? LIMIT 1');
    $oq->execute([(int) ($delivery['order_id'] ?? 0)]);
    $orderQuoteRow = $oq->fetch(PDO::FETCH_ASSOC) ?: null;
}
$checkoutQuote = $orderQuoteRow !== null ? DeliveryOrderSnapshot::reportingDeliveryQuote($orderQuoteRow) : null;
$pageHeading = 'Delivery — ' . (string) $delivery['order_number'];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/deliveries/index.php">← Deliveries</a></p>

<div class="grid lg:grid-cols-2 gap-4">
    <div class="at-admin-card">
        <div class="at-admin-card__body text-sm space-y-2">
            <p><strong>Order:</strong> <?= Security::escape((string) $delivery['order_number']) ?></p>
            <p><strong>Slot / date:</strong> <?= Security::escape((string) ($delivery['delivery_slot'] ?? '')) ?> · <?= Security::escape((string) ($delivery['delivery_date'] ?? '—')) ?></p>
            <p><strong>Payment:</strong> <?= Security::escape((string) ($delivery['payment_status'] ?? '')) ?> (not changed here)</p>
            <?php if ($checkoutQuote !== null): ?>
                <p><strong>Checkout delivery fee<?= !empty($checkoutQuote['from_snapshot']) ? ' (snapshot)' : '' ?>:</strong>
                    ₹<?= number_format((float) ($checkoutQuote['delivery_fee'] ?? 0), 2) ?>
                    <?php if (!empty($checkoutQuote['zone_label'])): ?>
                        · <?= Security::escape((string) $checkoutQuote['zone_label']) ?>
                    <?php endif; ?>
                </p>
                <?php if (!empty($checkoutQuote['quoted_at'])): ?>
                    <p class="text-xs at-muted">Quoted at checkout: <?= Security::escape((string) $checkoutQuote['quoted_at']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <p><strong>Status:</strong> <?= Security::escape($current) ?></p>
            <?php if (!empty($delivery['dispatched_at'])): ?>
                <p><strong>Dispatched:</strong> <?= Security::escape((string) $delivery['dispatched_at']) ?></p>
            <?php endif; ?>
            <?php if (!empty($delivery['delivered_at'])): ?>
                <p><strong>Delivered:</strong> <?= Security::escape((string) $delivery['delivered_at']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="at-admin-card">
        <div class="at-admin-card__body">
            <h3 class="font-bold mb-3">Update delivery</h3>
            <form method="post" class="space-y-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="update">
                <div>
                    <label class="at-label">New status</label>
                    <select name="status" class="at-select at-focusable" required>
                        <option value="">— select —</option>
                        <?php foreach ($next as $st): ?>
                            <option value="<?= Security::escape($st) ?>"><?= Security::escape(str_replace('_', ' ', $st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="at-label">Courier / service</label>
                    <select name="courier_service_name" class="at-select at-focusable">
                        <option value="">—</option>
                        <?php foreach ($presets as $p): ?>
                            <option value="<?= Security::escape($p) ?>"<?= (($delivery['courier_service_name'] ?? '') === $p) ? ' selected' : '' ?>><?= Security::escape($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input name="courier_other" class="at-input at-focusable mt-1 text-sm" placeholder="If Other, name here">
                </div>
                <div><label class="at-label">Booking / reference #</label>
                    <input name="booking_reference" class="at-input at-focusable" value="<?= Security::escape((string) ($delivery['booking_reference'] ?? '')) ?>"></div>
                <div><label class="at-label">Delivery person name</label>
                    <input name="driver_name" class="at-input at-focusable" value="<?= Security::escape((string) ($delivery['driver_name'] ?? '')) ?>"></div>
                <div><label class="at-label">Delivery person phone</label>
                    <input name="delivery_person_phone" class="at-input at-focusable" value="<?= Security::escape((string) ($delivery['delivery_person_phone'] ?? '')) ?>"></div>
                <div><label class="at-label">Dispatched at (if moving to dispatched)</label>
                    <input name="dispatched_at" type="datetime-local" class="at-input at-focusable"></div>
                <div><label class="at-label">Internal remarks (required)</label>
                    <textarea name="internal_remarks" class="at-textarea at-focusable" rows="2" required minlength="5"></textarea></div>
                <button type="submit" class="at-btn at-btn--primary at-focusable">Save status</button>
            </form>
            <?php if ($current === DeliveryStatus::FAILED): ?>
                <form method="post" class="mt-4 pt-4 border-t border-[var(--at-line)]">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="retry">
                    <input type="hidden" name="status" value="<?= Security::escape(DeliveryStatus::PENDING_DISPATCH) ?>">
                    <label class="flex gap-2 text-sm mb-2"><input type="checkbox" name="keep_booking_reference" value="1"> Keep booking reference</label>
                    <textarea name="internal_remarks" class="at-textarea at-focusable text-sm" required minlength="5" placeholder="Reason for retry"></textarea>
                    <button type="submit" class="at-btn at-btn--secondary at-focusable mt-2">Retry delivery</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<section class="at-admin-card mt-4">
    <div class="at-admin-card__body">
        <h3 class="font-bold">Status history</h3>
        <ul class="mt-2 text-sm space-y-2">
            <?php foreach ($history as $h): ?>
                <li>
                    <strong><?= Security::escape((string) $h['status']) ?></strong>
                    <span class="at-muted"> · <?= Security::escape((string) $h['created_at']) ?></span>
                    <?php if (!empty($h['admin_name'])): ?> · <?= Security::escape((string) $h['admin_name']) ?><?php endif; ?>
                    <div class="text-xs at-muted"><?= Security::escape((string) ($h['note'] ?? '')) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="at-admin-card mt-4">
    <div class="at-admin-card__body">
        <h3 class="font-bold">Customer SMS log</h3>
        <?php if ($smsLogs === []): ?>
            <p class="text-sm at-muted mt-2">No SMS attempts yet.</p>
        <?php else: ?>
            <div class="at-admin-table-wrap mt-2"><table class="at-admin-table">
                <thead><tr><th>Event</th><th>Mobile</th><th>Status</th><th>Gateway ref</th><th>Time</th><th class="is-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($smsLogs as $log): ?>
                    <tr>
                        <td><?= Security::escape((string) $log['event_key']) ?></td>
                        <td><?= Security::escape((string) ($log['mobile_display'] ?? $log['mobile_masked'] ?? '—')) ?></td>
                        <td><?= admin_status_badge((string) $log['status']) ?></td>
                        <td class="is-id"><?= Security::escape((string) ($log['gateway_message_id'] ?? '—')) ?></td>
                        <td><?= Security::escape((string) ($log['sent_at'] ?? $log['created_at'])) ?></td>
                        <td class="is-actions">
                            <?php if ((string) $log['status'] === 'failed'): ?>
                                <form method="post" class="inline">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="retry_sms">
                                    <input type="hidden" name="sms_log_id" value="<?= (int) $log['id'] ?>">
                                    <button type="submit" class="at-btn at-btn--sm at-focusable">Retry SMS</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php admin_layout_end(); ?>
