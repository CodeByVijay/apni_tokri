<?php

declare(strict_types=1);

final class AuditLog
{
    public static function write(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $payload = null
    ): void {
        if (!Install::isSchemaReady()) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'INSERT INTO admin_audit_logs (admin_user_id, action, entity_type, entity_id, ip_address, user_agent, payload_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $json = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
            $stmt->execute([
                AdminAuth::id(),
                $action,
                $entityType,
                $entityId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                $json,
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog failed: ' . $e->getMessage());
        }
    }
}
