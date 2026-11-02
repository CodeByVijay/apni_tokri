<?php

declare(strict_types=1);

final class SubscriptionAudit
{
    /** @param array<string, mixed> $detail */
    public static function log(
        int $subscriptionId,
        ?string $deliveryDate,
        string $actorType,
        ?int $actorId,
        string $action,
        array $detail = []
    ): void {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'INSERT INTO subscription_selection_audit (customer_subscription_id, delivery_date, actor_type, actor_id, action, detail_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $subscriptionId,
            $deliveryDate,
            $actorType,
            $actorId,
            $action,
            json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
