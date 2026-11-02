<?php

declare(strict_types=1);

final class SubscriptionMenuRepository
{
    /** @return list<array<string, mixed>> */
    public static function allForAdmin(): array
    {
        Install::ensureConnection();

        return Database::pdo()->query(
            'SELECT * FROM subscription_menu_templates ORDER BY planner_type, template_date, weekday, day_index, id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM subscription_menu_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function itemsForTemplate(int $templateId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT i.*, v.name AS variant_name, v.stock_qty, v.is_active AS variant_active,
                    p.name AS product_name, p.status AS product_status
             FROM subscription_menu_template_items i
             INNER JOIN product_variants v ON v.id = i.variant_id
             INNER JOIN products p ON p.id = v.product_id
             WHERE i.menu_template_id = ?
             ORDER BY i.sort_order, i.id'
        );
        $stmt->execute([$templateId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $items
     * @return array{ok: bool, id?: int, errors?: list<string>}
     */
    public static function save(?int $id, array $input, array $items): array
    {
        $normalized = self::normalizeTemplateInput($input);
        $errors = self::validateTemplate($normalized);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        Install::ensureConnection();
        $pdo = Database::pdo();

        if ($id === null) {
            $pdo->prepare(
                'INSERT INTO subscription_menu_templates (name, planner_type, template_date, weekday, day_index, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $normalized['name'],
                $normalized['planner_type'],
                $normalized['template_date'],
                $normalized['weekday'],
                $normalized['day_index'],
                $normalized['notes'],
                $normalized['is_active'],
            ]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE subscription_menu_templates SET name = ?, planner_type = ?, template_date = ?, weekday = ?,
                 day_index = ?, notes = ?, is_active = ?, updated_at = NOW() WHERE id = ?'
            )->execute([
                $normalized['name'],
                $normalized['planner_type'],
                $normalized['template_date'],
                $normalized['weekday'],
                $normalized['day_index'],
                $normalized['notes'],
                $normalized['is_active'],
                $id,
            ]);
            $pdo->prepare('DELETE FROM subscription_menu_template_items WHERE menu_template_id = ?')->execute([$id]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO subscription_menu_template_items
             (menu_template_id, variant_id, default_qty, unit_label, unit_price_inr, include_in_rotation, max_qty, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $sort = 0;
        foreach ($items as $row) {
            $variantId = (int) ($row['variant_id'] ?? 0);
            if ($variantId <= 0) {
                continue;
            }
            $price = round((float) ($row['unit_price_inr'] ?? 0), 2);
            if ($price < 0) {
                return ['ok' => false, 'errors' => ['Item prices cannot be negative.']];
            }
            $qty = (float) ($row['default_qty'] ?? 1);
            if ($qty <= 0) {
                return ['ok' => false, 'errors' => ['Item quantities must be positive.']];
            }
            $ins->execute([
                $id,
                $variantId,
                $qty,
                trim((string) ($row['unit_label'] ?? 'kg')),
                $price,
                !empty($row['include_in_rotation']) ? 1 : 0,
                isset($row['max_qty']) && $row['max_qty'] !== '' ? (float) $row['max_qty'] : null,
                $sort++,
            ]);
        }

        AuditLog::write('subscription.menu_template_saved', 'menu_template', (string) $id, [
            'planner_type' => $normalized['planner_type'],
        ]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Resolve menu lines for a delivery day (date-specific > weekly > day index).
     *
     * @return list<array<string, mixed>>
     */
    public static function resolveMenuLines(string $deliveryDateYmd, int $dayIndex): array
    {
        Install::ensureConnection();
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $deliveryDateYmd, $tz);
        $weekday = $d ? (int) $d->format('N') : 0;

        $template = self::findTemplate('date', $deliveryDateYmd, null, null);
        if ($template === null && $weekday > 0) {
            $template = self::findTemplate('weekly', null, $weekday, null);
        }
        if ($template === null && $dayIndex > 0) {
            $template = self::findTemplate('day_index', null, null, $dayIndex);
        }
        if ($template === null || empty($template['is_active'])) {
            return [];
        }

        return self::itemsForTemplate((int) $template['id']);
    }

    /** @return array<string, mixed>|null */
    private static function findTemplate(
        string $type,
        ?string $templateDate,
        ?int $weekday,
        ?int $dayIndex
    ): ?array {
        $pdo = Database::pdo();
        if ($type === 'date') {
            $stmt = $pdo->prepare(
                'SELECT * FROM subscription_menu_templates WHERE planner_type = ? AND template_date = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$type, $templateDate]);
        } elseif ($type === 'weekly') {
            $stmt = $pdo->prepare(
                'SELECT * FROM subscription_menu_templates WHERE planner_type = ? AND weekday = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$type, $weekday]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM subscription_menu_templates WHERE planner_type = ? AND day_index = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$type, $dayIndex]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed> */
    private static function normalizeTemplateInput(array $input): array
    {
        $type = (string) ($input['planner_type'] ?? 'weekly');
        if (!in_array($type, ['date', 'weekly', 'day_index'], true)) {
            $type = 'weekly';
        }

        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'planner_type' => $type,
            'template_date' => ($input['template_date'] ?? '') !== '' ? (string) $input['template_date'] : null,
            'weekday' => isset($input['weekday']) && $input['weekday'] !== '' ? (int) $input['weekday'] : null,
            'day_index' => isset($input['day_index']) && $input['day_index'] !== '' ? (int) $input['day_index'] : null,
            'notes' => trim((string) ($input['notes'] ?? '')),
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        ];
    }

    /** @return list<string> */
    private static function validateTemplate(array $t): array
    {
        $errors = [];
        if ($t['name'] === '') {
            $errors[] = 'Template name is required.';
        }
        if ($t['planner_type'] === 'date') {
            if ($t['template_date'] === null || !self::isValidYmd($t['template_date'])) {
                $errors[] = 'A valid calendar date is required for date-wise templates.';
            }
        } elseif ($t['planner_type'] === 'weekly') {
            $w = (int) ($t['weekday'] ?? 0);
            if ($w < 1 || $w > 7) {
                $errors[] = 'Weekday must be 1 (Mon) through 7 (Sun).';
            }
        } elseif ($t['planner_type'] === 'day_index') {
            if ((int) ($t['day_index'] ?? 0) < 1) {
                $errors[] = 'Delivery day index must be at least 1.';
            }
        }

        return $errors;
    }

    private static function isValidYmd(string $ymd): bool
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);

        return $d !== false && $d->format('Y-m-d') === $ymd;
    }
}

final class SubscriptionPreferenceRepository
{
    /** @return list<int> variant IDs to avoid */
    public static function avoidedVariantIds(int $customerId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT variant_id FROM subscription_customer_preferences WHERE customer_id = ? AND preference = ?'
        );
        $stmt->execute([$customerId, 'avoid']);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids[] = (int) $r['variant_id'];
        }

        return $ids;
    }
}
