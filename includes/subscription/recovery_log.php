<?php

declare(strict_types=1);

final class SubscriptionRecoveryLog
{
    /** @param array<string, mixed> $detail */
    public static function record(string $operation, string $referenceKey, string $status, array $detail = []): void
    {
        if (!Install::isSchemaReady()) {
            error_log('[subscription_recovery] ' . $operation . ' ' . $referenceKey . ' ' . $status);

            return;
        }
        try {
            Install::ensureConnection();
            Database::pdo()->prepare(
                'INSERT INTO subscription_recovery_log (operation, reference_key, status, detail_json) VALUES (?, ?, ?, ?)'
            )->execute([
                $operation,
                $referenceKey,
                $status,
                $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('[subscription_recovery] ' . $operation . ': ' . $e->getMessage());
        }
        if ($status === 'failed') {
            AuditLog::write('subscription.recovery_failed', 'subscription_day', $referenceKey, $detail);
        }
    }
}
