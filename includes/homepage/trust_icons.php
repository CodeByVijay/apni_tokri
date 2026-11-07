<?php

declare(strict_types=1);

/**
 * Trust section icons — same identifiers as storefront {@see at_icon()}.
 */
final class HomepageTrustIcons
{
    /** @var array<string, string> */
    private const ALIASES = [
        'refresh-cw' => 'refresh',
        'message-circle' => 'chat',
        'shield-check' => 'shield',
        'check-circle' => 'check',
        'shopping-basket' => 'basket',
        'map-pin' => 'pin',
    ];

    /** @var array<string, string> canonical id => admin label */
    private const LABELS = [
        'truck' => 'Delivery truck',
        'leaf' => 'Fresh produce',
        'refresh' => 'Refresh / returns',
        'chat' => 'Chat / support',
        'shield' => 'Shield / trust',
        'clock' => 'Clock / timing',
        'heart' => 'Heart / care',
        'check' => 'Check mark',
        'basket' => 'Shopping basket',
        'pin' => 'Map pin',
        'receipt' => 'Receipt / pricing',
        'box' => 'Box / packing',
        'star' => 'Star / reviews',
        'lock' => 'Lock / secure',
    ];

    /** @return list<string> */
    public static function canonicalIds(): array
    {
        return array_keys(self::LABELS);
    }

    /** Normalize admin input or stored value to a canonical icon id, or null if unsupported. */
    public static function normalizeStoredId(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/[^a-z0-9_-]/', '', $raw) ?? '';
        if ($raw === '') {
            return null;
        }
        $id = self::ALIASES[$raw] ?? $raw;
        if (!isset(self::LABELS[$id])) {
            return null;
        }

        return $id;
    }

    /** Storefront-safe icon id (canonical or fallback). */
    public static function resolveForRender(string $stored): string
    {
        $id = self::normalizeStoredId($stored);
        if ($id !== null) {
            return $id;
        }
        if ($stored !== '') {
            return 'check';
        }

        return 'check';
    }

    public static function labelFor(string $canonicalId): string
    {
        return self::LABELS[$canonicalId] ?? ucfirst($canonicalId);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function pickerOptions(): array
    {
        $priority = ['truck', 'leaf', 'refresh', 'chat', 'shield', 'clock', 'heart', 'check', 'basket', 'pin'];
        $out = [];
        foreach ($priority as $id) {
            $out[] = ['id' => $id, 'label' => self::LABELS[$id]];
        }
        foreach (self::canonicalIds() as $id) {
            if (in_array($id, $priority, true)) {
                continue;
            }
            $out[] = ['id' => $id, 'label' => self::LABELS[$id]];
        }

        return $out;
    }
}
