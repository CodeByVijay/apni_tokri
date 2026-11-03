<?php

declare(strict_types=1);

/**
 * Renders an invoice snapshot (see InvoiceSnapshotBuilder) to PDF. Pure function of the snapshot:
 * never reads current settings, catalogue or order rows, so historical invoices stay identical.
 */
final class InvoicePdfRenderer
{
    private const A4_W = 595.28;
    private const A4_H = 841.89;
    private const M = 40.0;
    private const FOOT = 92.0;

    private const GREEN = [0.075, 0.204, 0.129];
    private const ACCENT = [0.122, 0.420, 0.270];
    private const LIGHT = [0.929, 0.965, 0.941];
    private const LINE = [0.840, 0.890, 0.860];
    private const MUTED = [0.420, 0.470, 0.440];
    private const INK = [0.100, 0.130, 0.110];
    private const WHITE = [1.0, 1.0, 1.0];

    /** @var PdfDocument */
    private $d;
    /** @var array<string, mixed> */
    private $s;
    /** @var float */
    private $y = 0.0;
    /** @var float */
    private $w;

    /** @param array<string, mixed> $snapshot */
    private function __construct(array $snapshot)
    {
        $this->s = $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public static function renderA4(array $snapshot): string
    {
        $r = new self($snapshot);

        return $r->a4();
    }

    /** @param array<string, mixed> $snapshot */
    public static function renderThermal(array $snapshot): string
    {
        $r = new self($snapshot);
        $probe = $r->thermal(2000.0);
        unset($probe);

        return $r->thermal($r->thermalHeight + 14.0);
    }

    // ---------------------------------------------------------------- A4

    private function a4(): string
    {
        $doc = $this->s['document'];
        $this->w = self::A4_W - 2 * self::M;
        $this->d = new PdfDocument(self::A4_W, self::A4_H, (string) $doc['title'] . ' ' . (string) $doc['number'], $this->pdfDate());
        $this->d->addPage();
        $this->header();
        $this->sellerAndMeta();
        $isCredit = ($doc['type'] ?? '') === InvoiceSettings::DOC_CREDIT_NOTE;
        $this->parties();
        if (!$isCredit) {
            $this->deliveryStrip();
            $this->itemsTable();
        } else {
            $this->creditDetails();
        }
        $this->totalsAndPayments();
        $this->finishPages();

        return $this->d->output();
    }

    private function pdfDate(): string
    {
        $ts = strtotime((string) ($this->s['generated_at'] ?? '')) ?: time();

        return gmdate('YmdHis', $ts);
    }

    private function header(): void
    {
        $d = $this->d;
        $seller = $this->s['seller'];
        $doc = $this->s['document'];
        $this->logo(self::M, 34, 46);
        $tx = self::M + 58;
        $d->text($tx, 56, (string) ($seller['brand_name'] ?? ''), 21, true, self::GREEN);
        if (!empty($seller['tagline'])) {
            $d->text($tx, 70, (string) $seller['tagline'], 9, false, self::MUTED);
        }
        $right = self::A4_W - self::M;
        $d->text($right, 52, (string) $doc['title'], 19, true, self::GREEN, 'R');
        if (!empty($doc['badge'])) {
            $label = strtoupper((string) $doc['badge']);
            $bw = $d->textWidth($label, 8, true) + 16;
            $d->rect($right - $bw, 60, $bw, 16, self::LIGHT, self::ACCENT, 0.6, 8);
            $d->text($right - $bw / 2, 71.2, $label, 8, true, self::ACCENT, 'C');
        }
        $d->rect(self::M, 90, $this->w, 2.2, self::ACCENT);
        $this->y = 108;
    }

    private function logo(float $x, float $y, float $size): void
    {
        $path = $this->s['logo_path'] ?? null;
        if (is_string($path) && $path !== '' && is_file($path)) {
            $img = PdfImage::load($path);
            if ($img !== null && $img->width > 0 && $img->height > 0) {
                $ratio = $img->width / $img->height;
                $w = $size;
                $h = $size;
                if ($ratio > 1) {
                    $h = $size / $ratio;
                } else {
                    $w = $size * $ratio;
                }
                $this->d->image('Im1', $img, $x, $y + ($size - $h) / 2, $w, $h);

                return;
            }
        }
        // Brand mark fallback (vector): rounded square with monogram.
        $this->d->rect($x, $y, $size, $size, self::GREEN, null, 0, 9);
        $this->d->text($x + $size / 2, $y + $size / 2 + 6.5, 'AT', 19, true, self::WHITE, 'C');
    }

    private function sellerAndMeta(): void
    {
        $d = $this->d;
        $seller = $this->s['seller'];
        $doc = $this->s['document'];
        $order = $this->s['order'];
        $leftW = 270.0;
        $left = [];
        $left[] = ['t' => (string) ($seller['legal_name'] ?? ''), 'b' => true, 's' => 10.5, 'c' => self::INK];
        if (!empty($seller['brand_name']) && ($seller['brand_name'] ?? '') !== ($seller['legal_name'] ?? '')) {
            $left[] = ['t' => 'Brand: ' . $seller['brand_name'], 'b' => false, 's' => 8.5, 'c' => self::MUTED];
        }
        if (!empty($seller['address'])) {
            $left[] = ['t' => (string) $seller['address'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        $cityLine = trim(implode(', ', array_filter([(string) ($seller['city'] ?? ''), (string) ($seller['state'] ?? '')])));
        if (!empty($seller['pincode'])) {
            $cityLine .= ($cityLine !== '' ? ' - ' : '') . $seller['pincode'];
        }
        if ($cityLine !== '') {
            $left[] = ['t' => $cityLine, 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        if (!empty($seller['phone'])) {
            $left[] = ['t' => 'Support: ' . $seller['phone'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        if (!empty($seller['email'])) {
            $left[] = ['t' => (string) $seller['email'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        if (!empty($seller['website'])) {
            $left[] = ['t' => (string) $seller['website'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        if (!empty($seller['fssai'])) {
            $left[] = ['t' => 'FSSAI: ' . $seller['fssai'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        if (!empty($seller['gstin']) && InvoiceSettings::gstMode() !== 'unregistered') {
            $left[] = ['t' => 'GSTIN: ' . $seller['gstin'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        $leftLines = $this->layoutLines($left, $leftW);
        $leftH = $this->linesHeight($leftLines);

        $isCredit = ($doc['type'] ?? '') === InvoiceSettings::DOC_CREDIT_NOTE;
        $rows = [];
        $rows[] = [$isCredit ? 'Credit note no.' : 'Invoice no.', (string) $doc['number']];
        $rows[] = [$isCredit ? 'Credit note date' : 'Invoice date', InvoiceFormat::date((string) $doc['date'])];
        if ($isCredit) {
            $rows[] = ['Original invoice', (string) ($doc['original_invoice_number'] ?? '')];
        }
        $rows[] = ['Order no.', (string) $order['number']];
        $rows[] = ['Order date', InvoiceFormat::date((string) ($order['placed_at'] ?? ''))];
        $rows[] = ['Order status', (string) ($order['status_label'] ?? '')];
        $rows[] = ['Payment status', (string) ($order['payment_status_label'] ?? '')];
        $mx = self::M + $this->w - 232;
        $mw = 232.0;
        $prepared = [];
        $mh = 12.0;
        foreach ($rows as [$label, $value]) {
            if ($value === '') {
                continue;
            }
            $vl = $d->wrap($value, $mw - 100 - 12, 8.8, true) ?: [''];
            $prepared[] = [$label, $vl];
            $mh += count($vl) * 12.5 + 1.5;
        }
        $mh += 6;
        $h = max($leftH, $mh);
        $this->ensureSpace($h + 12);
        $y0 = $this->y;
        $this->drawLines($leftLines, self::M, $y0 + 8);
        $d->rect($mx, $y0, $mw, $mh, self::LIGHT, null, 0, 6);
        $cy = $y0 + 16;
        foreach ($prepared as [$label, $vl]) {
            $d->text($mx + 12, $cy, $label, 8.3, false, self::MUTED);
            foreach ($vl as $i => $line) {
                $d->text($mx + 100, $cy + $i * 12.5, $line, 8.8, true, self::INK);
            }
            $cy += count($vl) * 12.5 + 1.5;
        }
        $this->y = $y0 + $h + 14;
    }

    private function parties(): void
    {
        $c = $this->s['customer'];
        $isCredit = ($this->s['document']['type'] ?? '') === InvoiceSettings::DOC_CREDIT_NOTE;
        $colW = ($this->w - 14) / 2;

        $bill = [];
        $bill[] = ['t' => (string) ($c['name'] ?? ''), 'b' => true, 's' => 9.5, 'c' => self::INK];
        if (!empty($c['mobile'])) {
            $bill[] = ['t' => 'Mobile: ' . $c['mobile'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        if (!empty($c['email'])) {
            $bill[] = ['t' => (string) $c['email'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }
        if (!empty($c['billing_address'])) {
            $bill[] = ['t' => (string) $c['billing_address'], 'b' => false, 's' => 8.8, 'c' => self::INK];
        }

        $ship = [];
        $a = $this->s['delivery_address'] ?? null;
        if (is_array($a)) {
            $rn = trim((string) ($a['receiver_name'] ?? ''));
            $rp = trim((string) ($a['receiver_phone'] ?? ''));
            if ($rn !== '' || $rp !== '') {
                $ship[] = ['t' => $rn . ($rp !== '' ? ($rn !== '' ? '  |  ' : '') . $rp : ''), 'b' => true, 's' => 9.5, 'c' => self::INK];
            }
            foreach ((array) ($a['lines'] ?? []) as $line) {
                $ship[] = ['t' => (string) $line, 'b' => false, 's' => 8.8, 'c' => self::INK];
            }
            if (!empty($a['state'])) {
                $ship[] = ['t' => (string) $a['state'], 'b' => false, 's' => 8.8, 'c' => self::INK];
            }
            if (!empty($a['landmark'])) {
                $ship[] = ['t' => 'Landmark: ' . $a['landmark'], 'b' => false, 's' => 8.5, 'c' => self::MUTED];
            }
            if (!empty($a['delivery_instructions'])) {
                $ship[] = ['t' => 'Instructions: ' . $a['delivery_instructions'], 'b' => false, 's' => 8.5, 'c' => self::MUTED];
            }
            if (!empty($a['map_link'])) {
                $ship[] = ['t' => 'Map: ' . $a['map_link'], 'b' => false, 's' => 7.4, 'c' => self::ACCENT, 'link' => (string) $a['map_link']];
            }
        } else {
            $ship[] = ['t' => 'Delivery address not recorded for this order.', 'b' => false, 's' => 8.8, 'c' => self::MUTED];
        }
        if ($isCredit) {
            $ship = [];
            $ship[] = ['t' => 'Related order', 'b' => true, 's' => 9.5, 'c' => self::INK];
            $ship[] = ['t' => (string) ($this->s['order']['number'] ?? ''), 'b' => false, 's' => 8.8, 'c' => self::INK];
        }

        $bl = $this->layoutLines($bill, $colW - 24);
        $sl = $this->layoutLines($ship, $colW - 24);
        $h = max($this->linesHeight($bl), $this->linesHeight($sl)) + 30;
        $this->ensureSpace($h + 10);
        $y0 = $this->y;
        foreach ([[self::M, 'BILLED TO', $bl], [self::M + $colW + 14, $isCredit ? 'ORDER' : 'DELIVER TO', $sl]] as [$x, $title, $lines]) {
            $this->d->rect($x, $y0, $colW, $h, null, self::LINE, 0.7, 6);
            $this->d->text($x + 12, $y0 + 16, $title, 7.8, true, self::ACCENT);
            $this->drawLines($lines, $x + 12, $y0 + 22);
        }
        $this->y = $y0 + $h + 12;
    }

    private function deliveryStrip(): void
    {
        $dl = $this->s['delivery'] ?? null;
        if (!is_array($dl) || empty($this->s['options']['show_delivery_slot'])) {
            return;
        }
        $parts = [];
        if (!empty($dl['date'])) {
            $parts[] = 'Delivery date: ' . InvoiceFormat::date((string) $dl['date']);
        }
        if (!empty($dl['slot'])) {
            $parts[] = 'Slot: ' . $dl['slot'];
        }
        if (!empty($dl['window'])) {
            $parts[] = 'Window: ' . $dl['window'];
        } elseif (!empty($dl['time_range'])) {
            $parts[] = 'Window: ' . $dl['time_range'];
        }
        if (!empty($dl['status'])) {
            $parts[] = 'Delivery status: ' . $dl['status'];
        }
        if ($parts === []) {
            return;
        }
        $lines = $this->d->wrap(implode('   |   ', $parts), $this->w - 24, 8.8, false);
        $h = count($lines) * 12.5 + 12;
        $this->ensureSpace($h + 10);
        $this->d->rect(self::M, $this->y, $this->w, $h, self::LIGHT, null, 0, 6);
        foreach ($lines as $i => $l) {
            $this->d->text(self::M + 12, $this->y + 14 + $i * 12.5, $l, 8.8, false, self::INK);
        }
        $this->y += $h + 12;
    }

    /** @var array<string, float> */
    private $col = ['idx' => 40.0, 'item' => 62.0, 'qty' => 392.0, 'unit' => 472.0, 'total' => 555.28];

    private function tableHeader(): void
    {
        $this->d->rect(self::M, $this->y, $this->w, 20, self::GREEN, null, 0, 4);
        $by = $this->y + 13.5;
        $this->d->text($this->col['idx'] + 6, $by, '#', 8, true, self::WHITE);
        $this->d->text($this->col['item'], $by, 'ITEM', 8, true, self::WHITE);
        $this->d->text($this->col['qty'], $by, 'QTY', 8, true, self::WHITE, 'R');
        $this->d->text($this->col['unit'], $by, 'UNIT PRICE', 8, true, self::WHITE, 'R');
        $this->d->text($this->col['total'] - 8, $by, 'AMOUNT', 8, true, self::WHITE, 'R');
        $this->y += 22;
    }

    private function itemsTable(): void
    {
        $items = (array) ($this->s['items'] ?? []);
        $this->ensureSpace(80);
        $this->tableHeader();
        $showSku = !empty($this->s['options']['show_sku']);
        $itemW = 296.0;
        $limit = self::A4_H - self::FOOT - 6;
        foreach ($items as $i => $it) {
            $nameLines = $this->d->wrap((string) ($it['name'] ?? ''), $itemW, 9, true) ?: [''];
            $sub = [];
            if (!empty($it['variant'])) {
                $sub[] = (string) $it['variant'];
            }
            if ($showSku && !empty($it['sku'])) {
                $sub[] = 'SKU: ' . $it['sku'];
            }
            $subLines = $sub === [] ? [] : $this->d->wrap(implode('  |  ', $sub), $itemW, 8, false);
            $rh = count($nameLines) * 12 + count($subLines) * 10.5 + 10;
            if ($this->y + $rh > $limit) {
                $this->newPage();
                $this->tableHeader();
            }
            $y0 = $this->y;
            $this->d->text($this->col['idx'] + 6, $y0 + 14, (string) ($i + 1), 8.5, false, self::MUTED);
            $ty = $y0 + 14;
            foreach ($nameLines as $l) {
                $this->d->text($this->col['item'], $ty, $l, 9, true, self::INK);
                $ty += 12;
            }
            foreach ($subLines as $l) {
                $this->d->text($this->col['item'], $ty - 1.5, $l, 8, false, self::MUTED);
                $ty += 10.5;
            }
            $this->d->text($this->col['qty'], $y0 + 14, (string) (int) ($it['qty'] ?? 0), 9, false, self::INK, 'R');
            $this->d->text($this->col['unit'], $y0 + 14, InvoiceFormat::money((float) ($it['unit_price'] ?? 0)), 9, false, self::INK, 'R');
            $this->d->text($this->col['total'] - 8, $y0 + 14, InvoiceFormat::money((float) ($it['line_total'] ?? 0)), 9, true, self::INK, 'R');
            $this->d->line(self::M, $y0 + $rh, self::M + $this->w, $y0 + $rh, self::LINE, 0.5);
            $this->y += $rh;
        }
        $this->y += 14;
    }

    private function creditDetails(): void
    {
        $doc = $this->s['document'];
        $refund = (array) ($doc['refund'] ?? []);
        $rows = [
            ['Credit amount', InvoiceFormat::money((float) ($refund['amount'] ?? 0))],
            ['Refund status', (string) ($refund['status_label'] ?? '')],
            ['Refund reason', (string) ($refund['reason'] ?? '')],
            ['Refund reference', (string) ($refund['reference'] ?? '')],
            ['Refund processed', InvoiceFormat::dateTime((string) ($refund['completed_at'] ?? ''))],
            ['Original invoice', (string) ($doc['original_invoice_number'] ?? '')],
            ['Original invoice date', InvoiceFormat::date((string) ($doc['original_invoice_date'] ?? ''))],
        ];
        $this->ensureSpace(140);
        $this->d->rect(self::M, $this->y, $this->w, 20, self::GREEN, null, 0, 4);
        $this->d->text(self::M + 10, $this->y + 13.5, 'CREDIT NOTE DETAILS', 8, true, self::WHITE);
        $this->y += 24;
        foreach ($rows as [$label, $value]) {
            if ($value === '') {
                continue;
            }
            $lines = $this->d->wrap($value, $this->w - 190, 9, true) ?: [''];
            $rh = count($lines) * 12.5 + 8;
            $this->ensureSpace($rh + 4);
            $this->d->text(self::M + 10, $this->y + 12, $label, 8.8, false, self::MUTED);
            foreach ($lines as $i => $l) {
                $this->d->text(self::M + 170, $this->y + 12 + $i * 12.5, $l, 9, true, self::INK);
            }
            $this->d->line(self::M, $this->y + $rh, self::M + $this->w, $this->y + $rh, self::LINE, 0.4);
            $this->y += $rh;
        }
        $this->y += 14;
    }

    private function totalsAndPayments(): void
    {
        $p = (array) $this->s['pricing'];
        $isCredit = ($this->s['document']['type'] ?? '') === InvoiceSettings::DOC_CREDIT_NOTE;
        $payW = 262.0;
        $totW = $this->w - $payW - 16;
        $tx = self::M + $payW + 16;

        // Payment block
        $payLines = [];
        $payLines[] = ['t' => 'PAYMENT DETAILS', 'b' => true, 's' => 7.8, 'c' => self::ACCENT];
        $captured = 0;
        $failed = 0;
        foreach ((array) ($this->s['payments'] ?? []) as $pay) {
            if (($pay['status'] ?? '') === 'captured') {
                $captured++;
                $label = (string) ($pay['gateway_label'] ?? 'Payment');
                if (!empty($pay['method'])) {
                    $label .= ' (' . $pay['method'] . ')';
                }
                $payLines[] = ['t' => $label . ' - Paid', 'b' => true, 's' => 9, 'c' => self::INK];
                $payLines[] = ['t' => 'Amount: ' . InvoiceFormat::money((float) ($pay['amount'] ?? 0)), 'b' => false, 's' => 8.6, 'c' => self::INK];
                if (!empty($pay['paid_at'])) {
                    $payLines[] = ['t' => 'Paid on: ' . InvoiceFormat::dateTime((string) $pay['paid_at']), 'b' => false, 's' => 8.6, 'c' => self::INK];
                }
                if (!empty($pay['reference'])) {
                    $payLines[] = ['t' => 'Reference: ' . $pay['reference'], 'b' => false, 's' => 8.2, 'c' => self::MUTED];
                }
            } elseif (($pay['status'] ?? '') === 'failed') {
                $failed++;
            }
        }
        if ($captured === 0) {
            $payLines[] = ['t' => 'No captured payment recorded.', 'b' => false, 's' => 8.8, 'c' => self::MUTED];
        }
        if ($failed > 0) {
            $payLines[] = ['t' => $failed . ' unsuccessful attempt' . ($failed > 1 ? 's' : '') . ' (not charged).', 'b' => false, 's' => 8, 'c' => self::MUTED];
        }
        $pl = $this->layoutLines($payLines, $payW - 24);
        $payH = $this->linesHeight($pl) + 22;

        // Totals block
        $rows = [];
        if ($isCredit) {
            $rows[] = ['Credit amount', InvoiceFormat::money((float) ($this->s['document']['refund']['amount'] ?? 0)), 'grand'];
            $rows[] = ['Order total', InvoiceFormat::money((float) ($p['grand_total'] ?? 0)), ''];
            $rows[] = ['Total refunded on order', InvoiceFormat::money((float) ($p['amount_refunded'] ?? 0)), ''];
        } else {
            $rows[] = ['Item subtotal', InvoiceFormat::money((float) ($p['subtotal'] ?? 0)), ''];
            if ((float) ($p['discount'] ?? 0) > 0) {
                $lbl = !empty($p['coupon_code']) ? 'Coupon discount (' . $p['coupon_code'] . ')' : 'Discount';
                $rows[] = [$lbl, '-' . InvoiceFormat::money((float) $p['discount']), 'save'];
            }
            $rows[] = ['Delivery fee', (float) ($p['delivery_fee'] ?? 0) > 0 ? InvoiceFormat::money((float) $p['delivery_fee']) : 'Free', ''];
            if (abs((float) ($p['rounding'] ?? 0)) >= 0.005) {
                $rows[] = ['Rounding adjustment', InvoiceFormat::money((float) $p['rounding']), ''];
            }
            $rows[] = ['Grand total', InvoiceFormat::money((float) ($p['grand_total'] ?? 0)), 'grand'];
            $rows[] = ['Amount paid', InvoiceFormat::money((float) ($p['amount_paid'] ?? 0)), ''];
            if ((float) ($p['amount_refunded'] ?? 0) > 0) {
                $rows[] = ['Amount refunded', InvoiceFormat::money((float) $p['amount_refunded']), ''];
            }
            $rows[] = ['Balance due', InvoiceFormat::money((float) ($p['balance_due'] ?? 0)), 'bold'];
        }
        $totH = 14.0;
        foreach ($rows as $r) {
            $totH += $r[2] === 'grand' ? 24 : 16.5;
        }
        $totH += 4;

        $h = max($payH, $totH);
        $this->ensureSpace($h + 20);
        $y0 = $this->y;
        $this->d->rect(self::M, $y0, $payW, $h, null, self::LINE, 0.7, 6);
        $this->drawLines($pl, self::M + 12, $y0 + 6);

        $cy = $y0 + 10;
        foreach ($rows as [$label, $value, $kind]) {
            if ($kind === 'grand') {
                $this->d->rect($tx, $cy - 2, $totW, 21, self::GREEN, null, 0, 4);
                $this->d->text($tx + 10, $cy + 12.5, $label, 9.5, true, self::WHITE);
                $this->d->text($tx + $totW - 10, $cy + 12.5, $value, 10.5, true, self::WHITE, 'R');
                $cy += 24;
                continue;
            }
            $bold = $kind === 'bold';
            $col = $kind === 'save' ? self::ACCENT : self::INK;
            $this->d->text($tx + 4, $cy + 10, $label, 9, $bold, $kind === 'save' ? self::ACCENT : self::MUTED);
            $this->d->text($tx + $totW - 4, $cy + 10, $value, 9, $bold, $col, 'R');
            $cy += 16.5;
        }
        $this->y = $y0 + $h + 16;
    }

    private function newPage(): void
    {
        $this->d->addPage();
        $seller = $this->s['seller'];
        $doc = $this->s['document'];
        $this->d->text(self::M, 38, (string) ($seller['brand_name'] ?? ''), 11, true, self::GREEN);
        $this->d->text(self::A4_W - self::M, 38, (string) $doc['title'] . '  ' . (string) $doc['number'], 9, false, self::MUTED, 'R');
        $this->d->rect(self::M, 46, $this->w, 1.4, self::ACCENT);
        $this->y = 62;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y + $needed > self::A4_H - self::FOOT - 6) {
            $this->newPage();
        }
    }

    private function finishPages(): void
    {
        $total = $this->d->pageCount();
        $seller = $this->s['seller'];
        $opt = (array) $this->s['options'];
        for ($i = 0; $i < $total; $i++) {
            $this->d->setPage($i);
            $top = self::A4_H - self::FOOT + 8;
            $this->d->line(self::M, $top, self::A4_W - self::M, $top, self::LINE, 0.7);
            $this->d->text(self::M, $top + 14, (string) ($opt['footer'] ?? ''), 9, true, self::GREEN);
            $contact = [];
            if (!empty($seller['phone'])) {
                $contact[] = 'Support: ' . $seller['phone'];
            }
            if (!empty($seller['email'])) {
                $contact[] = (string) $seller['email'];
            }
            if (!empty($seller['website'])) {
                $contact[] = (string) $seller['website'];
            }
            $this->d->text(self::M, $top + 27, implode('   |   ', $contact), 8, false, self::INK);
            $x = self::M;
            foreach ([['Return & refund policy', (string) ($opt['return_url'] ?? '')], ['Terms & conditions', (string) ($opt['terms_url'] ?? '')]] as [$label, $url]) {
                if ($url === '') {
                    continue;
                }
                $w = $this->d->textWidth($label, 8, true);
                $this->d->text($x, $top + 40, $label, 8, true, self::ACCENT);
                $this->d->line($x, $top + 41.5, $x + $w, $top + 41.5, self::ACCENT, 0.4);
                $this->d->link($x, $top + 32, $w, 11, $url);
                $x += $w + 18;
            }
            $this->d->text(self::M, $top + 54, 'This is a computer-generated document and does not require a signature.', 7.5, false, self::MUTED);
            $this->d->text(self::A4_W - self::M, $top + 54, 'Page ' . ($i + 1) . ' of ' . $total, 7.5, false, self::MUTED, 'R');
        }
        $this->d->useLastPage();
    }

    // ---------------------------------------------------------------- text blocks

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function layoutLines(array $lines, float $width): array
    {
        $out = [];
        foreach ($lines as $ln) {
            $wrapped = $this->d->wrap((string) $ln['t'], $width, (float) $ln['s'], (bool) $ln['b']);
            foreach ($wrapped as $w) {
                $copy = $ln;
                $copy['t'] = $w;
                $out[] = $copy;
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $lines */
    private function linesHeight(array $lines): float
    {
        $h = 0.0;
        foreach ($lines as $ln) {
            $h += (float) $ln['s'] * 1.42;
        }

        return $h + 4;
    }

    /** @param list<array<string, mixed>> $lines */
    private function drawLines(array $lines, float $x, float $top): void
    {
        $y = $top;
        foreach ($lines as $ln) {
            $size = (float) $ln['s'];
            $y += $size * 1.42;
            $this->d->text($x, $y - $size * 0.28, (string) $ln['t'], $size, (bool) $ln['b'], $ln['c']);
            if (!empty($ln['link'])) {
                $this->d->link($x, $y - $size, $this->d->textWidth((string) $ln['t'], $size, false), $size + 2, (string) $ln['link']);
            }
        }
    }

    // ---------------------------------------------------------------- thermal

    /** @var float */
    public $thermalHeight = 0.0;

    private function thermal(float $pageHeight): string
    {
        $W = 226.77;
        $m = 10.0;
        $cw = $W - 2 * $m;
        $doc = $this->s['document'];
        $seller = $this->s['seller'];
        $d = new PdfDocument($W, $pageHeight, (string) $doc['title'] . ' ' . (string) $doc['number'], $this->pdfDate());
        $d->addPage();
        $y = $m + 6;
        $center = static function (string $text, float $size, bool $bold) use (&$y, $d, $W, $cw): void {
            foreach ($d->wrap($text, $cw, $size, $bold) as $l) {
                $y += $size * 1.3;
                $d->text($W / 2, $y, $l, $size, $bold, [0, 0, 0], 'C');
            }
        };
        $left = static function (string $text, float $size, bool $bold = false) use (&$y, $d, $m, $cw): void {
            foreach ($d->wrap($text, $cw, $size, $bold) as $l) {
                $y += $size * 1.3;
                $d->text($m, $y, $l, $size, $bold);
            }
        };
        $pair = static function (string $l, string $r, float $size, bool $bold = false) use (&$y, $d, $m, $W) {
            $y += $size * 1.4;
            $d->text($m, $y, $l, $size, $bold);
            $d->text($W - $m, $y, $r, $size, $bold, [0, 0, 0], 'R');
        };
        $rule = static function () use (&$y, $d, $m, $W): void {
            $y += 5;
            $d->line($m, $y, $W - $m, $y, [0.3, 0.3, 0.3], 0.5, true);
            $y += 3;
        };

        $center((string) ($seller['brand_name'] ?? ''), 13, true);
        if (!empty($seller['legal_name'])) {
            $center((string) $seller['legal_name'], 7.5, false);
        }
        $addr = trim(implode(', ', array_filter([(string) ($seller['address'] ?? ''), (string) ($seller['city'] ?? ''), (string) ($seller['pincode'] ?? '')])));
        if ($addr !== '') {
            $center($addr, 7, false);
        }
        if (!empty($seller['phone'])) {
            $center('Ph: ' . $seller['phone'], 7, false);
        }
        if (!empty($seller['fssai'])) {
            $center('FSSAI: ' . $seller['fssai'], 7, false);
        }
        $rule();
        $center((string) $doc['title'], 10, true);
        $left('No: ' . (string) $doc['number'], 8, true);
        $left('Date: ' . InvoiceFormat::date((string) $doc['date']), 8);
        $left('Order: ' . (string) $this->s['order']['number'], 8);
        $rule();

        $isCredit = ($doc['type'] ?? '') === InvoiceSettings::DOC_CREDIT_NOTE;
        $p = (array) $this->s['pricing'];
        if (!$isCredit) {
            foreach ((array) ($this->s['items'] ?? []) as $it) {
                $name = (string) ($it['name'] ?? '') . (!empty($it['variant']) ? ' (' . $it['variant'] . ')' : '');
                $left($name, 8, true);
                $pair((int) ($it['qty'] ?? 0) . ' x ' . InvoiceFormat::inr((float) ($it['unit_price'] ?? 0)), InvoiceFormat::inr((float) ($it['line_total'] ?? 0)), 8);
            }
            $rule();
            $pair('Subtotal', InvoiceFormat::inr((float) ($p['subtotal'] ?? 0)), 8);
            if ((float) ($p['discount'] ?? 0) > 0) {
                $pair('Discount', '-' . InvoiceFormat::inr((float) $p['discount']), 8);
            }
            $pair('Delivery', (float) ($p['delivery_fee'] ?? 0) > 0 ? InvoiceFormat::inr((float) $p['delivery_fee']) : 'Free', 8);
            if (abs((float) ($p['rounding'] ?? 0)) >= 0.005) {
                $pair('Rounding', InvoiceFormat::inr((float) $p['rounding']), 8);
            }
            $pair('TOTAL (Rs.)', InvoiceFormat::inr((float) ($p['grand_total'] ?? 0)), 10, true);
            $pair('Paid', InvoiceFormat::inr((float) ($p['amount_paid'] ?? 0)), 8);
            if ((float) ($p['amount_refunded'] ?? 0) > 0) {
                $pair('Refunded', InvoiceFormat::inr((float) $p['amount_refunded']), 8);
            }
            $pair('Balance due', InvoiceFormat::inr((float) ($p['balance_due'] ?? 0)), 8, true);
        } else {
            $refund = (array) ($doc['refund'] ?? []);
            $left('Against invoice: ' . (string) ($doc['original_invoice_number'] ?? ''), 8);
            $pair('CREDIT (Rs.)', InvoiceFormat::inr((float) ($refund['amount'] ?? 0)), 10, true);
            if (!empty($refund['reason'])) {
                $left('Reason: ' . $refund['reason'], 7.5);
            }
            if (!empty($refund['reference'])) {
                $left('Ref: ' . $refund['reference'], 7.5);
            }
            $left('Status: ' . (string) ($refund['status_label'] ?? ''), 7.5);
        }
        $rule();
        $center((string) ($this->s['options']['footer'] ?? ''), 7.5, true);
        $center('Computer-generated document.', 6.5, false);
        $this->thermalHeight = $y;

        return $d->output();
    }
}
