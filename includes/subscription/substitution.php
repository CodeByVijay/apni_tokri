<?php

declare(strict_types=1);

final class SubscriptionSubstitutionService
{
    /**
     * Create customer-facing substitution proposals when stock is insufficient (never silent replace).
     *
     * @param list<array<string, mixed>> $basketItems
     * @return array{ok: bool, created: int, message?: string}
     */
    public static function proposeForStockShortage(
        int $dayId,
        array $basketItems,
        PDO $pdo
    ): array {
        $created = 0;
        foreach ($basketItems as $item) {
            $vid = (int) $item['variant_id'];
            $needQty = (int) round((float) ($item['qty'] ?? 0));
            if ($needQty <= 0) {
                continue;
            }
            $stmt = $pdo->prepare(
                'SELECT id, stock_qty, is_active FROM product_variants WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$vid]);
            $orig = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orig || !(int) $orig['is_active']) {
                $alt = self::findAlternative($pdo, $vid, $needQty);
                if ($alt === null) {
                    continue;
                }
                self::insertProposal($pdo, $dayId, $item, $alt);
                $created++;

                continue;
            }
            $heldOther = (int) InventoryService::subscriptionHeldQty($vid) - self::heldOnDay($pdo, $dayId, $vid);
            $available = (int) $orig['stock_qty'] - max(0, $heldOther);
            if ($available >= $needQty) {
                continue;
            }
            $alt = self::findAlternative($pdo, $vid, $needQty);
            if ($alt === null) {
                continue;
            }
            $existing = $pdo->prepare(
                "SELECT id FROM subscription_basket_substitutions
                 WHERE subscription_delivery_day_id = ? AND original_variant_id = ? AND status = 'pending_customer' LIMIT 1"
            );
            $existing->execute([$dayId, $vid]);
            if ($existing->fetchColumn()) {
                continue;
            }
            self::insertProposal($pdo, $dayId, $item, $alt);
            $created++;
        }

        return ['ok' => true, 'created' => $created];
    }

    /** @return list<array<string, mixed>> */
    public static function pendingForDay(int $dayId, int $customerId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT s.*, d.customer_subscription_id, cs.customer_id
             FROM subscription_basket_substitutions s
             INNER JOIN subscription_delivery_days d ON d.id = s.subscription_delivery_day_id
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             WHERE s.subscription_delivery_day_id = ? AND cs.customer_id = ?
             AND s.status IN ('pending_customer', 'approved')
             ORDER BY s.id"
        );
        $stmt->execute([$dayId, $customerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{ok: bool, message?: string, requires_payment?: bool, checkout?: array<string, mixed>}
     */
    public static function customerDecision(int $substitutionId, int $customerId, string $decision, string $rejectResolution = ''): array
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'Invalid decision.'];
        }

        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT s.*, cs.customer_id, cs.id AS customer_subscription_id, cs.plan_snapshot_json, d.id AS day_id
             FROM subscription_basket_substitutions s
             INNER JOIN subscription_delivery_days d ON d.id = s.subscription_delivery_day_id
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([$substitutionId]);
        $preview = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$preview || (int) $preview['customer_id'] !== $customerId) {
            return ['ok' => false, 'message' => 'Substitution not found.'];
        }
        if ((string) $preview['status'] !== 'pending_customer') {
            return ['ok' => true];
        }
        if ($decision === 'approve' && (float) $preview['price_difference_inr'] > 0.01) {
            $init = SubscriptionSubstitutionPaymentService::initiateForSubstitution($substitutionId, $customerId);
            if (!$init['ok']) {
                return ['ok' => false, 'message' => $init['error'] ?? 'Could not start payment.'];
            }

            return [
                'ok' => true,
                'requires_payment' => true,
                'checkout' => $init['checkout'] ?? [],
            ];
        }

        try {
            return Database::transaction(static function () use ($substitutionId, $customerId, $decision, $rejectResolution): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    "SELECT s.*, cs.customer_id, cs.id AS customer_subscription_id, cs.plan_snapshot_json, d.id AS day_id
                     FROM subscription_basket_substitutions s
                     INNER JOIN subscription_delivery_days d ON d.id = s.subscription_delivery_day_id
                     INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
                     WHERE s.id = ? FOR UPDATE"
                );
                $stmt->execute([$substitutionId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int) $row['customer_id'] !== $customerId) {
                    return ['ok' => false, 'message' => 'Substitution not found.'];
                }
                if ((string) $row['status'] !== 'pending_customer') {
                    return ['ok' => true];
                }
                if ($decision === 'reject') {
                    return self::handleRejection($pdo, $row, $customerId, $rejectResolution);
                }
                $pdo->prepare(
                    "UPDATE subscription_basket_substitutions SET status = 'approved', customer_decision_at = NOW() WHERE id = ?"
                )->execute([$substitutionId]);
                self::applyApprovedSubstitutionPublic($pdo, $row);
                SubscriptionAudit::log(
                    (int) $row['customer_subscription_id'],
                    null,
                    'customer',
                    $customerId,
                    'substitution_approved',
                    ['substitution_id' => $substitutionId]
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not save decision.'];
        }
    }

    /**
     * Admin proposes paid replacement (customer must approve + pay before basket changes).
     *
     * @return array{ok: bool, message?: string}
     */
    public static function adminPropose(
        int $substitutionId,
        int $proposedVariantId,
        float $proposedQty,
        float $proposedUnitPrice,
        ?string $note
    ): array {
        if (!AdminAuth::check()) {
            return ['ok' => false, 'message' => 'Unauthorized.'];
        }
        try {
            return Database::transaction(static function () use ($substitutionId, $proposedVariantId, $proposedQty, $proposedUnitPrice, $note): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT s.*, d.customer_subscription_id FROM subscription_basket_substitutions s
                     INNER JOIN subscription_delivery_days d ON d.id = s.subscription_delivery_day_id
                     WHERE s.id = ? FOR UPDATE'
                );
                $stmt->execute([$substitutionId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return ['ok' => false, 'message' => 'Not found.'];
                }
                $diff = round(($proposedUnitPrice * $proposedQty) - ((float) $row['original_unit_price_inr'] * (float) $row['original_qty']), 2);
                $pdo->prepare(
                    'UPDATE subscription_basket_substitutions SET proposed_variant_id = ?, proposed_qty = ?,
                     proposed_unit_price_inr = ?, price_difference_inr = ?, status = ?, admin_user_id = ?, admin_note = ?, updated_at = NOW()
                     WHERE id = ?'
                )->execute([
                    $proposedVariantId,
                    $proposedQty,
                    $proposedUnitPrice,
                    $diff,
                    'pending_customer',
                    AdminAuth::id(),
                    $note,
                    $substitutionId,
                ]);
                SubscriptionAudit::log(
                    (int) $row['customer_subscription_id'],
                    null,
                    'admin',
                    AdminAuth::id(),
                    'substitution_admin_proposed',
                    ['substitution_id' => $substitutionId, 'price_difference_inr' => $diff]
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save proposal.'];
        }
    }

    /** @param array<string, mixed> $subRow */
    public static function applyApprovedSubstitutionPublic(PDO $pdo, array $subRow): void
    {
        self::applyApprovedSubstitution($pdo, $subRow);
    }

    /** @param array<string, mixed> $row */
    private static function handleRejection(PDO $pdo, array $row, int $customerId, string $resolution): array
    {
        $resolution = strtolower(trim($resolution));
        if (!in_array($resolution, ['partial_delivery', 'cancel_day'], true)) {
            return ['ok' => false, 'message' => 'Choose partial delivery or cancel the day.'];
        }
        $substitutionId = (int) $row['id'];
        $dayId = (int) $row['day_id'];
        $pdo->prepare(
            "UPDATE subscription_basket_substitutions SET status = 'rejected', rejection_resolution = ?, customer_decision_at = NOW() WHERE id = ?"
        )->execute([$resolution, $substitutionId]);

        if ($resolution === 'cancel_day') {
            $credit = SubscriptionRefundService::applyDayCancellationCredit($dayId, $customerId, 'substitution_rejected_cancel_day');
            if (!$credit['ok']) {
                throw new RuntimeException($credit['message'] ?? 'Cancellation failed.');
            }

            return ['ok' => true];
        }

        $subRow = CustomerSubscriptionRepository::findById((int) $row['customer_subscription_id']);
        $snapshot = $subRow !== null ? CustomerSubscriptionRepository::decodeSnapshot($subRow) : [];
        $policy = is_array($snapshot['refund_policy_json'] ?? null) ? $snapshot['refund_policy_json'] : [];
        if (empty($policy['allow_partial_on_substitution_reject'])) {
            return ['ok' => false, 'message' => 'Partial delivery after rejection is not enabled on this plan.'];
        }
        $basket = SubscriptionBasketRepository::basketByDayId($dayId);
        if ($basket === null) {
            return ['ok' => false, 'message' => 'Basket missing.'];
        }
        $basketId = (int) $basket['id'];
        $origVid = (int) $row['original_variant_id'];
        $one = $pdo->prepare(
            'SELECT id FROM subscription_delivery_basket_items WHERE basket_id = ? AND variant_id = ? ORDER BY id LIMIT 1'
        );
        $one->execute([$basketId, $origVid]);
        $lineId = (int) ($one->fetchColumn() ?: 0);
        if ($lineId > 0) {
            $pdo->prepare('DELETE FROM subscription_delivery_basket_items WHERE id = ?')->execute([$lineId]);
        }
        $remaining = $pdo->prepare('SELECT COUNT(*) FROM subscription_delivery_basket_items WHERE basket_id = ?');
        $remaining->execute([$basketId]);
        if ((int) $remaining->fetchColumn() === 0) {
            return ['ok' => false, 'message' => 'Cannot deliver an empty basket. Cancel the day instead.'];
        }
        $sum = $pdo->prepare('SELECT COALESCE(SUM(line_total_inr), 0) FROM subscription_delivery_basket_items WHERE basket_id = ?');
        $sum->execute([$basketId]);
        $total = (float) $sum->fetchColumn();
        $pdo->prepare('UPDATE subscription_delivery_baskets SET total_inr = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$total, $basketId]);
        $pdo->prepare(
            "UPDATE subscription_delivery_days SET fulfilment_status = 'none', updated_at = NOW() WHERE id = ?"
        )->execute([$dayId]);
        $pdo->prepare(
            "UPDATE subscription_fulfilment_events SET status = 'failed', error_message = 'Partial after substitution reject', updated_at = NOW()
             WHERE subscription_delivery_day_id = ? AND status <> 'completed'"
        )->execute([$dayId]);
        SubscriptionAudit::log(
            (int) $row['customer_subscription_id'],
            null,
            'customer',
            $customerId,
            'substitution_rejected_partial',
            ['substitution_id' => $substitutionId]
        );

        return ['ok' => true];
    }

    public static function dayHasBlockingSubstitutions(int $dayId, PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM subscription_basket_substitutions
             WHERE subscription_delivery_day_id = ? AND status = 'pending_customer'"
        );
        $stmt->execute([$dayId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $subRow substitution row */
    private static function applyApprovedSubstitution(PDO $pdo, array $subRow): void
    {
        $dayId = (int) ($subRow['day_id'] ?? $subRow['subscription_delivery_day_id'] ?? 0);
        $basket = SubscriptionBasketRepository::basketByDayId($dayId);
        if ($basket === null) {
            return;
        }
        $basketId = (int) $basket['id'];
        $origVid = (int) $subRow['original_variant_id'];
        $pdo->prepare(
            'DELETE FROM subscription_delivery_basket_items WHERE basket_id = ? AND variant_id = ?'
        )->execute([$basketId, $origVid]);
        $propVid = (int) $subRow['proposed_variant_id'];
        $vstmt = $pdo->prepare(
            'SELECT v.id, v.name AS variant_name, v.price, v.sale_price, p.name AS product_name
             FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.id = ? LIMIT 1'
        );
        $vstmt->execute([$propVid]);
        $v = $vstmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            return;
        }
        $unit = (float) ($subRow['proposed_unit_price_inr'] ?? 0);
        $qty = (float) $subRow['proposed_qty'];
        $lineTotal = round($unit * $qty, 2);
        $pdo->prepare(
            'INSERT INTO subscription_delivery_basket_items
             (basket_id, variant_id, product_name, variant_name, unit_label, qty, unit_price_inr, line_total_inr, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 99)'
        )->execute([
            $basketId,
            $propVid,
            (string) $v['product_name'],
            (string) $v['variant_name'],
            'unit',
            $qty,
            $unit,
            $lineTotal,
        ]);
        $sum = $pdo->prepare('SELECT COALESCE(SUM(line_total_inr), 0) FROM subscription_delivery_basket_items WHERE basket_id = ?');
        $sum->execute([$basketId]);
        $total = (float) $sum->fetchColumn();
        $pdo->prepare('UPDATE subscription_delivery_baskets SET total_inr = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$total, $basketId]);
        $pdo->prepare(
            "UPDATE subscription_delivery_days SET fulfilment_status = 'none', updated_at = NOW() WHERE id = ?"
        )->execute([$dayId]);
    }

    /** @return array<string, mixed>|null */
    private static function findAlternative(PDO $pdo, int $originalVariantId, int $needQty): ?array
    {
        $orig = $pdo->prepare('SELECT product_id, price, sale_price FROM product_variants WHERE id = ? LIMIT 1');
        $orig->execute([$originalVariantId]);
        $o = $orig->fetch(PDO::FETCH_ASSOC);
        if (!$o) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT v.id, v.name, v.price, v.sale_price, v.stock_qty
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.is_active = 1 AND p.status = \'published\' AND v.id <> ?
             AND (v.product_id = ? OR v.stock_qty >= ?)
             ORDER BY (v.product_id = ?) DESC, v.stock_qty DESC
             LIMIT 1'
        );
        $stmt->execute([$originalVariantId, (int) $o['product_id'], $needQty, (int) $o['product_id']]);
        $alt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$alt || (int) $alt['stock_qty'] < $needQty) {
            return null;
        }
        $unitOrig = (float) ($o['sale_price'] ?? 0) > 0 ? (float) $o['sale_price'] : (float) $o['price'];
        $unitProp = (float) ($alt['sale_price'] ?? 0) > 0 ? (float) $alt['sale_price'] : (float) $alt['price'];
        $diff = round(($unitProp - $unitOrig) * $needQty, 2);

        return [
            'variant_id' => (int) $alt['id'],
            'variant_name' => (string) $alt['name'],
            'qty' => $needQty,
            'unit_price_inr' => $unitProp,
            'price_difference_inr' => $diff,
        ];
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $alt */
    private static function insertProposal(PDO $pdo, int $dayId, array $item, array $alt): void
    {
        $unitOrig = (float) ($item['unit_price_inr'] ?? 0);
        $qtyOrig = (float) ($item['qty'] ?? 0);
        $pdo->prepare(
            'INSERT INTO subscription_basket_substitutions
             (subscription_delivery_day_id, original_variant_id, original_qty, original_unit_price_inr,
              proposed_variant_id, proposed_qty, proposed_unit_price_inr, price_difference_inr, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $dayId,
            (int) $item['variant_id'],
            $qtyOrig,
            $unitOrig,
            (int) $alt['variant_id'],
            (float) $alt['qty'],
            (float) $alt['unit_price_inr'],
            (float) $alt['price_difference_inr'],
            'pending_customer',
        ]);
    }

    private static function heldOnDay(PDO $pdo, int $dayId, int $variantId): int
    {
        $stmt = $pdo->prepare(
            "SELECT qty FROM subscription_stock_reservations
             WHERE subscription_delivery_day_id = ? AND variant_id = ? AND status = 'held' LIMIT 1"
        );
        $stmt->execute([$dayId, $variantId]);
        $v = $stmt->fetchColumn();

        return $v === false ? 0 : (int) round((float) $v);
    }
}
