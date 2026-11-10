<?php

declare(strict_types=1);

/**
 * Server-side admin lists for Firebase push screens (pagination + filters only).
 */
final class PushAdminList
{
    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function projects(string $q, int $page, int $perPage): array
    {
        if (!PushSchema::tableReady('firebase_projects')) {
            return self::emptyPage($page, $perPage);
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where .= ' AND (name LIKE ? OR firebase_project_id LIKE ?)';
            $params = [$like, $like];
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM firebase_projects WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $page = self::clampPage($page, $total, $perPage);
        $offset = ($page - 1) * $perPage;
        $platformCols = FirebaseProjectForm::platformColumnsReady()
            ? ', platform_web, platform_android'
            : '';
        $sql = "SELECT id, name, firebase_project_id, status, is_active_global,
                       web_app_id, web_api_key, vapid_public_key, android_app_id, android_package_name
                       {$platformCols},
                       last_test_status, last_test_at, updated_at
                FROM firebase_projects WHERE {$where}
                ORDER BY is_active_global DESC, name ASC
                LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable $e) {
            $sql = "SELECT id, name, firebase_project_id, status, is_active_global,
                           web_app_id, android_app_id, last_test_status, last_test_at, updated_at
                    FROM firebase_projects WHERE {$where}
                    ORDER BY is_active_global DESC, name ASC
                    LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return self::pack($rows, $total, $page, $perPage);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function devices(string $q, string $platform, string $status, int $page, int $perPage): array
    {
        if (!PushSchema::tableReady('device_push_registrations')) {
            return self::emptyPage($page, $perPage);
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $where = '1=1';
        $params = [];
        if ($platform !== '' && in_array($platform, ['web', 'android'], true)) {
            $where .= ' AND d.platform = ?';
            $params[] = $platform;
        }
        if ($status !== '' && in_array($status, ['active', 'revoked', 'invalid'], true)) {
            $where .= ' AND d.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            if (ctype_digit($q)) {
                $where .= ' AND (d.customer_id = ? OR d.id = ? OR p.name LIKE ? OR d.fcm_token LIKE ?)';
                $params[] = (int) $q;
                $params[] = (int) $q;
                $params[] = $like;
                $params[] = $like;
            } else {
                $where .= ' AND (p.name LIKE ? OR d.fcm_token LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            }
        }
        $countSql = "SELECT COUNT(*) FROM device_push_registrations d
                     INNER JOIN firebase_projects p ON p.id = d.firebase_project_id
                     WHERE {$where}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $page = self::clampPage($page, $total, $perPage);
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT d.id, d.customer_id, d.platform, d.target_type, d.status, d.permission_status,
                       d.last_refresh_at, d.created_at, LEFT(d.fcm_token, 12) AS token_prefix,
                       p.name AS project_name, p.is_active_global
                FROM device_push_registrations d
                INNER JOIN firebase_projects p ON p.id = d.firebase_project_id
                WHERE {$where}
                ORDER BY d.id DESC
                LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return self::pack($rows, $total, $page, $perPage);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function logs(string $q, string $status, int $page, int $perPage): array
    {
        if (!PushSchema::tableReady('push_notification_logs')) {
            return self::emptyPage($page, $perPage);
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $where = '1=1';
        $params = [];
        if ($status !== '' && in_array($status, ['pending', 'sent', 'failed', 'skipped', 'dry_run'], true)) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where .= ' AND (notification_type LIKE ? OR idempotency_key LIKE ? OR title_redacted LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM push_notification_logs WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $page = self::clampPage($page, $total, $perPage);
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT id, event_id, platform, customer_id, notification_type, status, http_status,
                       failure_category, idempotency_key, title_redacted, created_at
                FROM push_notification_logs WHERE {$where}
                ORDER BY id DESC
                LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return self::pack($rows, $total, $page, $perPage);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function templates(string $q, string $activeFilter, int $page, int $perPage): array
    {
        if (!PushSchema::tableReady('push_notification_templates')) {
            return self::emptyPage($page, $perPage);
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $where = '1=1';
        $params = [];
        if ($activeFilter === 'active') {
            $where .= ' AND is_active = 1';
        } elseif ($activeFilter === 'inactive') {
            $where .= ' AND is_active = 0';
        }
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where .= ' AND (template_key LIKE ? OR event_type LIKE ? OR title_template LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM push_notification_templates WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $page = self::clampPage($page, $total, $perPage);
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT id, template_key, event_type, title_template, body_template,
                       click_url_template, is_active, updated_at
                FROM push_notification_templates WHERE {$where}
                ORDER BY event_type, template_key
                LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return self::pack($rows, $total, $page, $perPage);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    private static function emptyPage(int $page, int $perPage): array
    {
        return self::pack([], 0, max(1, $page), $perPage);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    private static function pack(array $rows, int $total, int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    private static function clampPage(int $page, int $total, int $perPage): int
    {
        $page = max(1, $page);
        if ($total <= 0) {
            return 1;
        }
        $maxPage = max(1, (int) ceil($total / max(1, $perPage)));

        return min($page, $maxPage);
    }

    /** Build redirect query preserving list filters (exclude page for post-action clamp). */
    public static function listRedirectQuery(array $get, ?int $pageOverride = null): string
    {
        $keep = ['q', 'platform', 'status', 'active', 'per_page', 'page', 'edit', 'create'];
        $out = [];
        foreach ($keep as $k) {
            if (!isset($get[$k]) || (string) $get[$k] === '') {
                continue;
            }
            $out[$k] = (string) $get[$k];
        }
        if ($pageOverride !== null) {
            $out['page'] = (string) $pageOverride;
        }

        return $out === [] ? '' : '?' . http_build_query($out);
    }
}
