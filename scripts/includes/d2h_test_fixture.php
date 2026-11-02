<?php

declare(strict_types=1);

/**
 * Idempotent cleanup for scripts/test-delivery-d2-hardening.php fixtures only.
 */
function d2h_test_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $pdo->exec(
            'DELETE pce FROM payment_completion_exceptions pce
             INNER JOIN orders o ON o.id = pce.order_id
             WHERE o.order_number LIKE ' . $pdo->quote('D2H-%')
        );

        $pdo->exec(
            'DELETE p FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE o.order_number LIKE ' . $pdo->quote('D2H-%')
        );

        $pdo->exec(
            'DELETE dsr FROM delivery_slot_reservations dsr
             INNER JOIN orders o ON o.id = dsr.order_id
             WHERE o.order_number LIKE ' . $pdo->quote('D2H-%')
        );

        $pdo->exec(
            'DELETE dsr FROM delivery_slot_reservations dsr
             INNER JOIN delivery_slots s ON s.id = dsr.delivery_slot_id
             WHERE s.slug LIKE ' . $pdo->quote('d2h_%')
        );

        $pdo->exec('DELETE FROM orders WHERE order_number LIKE ' . $pdo->quote('D2H-%'));

        $pdo->exec('DELETE FROM delivery_slots WHERE slug LIKE ' . $pdo->quote('d2h_%'));
    });
}
