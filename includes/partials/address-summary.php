<?php

declare(strict_types=1);

if (!function_exists('at_address_summary_html')) {
    /**
     * Readable, escaped address block for cards (label, receiver, phone, address, landmark, pin status).
     *
     * @param array<string, mixed> $a customer_addresses row
     */
    function at_address_summary_html(array $a, bool $showDefault = true): string
    {
        $e = static function ($v): string {
            return Security::escape((string) $v);
        };
        $hasPin = AddressInput::hasPin($a);
        $confirmed = $hasPin && !empty($a['location_confirmed_at']);
        $receiver = trim((string) ($a['receiver_name'] ?? ''));
        $phone = trim((string) ($a['receiver_phone'] ?? ''));
        $landmark = AddressInput::landmarkOf($a);
        $full = AddressInput::fullAddress($a);

        $h = '<span class="at-addr-card__title">' . $e($a['label'] ?? '');
        if ($showDefault && (int) ($a['is_default'] ?? 0) === 1) {
            $h .= ' <span class="at-badge at-badge--success">Default</span>';
        }
        $h .= '</span>';

        if ($receiver !== '' || $phone !== '') {
            $h .= '<span class="at-addr-card__receiver">';
            if ($receiver !== '') {
                $h .= '<span class="at-addr-card__receiver-name">' . $e($receiver) . '</span>';
            }
            if ($phone !== '') {
                $h .= '<span class="at-addr-card__receiver-phone">' . $e($phone) . '</span>';
            }
            $h .= '</span>';
        } else {
            $h .= '<span class="at-addr-card__meta block at-muted">No receiver added yet — edit to add one</span>';
        }

        $h .= '<span class="at-addr-card__lines">' . $e($full) . '</span>';
        if ($landmark !== '') {
            $h .= '<span class="at-addr-card__meta block">Landmark: ' . $e($landmark) . '</span>';
        }
        $h .= '<span class="at-addr-card__pin ' . ($hasPin ? 'is-set' : 'is-missing') . '" role="status">'
            . ($hasPin ? ($confirmed ? 'Map pin saved ✓' : 'Map pin saved (confirm on edit)') : 'No map pin yet')
            . '</span>';

        return $h;
    }
}
