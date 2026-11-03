<?php

declare(strict_types=1);

final class TestimonialService
{
    /** @return list<array<string, mixed>> */
    public static function listForAdmin(?string $status = null, int $limit = 100): array
    {
        Install::ensureConnection();
        $sql = 'SELECT * FROM testimonials';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY sort_order ASC, created_at DESC LIMIT ?';
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function featuredPublic(int $limit = 6): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT customer_display_name, body FROM testimonials
             WHERE status = 'approved' AND featured = 1 AND consent_recorded_at IS NOT NULL
             ORDER BY sort_order ASC, created_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{ok: bool, message?: string, id?: int}
     */
    public static function saveAdmin(
        ?int $id,
        string $displayName,
        string $body,
        bool $consentRecorded,
        ?string $consentNote,
        bool $featured,
        int $sortOrder
    ): array {
        $displayName = trim($displayName);
        $body = trim($body);
        if ($displayName === '' || $body === '') {
            return ['ok' => false, 'message' => 'Name and story are required.'];
        }
        if (!$consentRecorded) {
            return ['ok' => false, 'message' => 'Customer consent must be recorded before saving.'];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($id !== null && $id > 0) {
            $pdo->prepare(
                'UPDATE testimonials SET customer_display_name = ?, body = ?, consent_recorded_at = NOW(),
                 consent_note = ?, featured = ?, sort_order = ?, updated_at = NOW() WHERE id = ?'
            )->execute([$displayName, $body, $consentNote, $featured ? 1 : 0, $sortOrder, $id]);
            AuditLog::write('testimonial.update', 'testimonial', (string) $id);

            return ['ok' => true, 'id' => $id];
        }
        $pdo->prepare(
            'INSERT INTO testimonials
             (customer_display_name, body, status, consent_recorded_at, consent_note, featured, sort_order, created_by_admin_id)
             VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)'
        )->execute([
            $displayName,
            $body,
            ReviewStatus::PENDING,
            $consentNote,
            $featured ? 1 : 0,
            $sortOrder,
            AdminAuth::id(),
        ]);
        $newId = (int) $pdo->lastInsertId();
        AuditLog::write('testimonial.create', 'testimonial', (string) $newId);

        return ['ok' => true, 'id' => $newId];
    }

    /** @return array{ok: bool, message?: string} */
    public static function moderate(int $id, string $action, ?string $rejectReason = null): array
    {
        $action = strtolower($action);
        if (!in_array($action, ['approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'Invalid action.'];
        }
        if ($action === 'reject' && trim((string) $rejectReason) === '') {
            return ['ok' => false, 'message' => 'Rejection reason required.'];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($action === 'approve') {
            $pdo->prepare('UPDATE testimonials SET status = ?, rejection_reason = NULL, updated_at = NOW() WHERE id = ?')
                ->execute([ReviewStatus::APPROVED, $id]);
        } else {
            $pdo->prepare('UPDATE testimonials SET status = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ?')
                ->execute([ReviewStatus::REJECTED, trim((string) $rejectReason), $id]);
        }
        AuditLog::write('testimonial.' . $action, 'testimonial', (string) $id);

        return ['ok' => true];
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteForAdmin(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Testimonial not found.'];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id FROM testimonials WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => false, 'message' => 'Testimonial not found.'];
        }
        try {
            $pdo->prepare('DELETE FROM testimonials WHERE id = ?')->execute([$id]);
            AuditLog::write('testimonial.delete', 'testimonial', (string) $id);

            return ['ok' => true, 'message' => 'Testimonial deleted.'];
        } catch (Throwable $e) {
            error_log('TestimonialService::deleteForAdmin: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not delete testimonial.'];
        }
    }
}
