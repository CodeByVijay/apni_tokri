<?php

declare(strict_types=1);

/** Read-only OPcache status + guarded clear (does not affect browser cache). */
final class AdminServerCache
{
    /** @return array{available: bool, enabled: bool, cached_scripts: int, memory_used_mb: float|null, memory_free_mb: float|null}|null */
    public static function opcacheSummary(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }
        $raw = @opcache_get_status(false);
        if (!is_array($raw)) {
            return [
                'available' => true,
                'enabled' => false,
                'cached_scripts' => 0,
                'memory_used_mb' => null,
                'memory_free_mb' => null,
            ];
        }
        $mem = is_array($raw['memory_usage'] ?? null) ? $raw['memory_usage'] : [];
        $used = isset($mem['used_memory']) ? (int) $mem['used_memory'] : 0;
        $free = isset($mem['free_memory']) ? (int) $mem['free_memory'] : 0;

        return [
            'available' => true,
            'enabled' => !empty($raw['opcache_enabled']),
            'cached_scripts' => (int) ($raw['opcache_statistics']['num_cached_scripts'] ?? 0),
            'memory_used_mb' => $used > 0 ? round($used / 1048576, 1) : null,
            'memory_free_mb' => $free > 0 ? round($free / 1048576, 1) : null,
        ];
    }

    public static function canClearOpcache(): bool
    {
        return function_exists('opcache_reset') && Permission::canModule('settings');
    }

    public static function clearOpcache(): bool
    {
        if (!self::canClearOpcache()) {
            return false;
        }

        return (bool) @opcache_reset();
    }
}
