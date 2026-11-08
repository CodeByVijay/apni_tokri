<?php

declare(strict_types=1);

/**
 * Storefront/admin delivery slot labels: "{Name} ({Start} – {End})" from configured slot times.
 * Historical orders prefer delivery_snapshot_json times; live slot rows are not used when a snapshot exists.
 */
final class DeliverySlotDisplay
{
    /** @return array{label: ?string, time_range: ?string, display_line: ?string} */
    public static function resolveForOrder(array $orderRow, ?PDO $pdo = null): array
    {
        $snap = DeliveryOrderSnapshot::fromOrderRow($orderRow);
        $label = '';
        $start = '';
        $end = '';

        if ($snap !== null) {
            if (!empty($snap['admin_delivery_override'])) {
                $start = (string) ($snap['custom_delivery_start'] ?? '');
                $end = (string) ($snap['custom_delivery_end'] ?? '');
                $label = trim((string) ($snap['slot_label'] ?? $snap['custom_delivery_window_label'] ?? ''));
            } else {
                $label = trim((string) ($snap['slot_label'] ?? ''));
                $start = (string) ($snap['slot_start_time'] ?? '');
                $end = (string) ($snap['slot_end_time'] ?? '');
            }
        }

        if ($label === '') {
            $label = trim((string) ($orderRow['delivery_slot'] ?? ''));
        }

        if (($start === '' || $end === '') && $snap === null) {
            $live = self::liveSlotRowForOrder($orderRow, $pdo);
            if ($live !== null) {
                if ($label === '') {
                    $label = (string) ($live['label'] ?? '');
                }
                if ($start === '') {
                    $start = (string) ($live['start_time'] ?? '');
                }
                if ($end === '') {
                    $end = (string) ($live['end_time'] ?? '');
                }
            }
        }

        $timeRange = self::formatWindowRange($start, $end);
        $displayLine = self::formatLabelWithWindow($label, $start, $end);
        if ($displayLine === '—' && $label !== '') {
            $displayLine = $label;
        }

        return [
            'label' => $label !== '' ? $label : null,
            'time_range' => $timeRange,
            'display_line' => $displayLine !== '—' ? $displayLine : null,
        ];
    }

    /** @param array<string, mixed> $slotRow delivery_slots row */
    public static function formatFromSlotRow(array $slotRow): string
    {
        $label = trim((string) ($slotRow['label'] ?? $slotRow['slug'] ?? ''));

        return self::formatLabelWithWindow(
            $label,
            (string) ($slotRow['start_time'] ?? ''),
            (string) ($slotRow['end_time'] ?? '')
        );
    }

    public static function formatWindowRange(?string $start, ?string $end): ?string
    {
        $start = trim((string) $start);
        $end = trim((string) $end);
        if ($start === '' && $end === '') {
            return null;
        }
        $startF = $start !== '' ? self::formatClockTime12h($start) : '';
        $endF = $end !== '' ? self::formatClockTime12h($end) : '';
        if ($startF !== '' && $startF !== '—' && $endF !== '' && $endF !== '—') {
            return $startF . ' – ' . $endF;
        }
        if ($startF !== '' && $startF !== '—') {
            return $startF;
        }
        if ($endF !== '' && $endF !== '—') {
            return $endF;
        }

        return null;
    }

    public static function formatLabelWithWindow(string $label, ?string $start, ?string $end): string
    {
        $label = trim($label);
        $range = self::formatWindowRange($start, $end);
        if ($range === null || $range === '') {
            return $label !== '' ? $label : '—';
        }
        if ($label === '') {
            return $range;
        }

        return $label . ' (' . $range . ')';
    }

    public static function formatClockTime12h(string $time): string
    {
        return DeliveryAdminUi::formatClockTime12h($time);
    }

    /**
     * Checkout / subscription slot card payload (capacity + date unchanged).
     *
     * @param array<string, mixed> $slotRow
     * @return array<string, mixed>
     */
    public static function checkoutCard(array $slotRow, string $deliveryDate, int $remaining): array
    {
        $range = self::formatWindowRange(
            (string) ($slotRow['start_time'] ?? ''),
            (string) ($slotRow['end_time'] ?? '')
        );
        $rangeDisp = $range ?? '—';

        return [
            'id' => (int) ($slotRow['id'] ?? 0),
            'slug' => (string) ($slotRow['slug'] ?? ''),
            'label' => (string) ($slotRow['label'] ?? ''),
            'time_range' => $range,
            'window' => $rangeDisp,
            'delivery_date' => $deliveryDate,
            'remaining' => $remaining,
        ];
    }

    /** @return array{label: string, start_time: string, end_time: string}|null */
    private static function liveSlotRowForOrder(array $orderRow, ?PDO $pdo): ?array
    {
        if (!Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        $slotId = (int) ($orderRow['delivery_slot_id'] ?? 0);
        if ($slotId > 0) {
            $st = $pdo->prepare('SELECT label, slug, start_time, end_time FROM delivery_slots WHERE id = ? LIMIT 1');
            $st->execute([$slotId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        }
        $slug = trim((string) ($orderRow['delivery_slot'] ?? ''));
        if ($slug === '' || $slug === 'auto') {
            return null;
        }
        $st = $pdo->prepare('SELECT label, slug, start_time, end_time FROM delivery_slots WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
