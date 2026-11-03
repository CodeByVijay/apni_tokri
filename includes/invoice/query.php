<?php

declare(strict_types=1);

/** Read-side queries for invoice listings (admin + customer). */
final class InvoiceQuery
{
    public const PER_PAGE = 20;

    private const SORTS = [
        'date' => 'i.invoice_date %s, i.id %s',
        'number' => 'i.invoice_number %s',
        'amount' => 'i.grand_total %s, i.id DESC',
        'customer' => 'c.name %s, i.id DESC',
    ];

    /**
     * @param array<string, mixed> $f q, customer, from, to, type, payment, email, sort, dir, page
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function adminSearch(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . self::likeEscape($q) . '%';
            $where[] = '(i.invoice_number LIKE ? OR o.order_number LIKE ?)';
            array_push($args, $like, $like);
        }
        $cust = trim((string) ($f['customer'] ?? ''));
        if ($cust !== '') {
            $like = '%' . self::likeEscape($cust) . '%';
            $where[] = '(c.name LIKE ? OR c.mobile LIKE ? OR c.email LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $v = trim((string) ($f[$k] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
                $where[] = 'i.invoice_date ' . $op . ' ?';
                $args[] = $v;
            }
        }
        $type = (string) ($f['type'] ?? '');
        if (in_array($type, [InvoiceSettings::DOC_RETAIL, InvoiceSettings::DOC_CREDIT_NOTE], true)) {
            $where[] = 'i.document_type = ?';
            $args[] = $type;
        }
        $payment = (string) ($f['payment'] ?? '');
        if (in_array($payment, ['paid', 'partially_refunded', 'refunded'], true)) {
            $where[] = 'o.payment_status = ?';
            $args[] = $payment;
        }
        $email = (string) ($f['email'] ?? '');
        $latest = '(SELECT l.status FROM invoice_email_log l WHERE l.invoice_id = i.id ORDER BY l.id DESC LIMIT 1)';
        if ($email === 'none') {
            $where[] = $latest . ' IS NULL';
        } elseif (in_array($email, ['sent', 'failed', 'pending'], true)) {
            $where[] = $latest . ' = ?';
            $args[] = $email;
        }

        $sortKey = isset(self::SORTS[(string) ($f['sort'] ?? '')]) ? (string) $f['sort'] : 'date';
        $dir = strtolower((string) ($f['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $order = sprintf(self::SORTS[$sortKey], $dir, $dir);

        $from = 'FROM invoices i
                 JOIN orders o ON o.id = i.order_id
                 LEFT JOIN customers c ON c.id = i.customer_id
                 WHERE ' . implode(' AND ', $where);
        $pdo = Database::pdo();
        $cnt = $pdo->prepare('SELECT COUNT(*) ' . $from);
        $cnt->execute($args);
        $total = (int) $cnt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($f['page'] ?? 1)));

        $sql = 'SELECT i.id, i.public_ref, i.invoice_number, i.document_type, i.invoice_date, i.grand_total, i.order_id,
                       o.order_number, o.payment_status, c.name AS customer_name, c.mobile AS customer_mobile, c.email AS customer_email,
                       ' . $latest . ' AS email_status '
            . $from . ' ORDER BY ' . $order . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE);
        $st = $pdo->prepare($sql);
        $st->execute($args);

        return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return list<array<string, mixed>> */
    public static function forCustomer(int $customerId, int $page = 1, int $perPage = 20): array
    {
        $st = Database::pdo()->prepare(
            'SELECT i.id, i.public_ref, i.invoice_number, i.document_type, i.invoice_date, i.grand_total, o.order_number
             FROM invoices i JOIN orders o ON o.id = i.order_id
             WHERE i.customer_id = ? ORDER BY i.invoice_date DESC, i.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (max(0, $page - 1) * (int) $perPage)
        );
        $st->execute([$customerId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function countForCustomer(int $customerId): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE customer_id = ?');
        $st->execute([$customerId]);

        return (int) $st->fetchColumn();
    }

    /**
     * Retail invoice refs for a set of order ids (customer-scoped), keyed by order id.
     *
     * @param list<int> $orderIds
     * @return array<int, string> order_id => public_ref
     */
    public static function refsForOrders(array $orderIds, int $customerId): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds), static function ($v) {
            return $v > 0;
        }));
        if ($orderIds === [] || $customerId <= 0) {
            return [];
        }
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT order_id, public_ref FROM invoices WHERE customer_id = ? AND document_type = 'retail_invoice' AND order_id IN ($in)"
        );
        $st->execute(array_merge([$customerId], $orderIds));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['order_id']] = (string) $r['public_ref'];
        }

        return $out;
    }

    /**
     * @param list<string> $orderNumbers
     * @return array<string, string> order_number => retail invoice public_ref
     */
    public static function refsForOrderNumbers(array $orderNumbers, int $customerId): array
    {
        $orderNumbers = array_values(array_unique(array_filter(array_map('strval', $orderNumbers), static function ($v) {
            return $v !== '';
        })));
        if ($orderNumbers === [] || $customerId <= 0) {
            return [];
        }
        $in = implode(',', array_fill(0, count($orderNumbers), '?'));
        $st = Database::pdo()->prepare(
            "SELECT o.order_number, i.public_ref FROM invoices i JOIN orders o ON o.id = i.order_id
             WHERE i.customer_id = ? AND i.document_type = 'retail_invoice' AND o.order_number IN ($in)"
        );
        $st->execute(array_merge([$customerId], $orderNumbers));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string) $r['order_number']] = (string) $r['public_ref'];
        }

        return $out;
    }

    private static function likeEscape(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
