<?php

declare(strict_types=1);

final class SubscriptionPlanRepository
{
    /** @return list<array<string, mixed>> */
    public static function allForAdmin(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();

        return Database::pdo()->query(
            'SELECT * FROM subscription_plans ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function activeForStorefront(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT id, name, description, calendar_duration_days, delivery_count, frequency_type,
                    price, compare_at_price, basket_rules_json, sort_order
             FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order, id'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM subscription_plans WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findByTemplateKey(string $key): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM subscription_plans WHERE template_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors?: list<string>}
     */
    public static function save(?int $id, array $data): array
    {
        $normalized = SubscriptionPlanValidator::normalizeInput($data);
        $valid = SubscriptionPlanValidator::validate($normalized);
        if (!$valid['ok']) {
            return ['ok' => false, 'errors' => $valid['errors'] ?? ['Invalid plan.']];
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $fields = [
            $normalized['name'],
            $normalized['description'],
            $normalized['internal_notes'],
            $normalized['calendar_duration_days'],
            $normalized['delivery_count'],
            $normalized['duration_model'],
            $normalized['frequency_type'],
            json_encode($normalized['frequency_config'], JSON_UNESCAPED_UNICODE),
            json_encode($normalized['basket_rules_json'], JSON_UNESCAPED_UNICODE),
            $normalized['price'],
            $normalized['compare_at_price'],
            json_encode($normalized['payment_rules_json'], JSON_UNESCAPED_UNICODE),
            json_encode($normalized['skip_pause_policy_json'], JSON_UNESCAPED_UNICODE),
            json_encode($normalized['slot_policy_json'], JSON_UNESCAPED_UNICODE),
            $normalized['selection_cutoff_hours'],
            $normalized['is_active'],
            $normalized['sort_order'],
        ];

        if ($id) {
            $fields[] = $id;
            $pdo->prepare(
                'UPDATE subscription_plans SET name = ?, description = ?, internal_notes = ?,
                 calendar_duration_days = ?, delivery_count = ?, duration_model = ?, frequency_type = ?,
                 frequency_config = ?, basket_rules_json = ?, price = ?, compare_at_price = ?,
                 payment_rules_json = ?, skip_pause_policy_json = ?, slot_policy_json = ?,
                 selection_cutoff_hours = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ?'
            )->execute($fields);
            self::audit($id, 'update', null);
            $planId = $id;
        } else {
            $pdo->prepare(
                'INSERT INTO subscription_plans (name, description, internal_notes, calendar_duration_days,
                 delivery_count, duration_model, frequency_type, frequency_config, basket_rules_json, price,
                 compare_at_price, payment_rules_json, skip_pause_policy_json, slot_policy_json,
                 selection_cutoff_hours, is_active, is_default_template, template_key, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?)'
            )->execute($fields);
            $planId = (int) $pdo->lastInsertId();
            self::audit($planId, 'create', null);
        }

        return ['ok' => true, 'id' => $planId];
    }

    public static function clonePlan(int $sourceId): ?int
    {
        $src = self::find($sourceId);
        if (!$src) {
            return null;
        }
        unset($src['id'], $src['created_at'], $src['updated_at'], $src['is_default_template'], $src['template_key']);
        $src['name'] = ($src['name'] ?? 'Plan') . ' (copy)';
        $src['is_active'] = 0;
        $result = self::save(null, $src);

        return $result['ok'] ? (int) ($result['id'] ?? 0) : null;
    }

    private static function audit(int $planId, string $action, ?string $note): void
    {
        $adminId = AdminAuth::check() ? AdminAuth::id() : null;
        Database::pdo()->prepare(
            'INSERT INTO subscription_plan_audit (subscription_plan_id, admin_user_id, action, note) VALUES (?, ?, ?, ?)'
        )->execute([$planId, $adminId, $action, $note]);
    }

    /** @return array<string, mixed> */
    public static function toSnapshotArray(array $planRow, float $pricePaid): array
    {
        return [
            'plan_id' => (int) $planRow['id'],
            'name' => (string) $planRow['name'],
            'description' => (string) ($planRow['description'] ?? ''),
            'calendar_duration_days' => (int) $planRow['calendar_duration_days'],
            'delivery_count' => (int) $planRow['delivery_count'],
            'duration_model' => (string) $planRow['duration_model'],
            'frequency_type' => (string) $planRow['frequency_type'],
            'frequency_config' => json_decode((string) ($planRow['frequency_config'] ?? '{}'), true) ?: [],
            'basket_rules_json' => json_decode((string) ($planRow['basket_rules_json'] ?? '{}'), true) ?: [],
            'skip_pause_policy_json' => json_decode((string) ($planRow['skip_pause_policy_json'] ?? '{}'), true) ?: [],
            'slot_policy_json' => json_decode((string) ($planRow['slot_policy_json'] ?? '{}'), true) ?: [],
            'selection_cutoff_hours' => (int) ($planRow['selection_cutoff_hours'] ?? 18),
            'price_paid' => round($pricePaid, 2),
            'snapshotted_at' => (new DateTimeImmutable('now', new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('c'),
        ];
    }
}

final class SubscriptionPlanValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeInput(array $input): array
    {
        $freq = (string) ($input['frequency_type'] ?? 'daily');
        $weekdays = [];
        if (isset($input['weekdays']) && is_array($input['weekdays'])) {
            $weekdays = array_values(array_map('intval', $input['weekdays']));
        }
        $interval = max(1, (int) ($input['interval_days'] ?? 1));
        $freqConfig = ['interval_days' => $interval];
        if ($freq === 'weekdays') {
            $freqConfig = ['weekdays' => $weekdays !== [] ? $weekdays : [1, 2, 3, 4, 5, 6]];
        } elseif ($freq === 'custom_interval') {
            $freqConfig = ['interval_days' => $interval];
        } elseif ($freq === 'alternate') {
            $freqConfig = ['interval_days' => 2];
        }

        $unused = (string) ($input['unused_allowance_policy'] ?? 'expire');
        if (!in_array($unused, ['expire', 'carry_forward'], true)) {
            $unused = 'expire';
        }

        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'internal_notes' => trim((string) ($input['internal_notes'] ?? '')),
            'calendar_duration_days' => max(1, (int) ($input['calendar_duration_days'] ?? 7)),
            'delivery_count' => max(1, (int) ($input['delivery_count'] ?? 7)),
            'duration_model' => (string) ($input['duration_model'] ?? 'hybrid'),
            'frequency_type' => $freq,
            'frequency_config' => $freqConfig,
            'basket_rules_json' => [
                'daily_allowance_inr' => max(0, (float) ($input['daily_allowance_inr'] ?? 499)),
                'max_line_items' => max(1, (int) ($input['max_line_items'] ?? 10)),
                'unused_allowance_policy' => $unused,
                'over_allowance_requires_approval' => !empty($input['over_allowance_requires_approval']),
                'never_silent_charge' => true,
            ],
            'price' => max(0, (float) ($input['price'] ?? 0)),
            'compare_at_price' => isset($input['compare_at_price']) && $input['compare_at_price'] !== ''
                ? (float) $input['compare_at_price'] : null,
            'payment_rules_json' => ['prepaid_percent' => 100],
            'skip_pause_policy_json' => [
                'max_skips' => max(0, (int) ($input['max_skips'] ?? 2)),
                'skip_cutoff_hours' => max(1, (int) ($input['skip_cutoff_hours'] ?? 18)),
                'pause_allowed' => !empty($input['pause_allowed']) ? 1 : 0,
                'carry_forward_skips' => !empty($input['carry_forward_skips']) ? 1 : 0,
                'preserve_remaining_on_pause' => !empty($input['preserve_remaining_on_pause']) ? 1 : 0,
            ],
            'slot_policy_json' => ['use_delivery_slots' => !empty($input['use_delivery_slots']) ? 1 : 0],
            'selection_cutoff_hours' => max(1, (int) ($input['selection_cutoff_hours'] ?? 18)),
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{ok: bool, errors?: list<string>, preview_dates?: list<string>}
     */
    public static function validate(array $plan): array
    {
        $errors = [];
        if (($plan['name'] ?? '') === '') {
            $errors[] = 'Plan name is required.';
        }
        if ((float) ($plan['price'] ?? 0) < 0) {
            $errors[] = 'Price cannot be negative.';
        }
        $schedule = SubscriptionScheduleGenerator::validatePlanSchedule($plan);
        if (!$schedule['ok']) {
            $errors = array_merge($errors, $schedule['errors'] ?? []);
        }

        $basket = $plan['basket_rules_json'] ?? [];
        if (empty($basket['never_silent_charge'])) {
            $errors[] = 'Basket rules must enforce never_silent_charge.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $preview = SubscriptionScheduleGenerator::generateDeliveryDates(
            $plan,
            (new DateTimeImmutable('now', new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->modify('+1 day')->format('Y-m-d')
        );

        return [
            'ok' => true,
            'preview_dates' => $preview['dates'] ?? [],
        ];
    }
}
