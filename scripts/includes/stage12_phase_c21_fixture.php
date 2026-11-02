<?php

declare(strict_types=1);

/**
 * Scoped fixtures for scripts/test-trust-stage12-phase-c2-1.php (AT-C21-* reviews only).
 */
function stage12_c21_cleanup(PDO $pdo): void
{
    Database::transaction(static function () use ($pdo): void {
        $ids = $pdo->query(
            "SELECT id FROM product_reviews WHERE body LIKE 'AT-C21-%' OR display_name = 'AT-C21'"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare('DELETE FROM review_moderation_history WHERE review_id IN (' . $placeholders . ')')
            ->execute($ids);
        $pdo->prepare('DELETE FROM product_review_photos WHERE review_id IN (' . $placeholders . ')')
            ->execute($ids);
        $pdo->prepare('DELETE FROM product_reviews WHERE id IN (' . $placeholders . ')')
            ->execute($ids);
    });
}

/** @return array{review_id: int, product_id: int, customer_id: int} */
function stage12_c21_insert_pending_review(PDO $pdo): array
{
    $customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    if ($customerId <= 0) {
        $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([
            '98' . random_int(10000000, 99999999),
            'AT-C21',
        ]);
        $customerId = (int) $pdo->lastInsertId();
    }
    $productId = (int) ($pdo->query(
        "SELECT id FROM products WHERE status = 'published' ORDER BY id LIMIT 1"
    )->fetchColumn() ?: 0);
    if ($productId <= 0) {
        throw new RuntimeException('No published product for C21 fixture.');
    }
    $body = 'AT-C21-' . strtoupper(substr(Security::randomToken(4), 0, 8));
    $pdo->prepare(
        'INSERT INTO product_reviews
         (product_id, customer_id, display_name, rating, body, verified_purchase, status)
         VALUES (?, ?, ?, 5, ?, 1, ?)'
    )->execute([$productId, $customerId, 'AT-C21', $body, ReviewStatus::PENDING]);
    $reviewId = (int) $pdo->lastInsertId();

    return ['review_id' => $reviewId, 'product_id' => $productId, 'customer_id' => $customerId];
}

function stage12_c21_admin_session(int $adminUserId): void
{
    $_SESSION['admin_user_id'] = $adminUserId;
}

function stage12_c21_clear_admin_session(): void
{
    unset($_SESSION['admin_user_id'], $_SESSION['admin_user_email'], $_SESSION['admin_user_name']);
}
