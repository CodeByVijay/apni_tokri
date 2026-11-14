<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('invoices');

$id = (int) ($_GET['id'] ?? 0);
$inv = $id > 0 && Install::isSchemaReady() ? InvoiceService::byId($id) : null;
$self = url_base() . '/admin/invoices/view.php?id=' . $id;

if ($inv !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'send_email') {
        $r = InvoiceEmailService::sendFromAdmin((int) $inv['id'], (int) AdminAuth::id());
        admin_flash_set($r['message'], !empty($r['ok']) ? 'success' : 'error');
    }
    header('Location: ' . $self, true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Invoice', 'activeAdminNav' => 'invoices']);

if ($inv === null) {
    echo '<div class="at-alert at-alert--error">Invoice not found.</div>';
    echo '<p class="mt-3"><a class="underline" href="' . Security::escape(url_base()) . '/admin/invoices/index.php">← Back to invoices</a></p>';
    admin_layout_end();
    exit;
}

$snap = InvoiceService::snapshot($inv);
$isCn = $inv['document_type'] === InvoiceSettings::DOC_CREDIT_NOTE;
$pdo = Database::pdo();
$cs = $pdo->prepare('SELECT id, name, mobile, email FROM customers WHERE id = ?');
$cs->execute([(int) $inv['customer_id']]);
$cust = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
$os = $pdo->prepare('SELECT id, order_number, status, payment_status FROM orders WHERE id = ?');
$os->execute([(int) $inv['order_id']]);
$order = $os->fetch(PDO::FETCH_ASSOC) ?: [];
$creditNotes = $isCn ? [] : InvoiceService::creditNotesForOrder((int) $inv['order_id']);
$parent = $isCn && !empty($inv['parent_invoice_id']) ? InvoiceService::byId((int) $inv['parent_invoice_id']) : null;
$emails = InvoiceEmailService::history((int) $inv['id']);
$payments = (array) ($snap['payments'] ?? []);
$base = url_base() . '/admin/invoices';
$pageHeading = ($isCn ? 'Credit note ' : 'Invoice ') . (string) $inv['invoice_number'];
$pageDescription = 'Issued ' . InvoiceFormat::date((string) $inv['invoice_date']) . ' · snapshot is immutable';
$breadcrumbs = [['label' => 'Invoices', 'href' => $base . '/index.php'], ['label' => (string) $inv['invoice_number']]];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$custEmail = trim((string) ($cust['email'] ?? ''));
$emailOn = InvoiceSettings::emailEnabled();
$sentBefore = false;
foreach ($emails as $e) {
    if ($e['status'] === 'sent') {
        $sentBefore = true;
        break;
    }
}
$canSend = $emailOn && $custEmail !== '' && (!$sentBefore || InvoiceSettings::adminResendAllowed());
$h = static function ($v): string {
    return Security::escape((string) $v);
};
?>
<div class="grid lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 space-y-4">
        <div class="at-card"><div class="at-card__body">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h3 class="font-bold">Preview</h3>
                <div class="flex flex-wrap gap-2">
                    <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= $h($base) ?>/download.php?id=<?= (int) $inv['id'] ?>">Download PDF</a>
                    <a class="at-btn at-btn--sm at-btn--secondary at-focusable" target="_blank" rel="noopener" href="<?= $h($base) ?>/download.php?id=<?= (int) $inv['id'] ?>&amp;inline=1">Open in new tab</a>
                    <button type="button" class="at-btn at-btn--sm at-btn--secondary at-focusable" id="inv-print">Print</button>
                    <?php if (InvoiceSettings::thermalEnabled()): ?>
                        <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="<?= $h($base) ?>/download.php?id=<?= (int) $inv['id'] ?>&amp;layout=thermal">Thermal (80mm)</a>
                    <?php endif; ?>
                </div>
            </div>
            <iframe id="inv-frame" title="Invoice preview" src="<?= $h($base) ?>/download.php?id=<?= (int) $inv['id'] ?>&amp;inline=1"
                    class="w-full rounded border border-[var(--at-line)] bg-white" style="height:80vh;min-height:520px"></iframe>
        </div></div>
    </div>

    <div class="space-y-4">
        <div class="at-card"><div class="at-card__body text-sm space-y-2">
            <h3 class="font-bold">Details</h3>
            <p><strong>Type:</strong> <?= $isCn ? 'Credit note' : 'Retail invoice' ?></p>
            <p><strong>Amount:</strong> <?= $h(InvoiceFormat::money((float) $inv['grand_total'])) ?></p>
            <p><strong>Order:</strong> <a class="underline at-focusable" href="<?= $h(url_base()) ?>/admin/orders/view.php?id=<?= (int) $inv['order_id'] ?>"><?= $h($order['order_number'] ?? '') ?></a>
                <?= admin_status_badge((string) ($order['payment_status'] ?? '')) ?></p>
            <p><strong>Customer:</strong> <?= $h(($cust['name'] ?? '') !== '' ? $cust['name'] : 'Customer') ?>
                <?php if (!empty($cust['id'])): ?> · <a class="underline at-focusable" href="<?= $h(url_base()) ?>/admin/customers/index.php?q=<?= urlencode((string) ($cust['mobile'] ?? '')) ?>">Customer</a><?php endif; ?></p>
            <p><strong>Mobile:</strong> <?= $h($cust['mobile'] ?? '—') ?></p>
            <?php if ($parent !== null): ?>
                <p><strong>Original invoice:</strong> <a class="underline at-focusable" href="<?= $h($base) ?>/view.php?id=<?= (int) $parent['id'] ?>"><?= $h($parent['invoice_number']) ?></a></p>
            <?php endif; ?>
            <p class="text-xs at-muted break-all">PDF SHA-256: <?= $h($inv['pdf_hash']) ?> (<?= (int) $inv['pdf_size'] ?> bytes)</p>
        </div></div>

        <div class="at-card"><div class="at-card__body text-sm space-y-3">
            <h3 class="font-bold">Email</h3>
            <?php if (!$emailOn): ?>
                <p class="at-muted">Invoice emails are disabled in Invoice Settings.</p>
            <?php elseif ($custEmail === ''): ?>
                <p class="at-muted">This customer has no email address on file.</p>
            <?php else: ?>
                <p>Recipient: <strong><?= $h($custEmail) ?></strong></p>
            <?php endif; ?>
            <form method="post" id="inv-email-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="send_email">
                <button type="submit" id="inv-email-btn" class="at-btn at-btn--primary at-btn--sm at-focusable"<?= $canSend ? '' : ' disabled' ?>>
                    <?= $sentBefore ? 'Resend invoice email' : 'Send invoice email' ?>
                </button>
            </form>
            <?php if ($emails !== []): ?>
                <ul class="space-y-1 text-xs">
                    <?php foreach ($emails as $e): ?>
                        <li><?= admin_status_badge((string) $e['status']) ?>
                            <?= $h($e['attempted_at']) ?> → <?= $h($e['recipient']) ?>
                            <span class="at-muted">(<?= $h($e['trigger_source']) ?><?= !empty($e['admin_name']) ? ', ' . $h($e['admin_name']) : '' ?><?= !empty($e['error_code']) ? ', ' . $h($e['error_code']) : '' ?>)</span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>

        <?php if ($payments !== []): ?>
        <div class="at-card"><div class="at-card__body text-sm space-y-2">
            <h3 class="font-bold">Payments (at issue)</h3>
            <?php foreach ($payments as $p): ?>
                <p><?= admin_status_badge((string) $p['status']) ?> <?= $h($p['gateway_label']) ?> · <?= $h(InvoiceFormat::money((float) $p['amount'])) ?>
                    <?php if (!empty($p['reference'])): ?><br><span class="text-xs at-muted"><?= $h($p['reference']) ?></span><?php endif; ?></p>
            <?php endforeach; ?>
        </div></div>
        <?php endif; ?>

        <?php if ($creditNotes !== []): ?>
        <div class="at-card"><div class="at-card__body text-sm space-y-2">
            <h3 class="font-bold">Credit notes</h3>
            <?php foreach ($creditNotes as $cn): ?>
                <p><a class="underline at-focusable" href="<?= $h($base) ?>/view.php?id=<?= (int) $cn['id'] ?>"><?= $h($cn['invoice_number']) ?></a> · <?= $h(InvoiceFormat::money((float) $cn['grand_total'])) ?></p>
            <?php endforeach; ?>
        </div></div>
        <?php endif; ?>
    </div>
</div>
<script <?= Security::cspNonceAttr() ?>>
(function () {
    var f = document.getElementById('inv-email-form'), b = document.getElementById('inv-email-btn');
    if (f && b) { f.addEventListener('submit', function () { b.disabled = true; b.textContent = 'Sending…'; }); }
    var p = document.getElementById('inv-print'), fr = document.getElementById('inv-frame');
    if (p && fr) { p.addEventListener('click', function () { try { fr.contentWindow.focus(); fr.contentWindow.print(); } catch (e) { window.open(fr.src, '_blank'); } }); }
})();
</script>
<?php admin_layout_end(); ?>
