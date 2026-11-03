<?php

declare(strict_types=1);

final class ReviewService
{
    /** @return list<string> */
    public static function reasonCodes(): array
    {
        return ['quality', 'damaged', 'wrong', 'missing', 'delivery', 'duplicate_payment', 'cancellation', 'other'];
    }

    /** @return list<array<string, mixed>> */
    public static function eligibleOrderItems(int $customerId, string $orderNumber): array
    {
        $order = OrderFulfillmentService::orderRowForCustomer($orderNumber, $customerId);
        if ($order === null || !OrderFulfillmentService::isOrderDelivered((int) $order['id'])) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT oi.id, oi.product_name, oi.variant_name, oi.quantity, oi.variant_id,
                    pv.product_id
             FROM order_items oi
             LEFT JOIN product_variants pv ON pv.id = oi.variant_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([(int) $order['id']]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemId = (int) $row['id'];
            $existing = $pdo->prepare('SELECT id, status FROM product_reviews WHERE order_item_id = ? LIMIT 1');
            $existing->execute([$itemId]);
            $rev = $existing->fetch(PDO::FETCH_ASSOC);
            if ($rev && (string) $rev['status'] === ReviewStatus::APPROVED) {
                continue;
            }
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $out[] = [
                'order_item_id' => $itemId,
                'product_id' => $productId,
                'product_name' => (string) $row['product_name'],
                'variant_name' => (string) $row['variant_name'],
                'quantity' => (int) $row['quantity'],
                'existing_review_id' => $rev ? (int) $rev['id'] : null,
                'existing_status' => $rev ? (string) $rev['status'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>>|null $photoFiles from $_FILES['photos']
     * @return array{ok: bool, message?: string, review_id?: int}
     */
    public static function submit(
        int $customerId,
        int $orderItemId,
        int $rating,
        string $body,
        ?string $title = null,
        ?array $photoFiles = null
    ): array {
        if ($rating < 1 || $rating > 5) {
            return ['ok' => false, 'message' => 'Choose a rating between 1 and 5 stars.'];
        }
        $body = trim($body);
        if ($body === '' || strlen($body) > 5000) {
            return ['ok' => false, 'message' => 'Please write a review (max 5000 characters).'];
        }
        try {
            return Database::transaction(static function () use (
                $customerId,
                $orderItemId,
                $rating,
                $body,
                $title,
                $photoFiles
            ): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT oi.*, o.customer_id, o.id AS order_pk, o.order_number
                     FROM order_items oi
                     INNER JOIN orders o ON o.id = oi.order_id
                     LEFT JOIN product_variants pv ON pv.id = oi.variant_id
                     WHERE oi.id = ? FOR UPDATE'
                );
                $stmt->execute([$orderItemId]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$item || (int) $item['customer_id'] !== $customerId) {
                    return ['ok' => false, 'message' => 'Item not found.'];
                }
                if (!OrderFulfillmentService::isOrderDelivered((int) $item['order_pk'])) {
                    return ['ok' => false, 'message' => 'Reviews are only available after delivery.'];
                }
                $productId = 0;
                if (!empty($item['variant_id'])) {
                    $p = $pdo->prepare('SELECT product_id FROM product_variants WHERE id = ?');
                    $p->execute([(int) $item['variant_id']]);
                    $productId = (int) ($p->fetchColumn() ?: 0);
                }
                if ($productId <= 0) {
                    return ['ok' => false, 'message' => 'This item cannot be reviewed.'];
                }

                $profile = CustomerService::profile();
                $displayName = trim((string) ($profile['name'] ?? 'Customer'));
                if ($displayName === '') {
                    $displayName = 'Customer';
                }

                $existing = $pdo->prepare('SELECT id FROM product_reviews WHERE order_item_id = ? LIMIT 1');
                $existing->execute([$orderItemId]);
                $reviewId = (int) ($existing->fetchColumn() ?: 0);
                if ($reviewId > 0) {
                    $pdo->prepare(
                        'UPDATE product_reviews SET rating = ?, title = ?, body = ?, status = ?, rejection_reason = NULL,
                         display_name = ?, verified_purchase = 1, updated_at = NOW() WHERE id = ? AND customer_id = ?'
                    )->execute([
                        $rating,
                        $title,
                        $body,
                        ReviewStatus::PENDING,
                        $displayName,
                        $reviewId,
                        $customerId,
                    ]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO product_reviews
                         (product_id, customer_id, display_name, order_id, order_item_id, rating, title, body,
                          verified_purchase, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
                    )->execute([
                        $productId,
                        $customerId,
                        $displayName,
                        (int) $item['order_pk'],
                        $orderItemId,
                        $rating,
                        $title,
                        $body,
                        ReviewStatus::PENDING,
                    ]);
                    $reviewId = (int) $pdo->lastInsertId();
                }

                if (is_array($photoFiles) && isset($photoFiles['name']) && is_array($photoFiles['name'])) {
                    $count = count($photoFiles['name']);
                    for ($i = 0; $i < $count && $i < 3; $i++) {
                        $file = [
                            'name' => $photoFiles['name'][$i] ?? '',
                            'type' => $photoFiles['type'][$i] ?? '',
                            'tmp_name' => $photoFiles['tmp_name'][$i] ?? '',
                            'error' => $photoFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                            'size' => $photoFiles['size'][$i] ?? 0,
                        ];
                        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        $stored = Upload::storeImage($file, 'reviews');
                        if ($stored['ok'] && !empty($stored['path'])) {
                            $pdo->prepare(
                                'INSERT INTO product_review_photos (review_id, file_path, sort_order) VALUES (?, ?, ?)'
                            )->execute([$reviewId, (string) $stored['path'], $i]);
                        }
                    }
                }

                return ['ok' => true, 'review_id' => $reviewId, 'message' => 'Review submitted for approval.'];
            });
        } catch (Throwable $e) {
            error_log('Review submit: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save review.'];
        }
    }

    /** @return list<array<string, mixed>> */
    public static function approvedForProduct(int $productId, int $limit = 20, int $offset = 0): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, (SELECT COUNT(*) FROM product_review_photos p WHERE p.review_id = r.id) AS photo_count
             FROM product_reviews r
             WHERE r.product_id = ? AND r.status = ?
             ORDER BY r.featured DESC, r.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $productId, PDO::PARAM_INT);
        $stmt->bindValue(2, ReviewStatus::APPROVED, PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['photos'] = self::photosForReview((int) $row['id']);
        }

        return $rows;
    }

    /** @return array{average: float, count: int, distribution: array<int, int>}|null */
    public static function ratingSummary(int $productId): ?array
    {
        if (!Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT rating, COUNT(*) AS c FROM product_reviews
             WHERE product_id = ? AND status = ? GROUP BY rating'
        );
        $stmt->execute([$productId, ReviewStatus::APPROVED]);
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $total = 0;
        $sum = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $star = (int) $r['rating'];
            $c = (int) $r['c'];
            if ($star >= 1 && $star <= 5) {
                $dist[$star] = $c;
                $total += $c;
                $sum += $star * $c;
            }
        }
        if ($total === 0) {
            return null;
        }

        return [
            'average' => round($sum / $total, 1),
            'count' => $total,
            'distribution' => $dist,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function photosForReview(int $reviewId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT file_path FROM product_review_photos WHERE review_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$reviewId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function listPending(int $limit = 50): array
    {
        $result = self::searchPendingForAdmin('', 1, min(100, max(1, $limit)), 'created_at', 'asc');

        return $result['rows'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchPendingForAdmin(
        string $query,
        int $page,
        int $perPage,
        string $sortColumn = 'created_at',
        string $sortDirection = 'asc'
    ): array {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['r.status = ?'];
        $params = [ReviewStatus::PENDING];
        $q = trim($query);
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where[] = '(p.name LIKE ? OR r.body LIKE ? OR CAST(r.rating AS CHAR) LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'created_at' => 'r.created_at',
            'rating' => 'r.rating',
            'product_name' => 'p.name',
        ];
        $orderSql = AdminServerList::orderBySql($sortColumn, $sortDirection, $sortMap, 'created_at');
        $from = ' FROM product_reviews r INNER JOIN products p ON p.id = r.product_id WHERE ' . $whereSql;
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT r.*, p.name AS product_name' . $from . ' ORDER BY ' . $orderSql . ' LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /** @return array{ok: bool, message?: string} */
    public static function moderate(int $reviewId, string $action, ?string $rejectReason = null, ?string $adminReply = null): array
    {
        $adminUserId = AdminAuth::id();
        if ($adminUserId === null || $adminUserId <= 0) {
            return ['ok' => false, 'message' => 'Admin authentication required.'];
        }
        if (!self::moderationHistoryTableReady()) {
            return ['ok' => false, 'message' => 'Review moderation history is not available.'];
        }

        $action = strtolower($action);
        if (!in_array($action, ['approve', 'reject', 'reply'], true)) {
            return ['ok' => false, 'message' => 'Invalid action.'];
        }
        if ($action === 'reject' && trim((string) $rejectReason) === '') {
            return ['ok' => false, 'message' => 'Rejection reason is required.'];
        }

        try {
            return Database::transaction(static function () use (
                $reviewId,
                $action,
                $rejectReason,
                $adminReply,
                $adminUserId
            ): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare('SELECT * FROM product_reviews WHERE id = ? LIMIT 1 FOR UPDATE');
                $stmt->execute([$reviewId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return ['ok' => false, 'message' => 'Review not found.'];
                }
                $previousStatus = (string) ($row['status'] ?? '');

                if ($action === 'approve') {
                    $pdo->prepare(
                        'UPDATE product_reviews SET status = ?, rejection_reason = NULL, updated_at = NOW() WHERE id = ?'
                    )->execute([ReviewStatus::APPROVED, $reviewId]);
                    self::appendModerationHistory($pdo, $reviewId, $adminUserId, 'approve', $previousStatus, ReviewStatus::APPROVED, null, null);
                    AuditLog::write('review.approve', 'product_review', (string) $reviewId);
                } elseif ($action === 'reject') {
                    $reason = trim((string) $rejectReason);
                    $pdo->prepare(
                        'UPDATE product_reviews SET status = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([ReviewStatus::REJECTED, $reason, $reviewId]);
                    self::appendModerationHistory(
                        $pdo,
                        $reviewId,
                        $adminUserId,
                        'reject',
                        $previousStatus,
                        ReviewStatus::REJECTED,
                        $reason,
                        null
                    );
                    AuditLog::write('review.reject', 'product_review', (string) $reviewId, ['reason' => $reason]);
                } else {
                    $reply = trim((string) $adminReply);
                    if ($reply === '') {
                        return ['ok' => false, 'message' => 'Reply cannot be empty.'];
                    }
                    $pdo->prepare(
                        'UPDATE product_reviews SET admin_reply = ?, admin_reply_at = NOW(), admin_reply_by = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([$reply, $adminUserId, $reviewId]);
                    self::appendModerationHistory(
                        $pdo,
                        $reviewId,
                        $adminUserId,
                        'admin_reply',
                        $previousStatus,
                        $previousStatus,
                        null,
                        ['admin_reply' => $reply]
                    );
                    AuditLog::write('review.reply', 'product_review', (string) $reviewId);
                }

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            error_log('Review moderate: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not update review.'];
        }
    }

    /** @return list<array<string, mixed>> Admin-only read (Phase C.2.2 UI will consume). */
    public static function moderationHistoryForReview(int $reviewId): array
    {
        if (AdminAuth::id() === null) {
            return [];
        }
        if (!self::moderationHistoryTableReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT h.*, u.email AS admin_email, u.name AS admin_name,
                    p.name AS product_name, r.rating AS review_rating
             FROM review_moderation_history h
             INNER JOIN product_reviews r ON r.id = h.review_id
             INNER JOIN products p ON p.id = r.product_id
             LEFT JOIN admin_users u ON u.id = h.admin_user_id
             WHERE h.review_id = ?
             ORDER BY h.created_at ASC, h.id ASC'
        );
        $stmt->execute([$reviewId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return self::presentModerationHistoryRow($row);
        }, $rows);
    }

    /** @return list<string> */
    public static function moderationHistoryActionOptions(): array
    {
        return ['approve', 'reject', 'admin_reply'];
    }

    /**
     * @param array<string, mixed> $input Raw GET/query input
     * @return array{action?: string, admin_user_id?: int, review_id?: int, date_from?: string, date_to?: string}
     */
    public static function normalizeModerationHistoryFilters(array $input): array
    {
        $out = [];
        $action = isset($input['action']) ? strtolower(trim((string) $input['action'])) : '';
        if ($action !== '' && in_array($action, self::moderationHistoryActionOptions(), true)) {
            $out['action'] = $action;
        }
        if (isset($input['admin_user_id']) && (string) $input['admin_user_id'] !== '') {
            $adminId = (int) $input['admin_user_id'];
            if ($adminId > 0) {
                $out['admin_user_id'] = $adminId;
            }
        }
        if (isset($input['review_id']) && (string) $input['review_id'] !== '') {
            $reviewId = (int) $input['review_id'];
            if ($reviewId > 0) {
                $out['review_id'] = $reviewId;
            }
        }
        foreach (['date_from' => 'date_from', 'date_to' => 'date_to'] as $key => $outKey) {
            if (!isset($input[$key])) {
                continue;
            }
            $ymd = trim((string) $input[$key]);
            if ($ymd === '') {
                continue;
            }
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
            if ($d !== false && $d->format('Y-m-d') === $ymd) {
                $out[$outKey] = $ymd;
            }
        }
        if (isset($out['date_from'], $out['date_to']) && $out['date_from'] > $out['date_to']) {
            unset($out['date_to']);
        }

        return $out;
    }

    /**
     * @param array{action?: string, admin_user_id?: int, review_id?: int, date_from?: string, date_to?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public static function searchModerationHistoryForAdmin(array $filters, int $page = 1, int $perPage = 25): array
    {
        $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage];
        if (AdminAuth::id() === null || !self::moderationHistoryTableReady()) {
            return $empty;
        }
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];
        if (!empty($filters['action'])) {
            $where[] = 'h.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['admin_user_id'])) {
            $where[] = 'h.admin_user_id = ?';
            $params[] = (int) $filters['admin_user_id'];
        }
        if (!empty($filters['review_id'])) {
            $where[] = 'h.review_id = ?';
            $params[] = (int) $filters['review_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'h.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'h.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        Install::ensureConnection();
        $pdo = Database::pdo();
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM review_moderation_history h WHERE ' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listSql =
            'SELECT h.*, u.email AS admin_email, u.name AS admin_name,
                    p.name AS product_name, r.rating AS review_rating
             FROM review_moderation_history h
             INNER JOIN product_reviews r ON r.id = h.review_id
             INNER JOIN products p ON p.id = r.product_id
             LEFT JOIN admin_users u ON u.id = h.admin_user_id
             WHERE ' . $whereSql . '
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => array_map(static function (array $row): array {
            return self::presentModerationHistoryRow($row);
        }, $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return list<array{id: int, label: string}> */
    public static function moderationHistoryAdminFilterOptions(): array
    {
        if (AdminAuth::id() === null || !self::moderationHistoryTableReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT DISTINCT u.id, u.name, u.email
             FROM review_moderation_history h
             INNER JOIN admin_users u ON u.id = h.admin_user_id
             ORDER BY u.name, u.email'
        );
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string) ($row['name'] ?? ''));
            if ($label === '') {
                $label = (string) ($row['email'] ?? 'Admin #' . $id);
            }
            $out[] = ['id' => $id, 'label' => $label];
        }

        return $out;
    }

    public static function moderationActionLabel(string $action): string
    {
        if ($action === 'approve') {
            return 'Approved';
        }
        if ($action === 'reject') {
            return 'Rejected';
        }
        if ($action === 'admin_reply') {
            return 'Admin reply';
        }

        return ucfirst(str_replace('_', ' ', $action));
    }

    /** @param array<string, mixed> $row */
    public static function presentModerationHistoryRow(array $row): array
    {
        $action = (string) ($row['action'] ?? '');
        $actor = trim((string) ($row['admin_name'] ?? ''));
        if ($actor === '') {
            $actor = trim((string) ($row['admin_email'] ?? ''));
        }
        if ($actor === '' && !empty($row['admin_user_id'])) {
            $actor = 'Admin #' . (int) $row['admin_user_id'];
        }
        if ($actor === '' && (string) ($row['actor_type'] ?? '') === 'system') {
            $actor = 'System';
        }

        return array_merge($row, [
            'action_label' => self::moderationActionLabel($action),
            'actor_display' => $actor !== '' ? $actor : '—',
            'admin_reply_display' => self::adminReplyFromModerationMetadata($row['metadata_json'] ?? null),
            'reason_display' => trim((string) ($row['reason'] ?? '')),
        ]);
    }

    /** @param mixed $metadataJson */
    public static function adminReplyFromModerationMetadata($metadataJson): ?string
    {
        if ($metadataJson === null || $metadataJson === '') {
            return null;
        }
        if (is_array($metadataJson)) {
            $data = $metadataJson;
        } else {
            $data = json_decode((string) $metadataJson, true);
        }
        if (!is_array($data) || !isset($data['admin_reply']) || !is_string($data['admin_reply'])) {
            return null;
        }
        $reply = trim($data['admin_reply']);

        return $reply !== '' ? $reply : null;
    }

    /** @param array<string, mixed>|null $metadata */
    public static function appendModerationHistory(
        PDO $pdo,
        int $reviewId,
        int $adminUserId,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $reason,
        ?array $metadata
    ): void {
        if (self::$testThrowOnModerationHistoryInsert) {
            self::$testThrowOnModerationHistoryInsert = false;
            throw new RuntimeException('Simulated moderation history insert failure.');
        }
        $pdo->prepare(
            'INSERT INTO review_moderation_history
             (review_id, actor_type, admin_user_id, action, previous_status, new_status, reason, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $reviewId,
            'admin',
            $adminUserId,
            $action,
            $previousStatus,
            $newStatus,
            $reason,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** @internal Automated tests only */
    public static function testThrowOnNextModerationHistoryInsert(bool $enabled): void
    {
        self::$testThrowOnModerationHistoryInsert = $enabled;
    }

    /** @var bool */
    private static $testThrowOnModerationHistoryInsert = false;

    private static function moderationHistoryTableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute(['review_moderation_history']);
            $ready = (int) $stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            $ready = false;
        }

        return $ready;
    }

    /** @return list<array<string, mixed>> */
    public static function featuredTestimonialsFromReviews(int $limit = 6): array
    {
        return [];
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteForAdmin(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Review not found.'];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id FROM product_reviews WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => false, 'message' => 'Review not found.'];
        }
        try {
            if (self::moderationHistoryTableReady()) {
                $pdo->prepare('DELETE FROM review_moderation_history WHERE review_id = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM product_reviews WHERE id = ?')->execute([$id]);
            AuditLog::write('review.delete', 'review', (string) $id);

            return ['ok' => true, 'message' => 'Review deleted.'];
        } catch (Throwable $e) {
            error_log('ReviewService::deleteForAdmin: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not delete review.'];
        }
    }
}
