<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

/**
 * Subscription Menu Planner: Plan -> Day -> Vegetables.
 *
 * A "menu plan" is a header (subscription_menu_plans) that owns one subscription_menu_templates row per
 * delivery day (planner_type = day_index, menu_plan_id = plan). Day items live in the existing
 * subscription_menu_template_items table. Customer baskets are snapshot tables, so changing a menu never alters
 * a basket that was already generated.
 */
final class SubscriptionMenuPlanService
{
    public const MAX_DAYS = 365;
    public const MAX_LINES_PER_DAY = 50;
    /** A day is "Ready" with at least this many available vegetables. */
    public const READY_MIN_ITEMS = 3;

    public const REPEAT_NONE = 'none';
    public const REPEAT_EVERY_N = 'every_n';
    public const REPEAT_WEEKLY = 'weekly';

    /**
     * Menu template for (subscription plan, delivery day) from the active menu plan, only for subscriptions
     * created at/after the menu plan was first activated.
     *
     * @return array<string, mixed>|null
     */
    public static function findPlanTemplate(int $subscriptionPlanId, int $dayIndex, ?string $subscriptionCreatedAt): ?array
    {
        Install::ensureConnection();
        $sql = 'SELECT t.* FROM subscription_menu_templates t
                INNER JOIN subscription_menu_plans p ON p.id = t.menu_plan_id
                WHERE p.subscription_plan_id = ? AND p.is_active = 1
                  AND t.planner_type = \'day_index\' AND t.day_index = ? AND t.is_active = 1';
        $params = [$subscriptionPlanId, $dayIndex];
        if ($subscriptionCreatedAt !== null && $subscriptionCreatedAt !== '') {
            $sql .= ' AND (p.activated_at IS NULL OR p.activated_at <= ?)';
            $params[] = $subscriptionCreatedAt;
        }
        $sql .= ' ORDER BY p.activated_at DESC, p.id DESC LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** Day status from its (already loaded) lines. Mirrors assets/js/menu-planner-ops.js. */
    public static function dayStatus(array $items): string
    {
        $n = count($items);
        if ($n === 0) {
            return 'not_configured';
        }
        $available = 0;
        foreach ($items as $it) {
            if (!isset($it['available']) || $it['available']) {
                $available++;
            }
        }
        if ($n >= self::READY_MIN_ITEMS && $available === $n) {
            return 'ready';
        }

        return 'partial';
    }

    /** Effective selling price of a variant row (sale price when lower than regular). */
    public static function currentPrice(array $variantRow): float
    {
        $p = catalogue_variant_prices($variantRow);

        return round((float) $p['display'], 2);
    }

    /** Unit label stored on a menu line: the variant's pack label (e.g. "500 g"), so quantity = number of packs. */
    public static function unitLabelForVariant(array $variantRow): string
    {
        $label = trim((string) ($variantRow['name'] ?? ''));
        if ($label === '') {
            $label = 'unit';
        }

        return function_exists('mb_substr') ? mb_substr($label, 0, 32) : substr($label, 0, 32);
    }

    /**
     * Search variants by product name, SKU or variant name.
     *
     * @return list<array<string, mixed>>
     */
    public static function searchVariants(string $q, int $limit = 20): array
    {
        Install::ensureConnection();
        $limit = max(1, min(50, $limit));
        $q = trim($q);
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where = '(p.name LIKE ? OR v.sku LIKE ? OR v.name LIKE ?)';
            $params = [$like, $like, $like];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT v.id, v.name AS variant_name, v.sku, v.price, v.sale_price, v.stock_qty, v.is_active,
                    p.id AS product_id, p.name AS product_name, p.status AS product_status,
                    (SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image_path
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE ' . $where . '
             ORDER BY (v.is_active = 1 AND p.status = \'published\') DESC, p.name, v.sort_order, v.name
             LIMIT ' . $limit
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = self::decorateVariant($r);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function decorateVariant(array $r): array
    {
        $available = !empty($r['is_active']) && (string) ($r['product_status'] ?? '') === 'published';
        $stock = (int) ($r['stock_qty'] ?? 0);
        $image = trim((string) ($r['image_path'] ?? ''));

        return [
            'variant_id' => (int) $r['id'],
            'product_name' => (string) ($r['product_name'] ?? ''),
            'variant_name' => (string) ($r['variant_name'] ?? $r['name'] ?? ''),
            'sku' => (string) ($r['sku'] ?? ''),
            'stock_qty' => $stock,
            'price' => self::currentPrice($r),
            'unit_label' => self::unitLabelForVariant(['name' => $r['variant_name'] ?? $r['name'] ?? '']),
            'image_url' => $image !== '' ? url_base() . '/' . ltrim($image, '/') : '',
            'available' => $available,
            'in_stock' => $stock > 0,
            'unavailable_reason' => !$available
                ? (empty($r['is_active']) ? 'Variant is inactive' : 'Product is not published')
                : '',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listPlans(): array
    {
        Install::ensureConnection();
        $rows = Database::pdo()->query(
            'SELECT mp.*, sp.name AS subscription_plan_name,
                    (SELECT COUNT(*) FROM subscription_menu_templates t
                     WHERE t.menu_plan_id = mp.id AND t.is_active = 1 AND t.day_index <= mp.duration_days
                       AND EXISTS (SELECT 1 FROM subscription_menu_template_items i WHERE i.menu_template_id = t.id)) AS configured_days,
                    (SELECT COUNT(*) FROM subscription_menu_template_items i
                     INNER JOIN subscription_menu_templates t ON t.id = i.menu_template_id
                     WHERE t.menu_plan_id = mp.id AND t.is_active = 1 AND t.day_index <= mp.duration_days) AS total_entries
             FROM subscription_menu_plans mp
             LEFT JOIN subscription_plans sp ON sp.id = mp.subscription_plan_id
             ORDER BY mp.is_active DESC, mp.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT mp.*, sp.name AS subscription_plan_name FROM subscription_menu_plans mp
             LEFT JOIN subscription_plans sp ON sp.id = mp.subscription_plan_id WHERE mp.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Full plan with per-day lines (live variant info), shaped like the wizard/save payload.
     *
     * @return array<string, mixed>|null
     */
    public static function load(int $id): ?array
    {
        $plan = self::find($id);
        if ($plan === null) {
            return null;
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.id, t.day_index, t.is_customized FROM subscription_menu_templates t
             WHERE t.menu_plan_id = ? AND t.is_active = 1 AND t.day_index <= ? ORDER BY t.day_index'
        );
        $stmt->execute([$id, (int) $plan['duration_days']]);
        $days = [];
        $configured = 0;
        $entries = 0;
        for ($d = 1; $d <= (int) $plan['duration_days']; $d++) {
            $days[$d] = ['customized' => false, 'items' => []];
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
            $d = (int) $t['day_index'];
            $items = [];
            foreach (SubscriptionMenuRepository::itemsForTemplate((int) $t['id']) as $it) {
                $items[] = self::lineFromRow($it);
            }
            $days[$d] = ['customized' => !empty($t['is_customized']), 'items' => $items];
            if ($items !== []) {
                $configured++;
                $entries += count($items);
            }
        }
        $plan['days'] = $days;
        $plan['configured_days'] = $configured;
        $plan['total_entries'] = $entries;

        return $plan;
    }

    /** @return array<string, mixed> */
    private static function lineFromRow(array $it): array
    {
        $available = !empty($it['variant_active']) && (string) ($it['product_status'] ?? '') === 'published';
        $image = '';
        $current = null;
        try {
            $ps = Database::pdo()->prepare('SELECT price, sale_price FROM product_variants WHERE id = ? LIMIT 1');
            $ps->execute([(int) $it['variant_id']]);
            $pv = $ps->fetch(PDO::FETCH_ASSOC);
            $current = $pv ? self::currentPrice($pv) : null;
            $s = Database::pdo()->prepare(
                'SELECT pi.file_path FROM product_images pi
                 INNER JOIN product_variants v ON v.product_id = pi.product_id
                 WHERE v.id = ? ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1'
            );
            $s->execute([(int) $it['variant_id']]);
            $image = (string) ($s->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $image = '';
        }

        return [
            'variant_id' => (int) $it['variant_id'],
            'product_name' => (string) ($it['product_name'] ?? ''),
            'variant_name' => (string) ($it['variant_name'] ?? ''),
            'unit_label' => (string) ($it['unit_label'] ?? ''),
            'qty' => (float) $it['default_qty'],
            'max_qty' => $it['max_qty'] !== null ? (float) $it['max_qty'] : null,
            'price' => (float) $it['unit_price_inr'],
            'current_price' => $current,
            'stock_qty' => (int) ($it['stock_qty'] ?? 0),
            'available' => $available,
            'in_stock' => (int) ($it['stock_qty'] ?? 0) > 0,
            'image_url' => $image !== '' ? url_base() . '/' . ltrim($image, '/') : '',
        ];
    }

    /**
     * Validate a wizard payload without touching the database for writes.
     *
     * @param array<string, mixed> $payload
     * @param int|null $planId existing plan id (for edit)
     * @return array{errors: list<string>, data: array<string, mixed>, incomplete_days: list<int>}
     */
    public static function validatePayload(array $payload, ?int $planId = null): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $errors = [];

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Plan name is required.';
        } elseif (strlen($name) > 191) {
            $errors[] = 'Plan name must be 191 characters or fewer.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM subscription_menu_plans WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1');
            $stmt->execute([$name, (int) $planId]);
            if ($stmt->fetchColumn()) {
                $errors[] = 'A menu plan named "' . $name . '" already exists. Choose a different name.';
            }
        }

        $rawDuration = $payload['duration_days'] ?? null;
        $duration = 0;
        if (!is_scalar($rawDuration) || !preg_match('/^\d+$/', trim((string) $rawDuration))) {
            $errors[] = 'Duration must be a whole number of days between 1 and ' . self::MAX_DAYS . '.';
        } else {
            $duration = (int) $rawDuration;
            if ($duration < 1 || $duration > self::MAX_DAYS) {
                $errors[] = 'Duration must be between 1 and ' . self::MAX_DAYS . ' days.';
                $duration = 0;
            }
        }

        $repeatMode = (string) ($payload['repeat_mode'] ?? self::REPEAT_NONE);
        if (!in_array($repeatMode, [self::REPEAT_NONE, self::REPEAT_EVERY_N, self::REPEAT_WEEKLY], true)) {
            $errors[] = 'Unknown repeat option.';
            $repeatMode = self::REPEAT_NONE;
        }
        $repeatEvery = null;
        if ($repeatMode === self::REPEAT_EVERY_N) {
            $re = $payload['repeat_every'] ?? null;
            if (!is_scalar($re) || !preg_match('/^\d+$/', trim((string) $re))
                || (int) $re < 1 || ($duration > 0 && (int) $re >= $duration)) {
                $errors[] = 'Repeat interval must be a whole number from 1 to ' . max(1, $duration - 1) . '.';
            } else {
                $repeatEvery = (int) $re;
            }
        } elseif ($repeatMode === self::REPEAT_WEEKLY) {
            $repeatEvery = 7;
        }

        $subPlanId = null;
        $rawSub = $payload['subscription_plan_id'] ?? null;
        if ($rawSub !== null && $rawSub !== '' && (int) $rawSub !== 0) {
            $subPlanId = (int) $rawSub;
            $stmt = $pdo->prepare('SELECT id FROM subscription_plans WHERE id = ? LIMIT 1');
            $stmt->execute([$subPlanId]);
            if (!$stmt->fetchColumn()) {
                $errors[] = 'The selected subscription plan does not exist.';
                $subPlanId = null;
            }
        }

        $isActive = !empty($payload['is_active']);
        if ($isActive && $subPlanId === null) {
            $errors[] = 'Choose the subscription plan this menu is for before making it live.';
        }

        // Days
        $existingByDay = [];
        if ($planId !== null) {
            $stmt = $pdo->prepare(
                'SELECT t.day_index, i.variant_id FROM subscription_menu_template_items i
                 INNER JOIN subscription_menu_templates t ON t.id = i.menu_template_id WHERE t.menu_plan_id = ?'
            );
            $stmt->execute([$planId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $existingByDay[(int) $r['day_index']][(int) $r['variant_id']] = true;
            }
        }

        $daysIn = $payload['days'] ?? [];
        if (!is_array($daysIn)) {
            $daysIn = [];
            $errors[] = 'Menu data is malformed.';
        }
        $clean = [];
        $incomplete = [];
        $variantCache = [];
        $vstmt = $pdo->prepare(
            'SELECT v.id, v.name, v.price, v.sale_price, v.is_active, p.status AS product_status, p.name AS product_name
             FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.id = ? LIMIT 1'
        );
        if ($duration > 0) {
            foreach (array_keys($daysIn) as $k) {
                if (!is_int($k) && !(is_string($k) && ctype_digit($k))) {
                    $errors[] = 'Invalid day key.';
                    continue;
                }
                if ((int) $k < 1 || (int) $k > $duration) {
                    $errors[] = 'Day ' . (int) $k . ' is outside the plan duration of ' . $duration . ' days.';
                }
            }
            for ($d = 1; $d <= $duration; $d++) {
                $dayIn = $daysIn[$d] ?? $daysIn[(string) $d] ?? ['items' => []];
                $itemsIn = is_array($dayIn) ? ($dayIn['items'] ?? []) : [];
                if (!is_array($itemsIn)) {
                    $itemsIn = [];
                }
                if (count($itemsIn) > self::MAX_LINES_PER_DAY) {
                    $errors[] = 'Day ' . $d . ': at most ' . self::MAX_LINES_PER_DAY . ' vegetables per day.';
                    continue;
                }
                $seen = [];
                $lines = [];
                foreach (array_values($itemsIn) as $idx => $it) {
                    if (!is_array($it)) {
                        $errors[] = 'Day ' . $d . ': malformed line.';
                        continue;
                    }
                    $vid = (int) ($it['variant_id'] ?? 0);
                    if ($vid <= 0) {
                        $errors[] = 'Day ' . $d . ': choose a vegetable for line ' . ($idx + 1) . '.';
                        continue;
                    }
                    if (isset($seen[$vid])) {
                        $errors[] = 'Day ' . $d . ': the same vegetable variant is added more than once.';
                        continue;
                    }
                    $seen[$vid] = true;
                    if (!array_key_exists($vid, $variantCache)) {
                        $vstmt->execute([$vid]);
                        $variantCache[$vid] = $vstmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                    $v = $variantCache[$vid];
                    if ($v === null) {
                        $errors[] = 'Day ' . $d . ': variant #' . $vid . ' does not exist.';
                        continue;
                    }
                    $label = $v['product_name'] . ' (' . $v['name'] . ')';
                    $available = !empty($v['is_active']) && (string) $v['product_status'] === 'published';
                    if (!$available && empty($existingByDay[$d][$vid])) {
                        $errors[] = 'Day ' . $d . ': ' . $label . ' is inactive or unavailable and cannot be newly added.';
                        continue;
                    }
                    $qtyRaw = $it['qty'] ?? null;
                    if (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0 || (float) $qtyRaw > 9999) {
                        $errors[] = 'Day ' . $d . ': ' . $label . ' quantity must be greater than 0.';
                        continue;
                    }
                    $qty = round((float) $qtyRaw, 3);
                    $max = null;
                    if (isset($it['max_qty']) && $it['max_qty'] !== '' && $it['max_qty'] !== null) {
                        if (!is_numeric($it['max_qty'])) {
                            $errors[] = 'Day ' . $d . ': ' . $label . ' max quantity must be a number.';
                            continue;
                        }
                        $max = round((float) $it['max_qty'], 3);
                        if ($max < $qty) {
                            $errors[] = 'Day ' . $d . ': ' . $label . ' max quantity cannot be less than the suggested quantity.';
                            continue;
                        }
                        if ($max > 9999) {
                            $errors[] = 'Day ' . $d . ': ' . $label . ' max quantity is too large.';
                            continue;
                        }
                    }
                    $lines[] = ['variant_id' => $vid, 'qty' => $qty, 'max_qty' => $max, 'variant' => $v];
                }
                if ($lines === []) {
                    $incomplete[] = $d;
                }
                $clean[$d] = [
                    'customized' => is_array($dayIn) && !empty($dayIn['customized']),
                    'items' => $lines,
                ];
            }
        }

        if ($isActive && $incomplete !== [] && empty($payload['allow_incomplete'])) {
            $errors[] = 'Cannot make the plan live: ' . count($incomplete) . ' day(s) have no vegetables ('
                . self::summarizeDays($incomplete) . '). Add vegetables or confirm saving an incomplete plan.';
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'incomplete_days' => $incomplete,
            'data' => [
                'name' => $name,
                'duration_days' => $duration,
                'repeat_mode' => $repeatMode,
                'repeat_every' => $repeatEvery,
                'subscription_plan_id' => $subPlanId,
                'is_active' => $isActive ? 1 : 0,
                'refresh_prices' => !empty($payload['refresh_prices']),
                'days' => $clean,
            ],
        ];
    }

    /** @param list<int> $days */
    public static function summarizeDays(array $days): string
    {
        $days = array_values($days);
        if (count($days) > 8) {
            return 'Day ' . implode(', ', array_slice($days, 0, 8)) . ' and ' . (count($days) - 8) . ' more';
        }

        return 'Day ' . implode(', ', $days);
    }

    /**
     * Create or update a menu plan atomically.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, id?: int, errors?: list<string>, summary?: array<string, mixed>}
     */
    public static function save(?int $planId, array $payload): array
    {
        Install::ensureConnection();
        if ($planId !== null && self::find($planId) === null) {
            return ['ok' => false, 'errors' => ['Menu plan not found.']];
        }
        $v = self::validatePayload($payload, $planId);
        if ($v['errors'] !== []) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        $d = $v['data'];

        try {
            $id = Database::transaction(static function () use ($planId, $d): int {
                $pdo = Database::pdo();
                $prev = null;
                if ($planId === null) {
                    $pdo->prepare(
                        'INSERT INTO subscription_menu_plans (name, subscription_plan_id, duration_days, repeat_mode, repeat_every, is_active, activated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ' . ($d['is_active'] ? 'NOW()' : 'NULL') . ')'
                    )->execute([
                        $d['name'], $d['subscription_plan_id'], $d['duration_days'], $d['repeat_mode'],
                        $d['repeat_every'], $d['is_active'],
                    ]);
                    $id = (int) $pdo->lastInsertId();
                } else {
                    $id = $planId;
                    $prev = $pdo->query('SELECT * FROM subscription_menu_plans WHERE id = ' . (int) $id . ' FOR UPDATE')
                        ->fetch(PDO::FETCH_ASSOC);
                    $pdo->prepare(
                        'UPDATE subscription_menu_plans SET name = ?, subscription_plan_id = ?, duration_days = ?, repeat_mode = ?,
                         repeat_every = ?, is_active = ?, activated_at = ' . ($d['is_active'] ? 'COALESCE(activated_at, NOW())' : 'activated_at') . '
                         WHERE id = ?'
                    )->execute([
                        $d['name'], $d['subscription_plan_id'], $d['duration_days'], $d['repeat_mode'],
                        $d['repeat_every'], $d['is_active'], $id,
                    ]);
                }
                if ($d['is_active'] && $d['subscription_plan_id'] !== null) {
                    // Only one live menu plan per subscription plan.
                    $pdo->prepare(
                        'UPDATE subscription_menu_plans SET is_active = 0 WHERE subscription_plan_id = ? AND id <> ? AND is_active = 1'
                    )->execute([$d['subscription_plan_id'], $id]);
                }

                $tplSel = $pdo->prepare('SELECT id FROM subscription_menu_templates WHERE menu_plan_id = ? AND day_index = ? LIMIT 1');
                $tplIns = $pdo->prepare(
                    'INSERT INTO subscription_menu_templates (name, planner_type, template_date, weekday, day_index, notes, is_active, menu_plan_id, is_customized)
                     VALUES (?, \'day_index\', NULL, NULL, ?, \'\', 1, ?, ?)'
                );
                $tplUpd = $pdo->prepare(
                    'UPDATE subscription_menu_templates SET name = ?, is_active = 1, is_customized = ?, updated_at = NOW() WHERE id = ?'
                );
                $oldItems = $pdo->prepare(
                    'SELECT variant_id, unit_price_inr, unit_label FROM subscription_menu_template_items WHERE menu_template_id = ?'
                );
                $delItems = $pdo->prepare('DELETE FROM subscription_menu_template_items WHERE menu_template_id = ?');
                $insItem = $pdo->prepare(
                    'INSERT INTO subscription_menu_template_items
                     (menu_template_id, variant_id, default_qty, unit_label, unit_price_inr, include_in_rotation, max_qty, sort_order)
                     VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
                );
                foreach ($d['days'] as $day => $dayData) {
                    $tplName = mb_substr($d['name'], 0, 160) . ' – Day ' . $day;
                    $tplSel->execute([$id, $day]);
                    $tid = (int) $tplSel->fetchColumn();
                    if ($tid === 0) {
                        $tplIns->execute([$tplName, $day, $id, $dayData['customized'] ? 1 : 0]);
                        $tid = (int) $pdo->lastInsertId();
                        $old = [];
                    } else {
                        $tplUpd->execute([$tplName, $dayData['customized'] ? 1 : 0, $tid]);
                        $oldItems->execute([$tid]);
                        $old = [];
                        foreach ($oldItems->fetchAll(PDO::FETCH_ASSOC) ?: [] as $o) {
                            $old[(int) $o['variant_id']] = $o;
                        }
                        $delItems->execute([$tid]);
                    }
                    $sort = 0;
                    foreach ($dayData['items'] as $line) {
                        $vid = $line['variant_id'];
                        $price = self::currentPrice($line['variant']);
                        if (isset($old[$vid]) && !$d['refresh_prices']) {
                            $price = (float) $old[$vid]['unit_price_inr'];
                        }
                        $insItem->execute([
                            $tid, $vid, $line['qty'], self::unitLabelForVariant($line['variant']), $price,
                            $line['max_qty'], $sort++,
                        ]);
                    }
                }
                // Shrunk plan: keep rows (non-destructive) but stop using days beyond the new duration.
                $pdo->prepare(
                    'UPDATE subscription_menu_templates SET is_active = 0 WHERE menu_plan_id = ? AND day_index > ?'
                )->execute([$id, $d['duration_days']]);

                return $id;
            });
        } catch (Throwable $e) {
            error_log('SubscriptionMenuPlanService::save: ' . $e->getMessage());

            return ['ok' => false, 'errors' => ['Could not save the menu plan. No changes were made.']];
        }

        $entries = 0;
        $configured = 0;
        foreach ($d['days'] as $dd) {
            $c = count($dd['items']);
            $entries += $c;
            if ($c > 0) {
                $configured++;
            }
        }
        AuditLog::write($planId === null ? 'subscription.menu_plan_created' : 'subscription.menu_plan_updated', 'menu_plan', (string) $id, [
            'name' => $d['name'], 'duration_days' => $d['duration_days'], 'configured_days' => $configured, 'is_active' => $d['is_active'],
        ]);

        return [
            'ok' => true,
            'id' => $id,
            'summary' => [
                'name' => $d['name'],
                'duration_days' => $d['duration_days'],
                'days_saved' => count($d['days']),
                'configured_days' => $configured,
                'total_entries' => $entries,
            ],
        ];
    }

    /** @return array{ok: bool, message: string} */
    public static function setActive(int $id, bool $active): array
    {
        $plan = self::find($id);
        if ($plan === null) {
            return ['ok' => false, 'message' => 'Menu plan not found.'];
        }
        Install::ensureConnection();
        if ($active && (int) ($plan['subscription_plan_id'] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'Edit the plan and choose a subscription plan before making it live.'];
        }
        Database::transaction(static function () use ($id, $active, $plan): void {
            $pdo = Database::pdo();
            if ($active) {
                $pdo->prepare('UPDATE subscription_menu_plans SET is_active = 0 WHERE subscription_plan_id = ? AND id <> ?')
                    ->execute([(int) $plan['subscription_plan_id'], $id]);
                $pdo->prepare('UPDATE subscription_menu_plans SET is_active = 1, activated_at = COALESCE(activated_at, NOW()) WHERE id = ?')
                    ->execute([$id]);
            } else {
                $pdo->prepare('UPDATE subscription_menu_plans SET is_active = 0 WHERE id = ?')->execute([$id]);
            }
        });
        AuditLog::write('subscription.menu_plan_active', 'menu_plan', (string) $id, ['active' => $active]);

        return ['ok' => true, 'message' => $active ? 'Menu plan is now live.' : 'Menu plan deactivated.'];
    }

    /** @return array{ok: bool, message: string} */
    public static function delete(int $id): array
    {
        $plan = self::find($id);
        if ($plan === null) {
            return ['ok' => false, 'message' => 'Menu plan not found.'];
        }
        if ($plan['activated_at'] !== null) {
            return [
                'ok' => false,
                'message' => 'This menu plan has been live for customers, so it cannot be deleted. Deactivate it instead.',
            ];
        }
        Install::ensureConnection();
        Database::pdo()->prepare('DELETE FROM subscription_menu_plans WHERE id = ?')->execute([$id]);
        AuditLog::write('subscription.menu_plan_deleted', 'menu_plan', (string) $id, ['name' => (string) $plan['name']]);

        return ['ok' => true, 'message' => 'Menu plan deleted.'];
    }

    /** Suggested daily basket value (sum of qty x price). */
    public static function dayValue(array $items): float
    {
        $t = 0.0;
        foreach ($items as $it) {
            $t += (float) $it['qty'] * (float) $it['price'];
        }

        return round($t, 2);
    }
}
