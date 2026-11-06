<?php

declare(strict_types=1);

/**
 * Emails invoices (PDF attached) through the existing SMTP infrastructure.
 * Failures are logged and never affect invoice generation.
 */
final class InvoiceEmailService
{
    /** @var callable|null test seam: fn(string $to, string $subject, string $text, string $html, array $attachments): array{ok: bool, error?: string} */
    public static $transport;

    /** Seconds within which a second admin click is treated as a double submit. */
    private const DOUBLE_CLICK_WINDOW = 20;

    /** @return list<array<string, mixed>> */
    public static function history(int $invoiceId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT l.*, a.name AS admin_name FROM invoice_email_log l
             LEFT JOIN admin_users a ON a.id = l.admin_user_id
             WHERE l.invoice_id = ? ORDER BY l.id DESC LIMIT 50'
        );
        try {
            $st->execute([$invoiceId]);
        } catch (Throwable $e) {
            $st = Database::pdo()->prepare('SELECT l.*, NULL AS admin_name FROM invoice_email_log l WHERE l.invoice_id = ? ORDER BY l.id DESC LIMIT 50');
            $st->execute([$invoiceId]);
        }

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{status: string, label: string} */
    public static function statusFor(int $invoiceId): array
    {
        $st = Database::pdo()->prepare('SELECT status FROM invoice_email_log WHERE invoice_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$invoiceId]);
        $s = (string) ($st->fetchColumn() ?: '');
        $map = ['sent' => 'Sent', 'failed' => 'Failed', 'pending' => 'Sending'];

        return $s === '' ? ['status' => 'none', 'label' => 'Not sent'] : ['status' => $s, 'label' => $map[$s] ?? ucfirst($s)];
    }

    /** @return array{ok: bool, error?: string, message: string} */
    public static function sendAuto(int $invoiceId): array
    {
        if (!InvoiceSettings::emailEnabled() || !InvoiceSettings::autoEmail()) {
            return ['ok' => false, 'error' => 'disabled', 'message' => 'Automatic invoice email is off.'];
        }

        return self::send($invoiceId, 'auto', null, 'auto:' . $invoiceId);
    }

    /**
     * Customer automatic invoice email — only after order delivery (idempotent per order).
     *
     * @return array{ok: bool, error?: string, message: string}
     */
    public static function sendWhenOrderDelivered(int $orderId): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'error' => 'invalid', 'message' => 'Invalid order.'];
        }
        if (!InvoiceSettings::emailEnabled() || !InvoiceSettings::autoEmail()) {
            return ['ok' => false, 'error' => 'disabled', 'message' => 'Automatic invoice email is off.'];
        }
        if (!InvoiceService::orderDeliveryProven($orderId)) {
            return ['ok' => false, 'error' => 'not_delivered', 'message' => 'Order has not been delivered yet.'];
        }
        $sync = InvoiceService::syncForOrder($orderId);
        $inv = $sync['invoice'] ?? null;
        if ($inv === null || empty($inv['id'])) {
            error_log('InvoiceEmailService::sendWhenOrderDelivered: no invoice for order ' . $orderId);

            return ['ok' => false, 'error' => 'no_invoice', 'message' => 'Invoice is not available for this order yet.'];
        }
        $read = InvoiceStorage::read($inv);
        if (!$read['ok']) {
            error_log('InvoiceEmailService::sendWhenOrderDelivered: PDF missing for invoice ' . (int) $inv['id']);

            return ['ok' => false, 'error' => 'pdf_missing', 'message' => 'Invoice PDF is not ready.'];
        }

        return self::send((int) $inv['id'], 'auto', null, self::deliveredEmailAutoKey($orderId));
    }

    public static function deliveredEmailAutoKey(int $orderId): string
    {
        return 'invoice.delivered:order:' . $orderId . ':email';
    }

    /** Idempotent customer invoice email after delivery; never throws (order status or delivery row). */
    public static function trySendWhenOrderDelivered(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            self::sendWhenOrderDelivered($orderId);
        } catch (Throwable $e) {
            error_log('InvoiceEmailService::trySendWhenOrderDelivered: ' . get_class($e));
        }
    }

    /** @return array{ok: bool, error?: string, message: string} */
    public static function sendFromAdmin(int $invoiceId, int $adminId): array
    {
        return self::send($invoiceId, 'admin', $adminId, null);
    }

    /**
     * @return array{ok: bool, error?: string, message: string}
     */
    private static function send(int $invoiceId, string $trigger, ?int $adminId, ?string $autoKey): array
    {
        $pdo = Database::pdo();
        self::recoverStalePendingLogs($pdo);
        if (!InvoiceSettings::emailEnabled()) {
            return ['ok' => false, 'error' => 'disabled', 'message' => 'Invoice emails are disabled in Invoice Settings.'];
        }
        $inv = InvoiceService::byId($invoiceId);
        if ($inv === null) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Invoice not found.'];
        }
        $cs = $pdo->prepare('SELECT name, email FROM customers WHERE id = ? LIMIT 1');
        $cs->execute([(int) $inv['customer_id']]);
        $cust = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
        $to = trim((string) ($cust['email'] ?? ''));
        if ($to === '' || !Validation::email($to) || SmtpSettingsService::containsHeaderInjection($to)) {
            return ['ok' => false, 'error' => 'no_email', 'message' => 'The customer has no valid email address on file.'];
        }

        if ($trigger === 'admin') {
            $prev = $pdo->prepare("SELECT status, attempted_at FROM invoice_email_log WHERE invoice_id = ? ORDER BY id DESC LIMIT 1");
            $prev->execute([$invoiceId]);
            $last = $prev->fetch(PDO::FETCH_ASSOC);
            if ($last && strtotime((string) $last['attempted_at']) > time() - self::DOUBLE_CLICK_WINDOW
                && in_array((string) $last['status'], ['pending', 'sent'], true)) {
                return ['ok' => false, 'error' => 'duplicate', 'message' => 'An email for this invoice was just sent. Please wait before sending again.'];
            }
            $sent = $pdo->prepare("SELECT COUNT(*) FROM invoice_email_log WHERE invoice_id = ? AND status = 'sent'");
            $sent->execute([$invoiceId]);
            if ((int) $sent->fetchColumn() > 0 && !InvoiceSettings::adminResendAllowed()) {
                return ['ok' => false, 'error' => 'resend_disabled', 'message' => 'Resending is disabled in Invoice Settings.'];
            }
        }

        // Reserve a log row (auto emails dedupe on the unique auto_key).
        try {
            $ins = $pdo->prepare(
                "INSERT INTO invoice_email_log (invoice_id, recipient, trigger_source, status, admin_user_id, auto_key, attempted_at)
                 VALUES (?,?,?,'pending',?,?,?)"
            );
            $ins->execute([$invoiceId, $to, $trigger, $adminId, $autoKey, date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'duplicate', 'message' => 'This invoice email was already sent automatically.'];
            }
            throw $e;
        }
        $logId = (int) $pdo->lastInsertId();

        $result = ['ok' => false, 'error' => 'send_failed'];
        try {
            $read = InvoiceStorage::read($inv);
            if (!$read['ok']) {
                $result = ['ok' => false, 'error' => 'pdf_' . (string) ($read['error'] ?? 'missing')];
            } else {
                $msg = self::compose($inv, (string) ($cust['name'] ?? ''));
                $attachments = [[
                    'filename' => preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string) $inv['invoice_number']) . '.pdf',
                    'mime' => 'application/pdf',
                    'content' => (string) $read['bytes'],
                ]];
                if (self::$transport !== null) {
                    $result = (self::$transport)($to, $msg['subject'], $msg['text'], $msg['html'], $attachments);
                } else {
                    $result = (new SmtpMailer())->send($to, $msg['subject'], $msg['text'], $msg['html'], $attachments);
                }
            }
        } catch (Throwable $e) {
            error_log('InvoiceEmailService: ' . get_class($e));
            $result = ['ok' => false, 'error' => 'exception'];
        }

        $ok = !empty($result['ok']);
        $code = $ok ? null : substr((string) ($result['error'] ?? 'send_failed'), 0, 60);
        $pdo->prepare('UPDATE invoice_email_log SET status = ?, error_code = ?, completed_at = ? WHERE id = ?')
            ->execute([$ok ? 'sent' : 'failed', $code, date('Y-m-d H:i:s'), $logId]);

        if ($ok) {
            return ['ok' => true, 'message' => 'Invoice emailed to ' . $to . '.'];
        }
        $pub = $code !== null && strpos($code, 'pdf_') === 0
            ? 'The invoice PDF could not be read.'
            : SmtpMailer::publicErrorMessage((string) $code);

        return ['ok' => false, 'error' => (string) $code, 'message' => 'Email failed: ' . $pub . ' You can retry.'];
    }

    /** Mark abandoned pending rows so duplicate guards do not block retries (no email sent). */
    private static function recoverStalePendingLogs(PDO $pdo): void
    {
        $pdo->prepare(
            "UPDATE invoice_email_log SET status = 'failed', error_code = 'stale_pending', completed_at = NOW()
             WHERE status = 'pending' AND attempted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        )->execute();
    }

    /** @return array{subject: string, text: string, html: string} */
    public static function compose(array $invoice, string $customerName): array
    {
        $snap = InvoiceService::snapshot($invoice);
        $orderNo = (string) ($snap['order']['number'] ?? '');
        $brand = (string) ($snap['seller']['brand_name'] ?? 'Veggie Tokri');
        $isCredit = ($invoice['document_type'] ?? '') === InvoiceSettings::DOC_CREDIT_NOTE;
        $docName = $isCredit ? 'credit note' : 'invoice';
        $subject = $isCredit
            ? 'Your Veggie Tokri Credit Note – Order #' . $orderNo
            : 'Your Veggie Tokri Invoice – Order #' . $orderNo;
        $link = App::baseUrl() . '/invoice-view.php?ref=' . $invoice['public_ref'];
        $name = trim($customerName) !== '' ? trim($customerName) : 'there';
        $amount = InvoiceFormat::money((float) $invoice['grand_total']);
        $e = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };
        $support = trim((string) ($snap['seller']['phone'] ?? '') . ' ' . (string) ($snap['seller']['email'] ?? ''));

        $text = "Hi {$name},\n\nThank you for shopping with {$brand}. Your {$docName} {$invoice['invoice_number']} for order #{$orderNo} ({$amount}) is attached as a PDF.\n\n"
            . "You can also view it any time after signing in:\n{$link}\n\n"
            . ($support !== '' ? "Need help? {$support}\n\n" : '')
            . "This is a computer-generated document.\n— {$brand}";

        $html = '<!doctype html><html><body style="margin:0;background:#f3f7f4;font-family:Arial,Helvetica,sans-serif;color:#1a2a20">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #d6e3da">'
            . '<tr><td style="background:#133522;color:#fff;padding:20px 24px;font-size:20px;font-weight:bold">' . $e($brand) . '</td></tr>'
            . '<tr><td style="padding:24px">'
            . '<p style="margin:0 0 12px;font-size:16px">Hi ' . $e($name) . ',</p>'
            . '<p style="margin:0 0 16px;line-height:1.5">Thank you for shopping with us. Your ' . $e($docName) . ' for order <strong>#' . $e($orderNo) . '</strong> is attached to this email as a PDF.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#edf6f0;border-radius:8px;margin:0 0 20px"><tr><td style="padding:14px 16px;font-size:14px;line-height:1.7">'
            . ucfirst($e($docName)) . ' no.: <strong>' . $e((string) $invoice['invoice_number']) . '</strong><br>'
            . 'Date: <strong>' . $e(InvoiceFormat::date((string) $invoice['invoice_date'])) . '</strong><br>'
            . 'Amount: <strong>' . $e($amount) . '</strong></td></tr></table>'
            . '<p style="margin:0 0 20px"><a href="' . $e($link) . '" style="display:inline-block;background:#1f6b45;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold">View in your account</a></p>'
            . '<p style="margin:0;font-size:12px;color:#6b7a70;line-height:1.5">' . ($support !== '' ? 'Need help? ' . $e($support) . '<br>' : '') . 'This is a computer-generated document.</p>'
            . '</td></tr></table></td></tr></table></body></html>';

        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }
}
