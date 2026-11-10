<?php

declare(strict_types=1);

/**
 * Stage 4 — permission-aware admin UI (demo roles until Stage 5 auth).
 */
final class AdminDemoPerms
{
    /** @var array<string, list<string>> */
    private static $matrix = [
        'super_admin' => ['*'],
        'order_manager' => ['orders', 'deliveries', 'customers', 'support', 'refunds', 'payments', 'dashboard'],
        'inventory_manager' => ['products', 'categories', 'inventory', 'dashboard'],
        'support' => ['support', 'reviews', 'refunds', 'customers', 'faqs', 'dashboard'],
        'finance' => ['payments', 'refunds', 'reports', 'coupons', 'offers', 'dashboard'],
    ];

    public static function role(): string
    {
        $role = $_SESSION['admin_demo_role'] ?? 'super_admin';
        return isset(self::$matrix[$role]) ? $role : 'super_admin';
    }

    public static function setRole(string $role): void
    {
        if (isset(self::$matrix[$role])) {
            $_SESSION['admin_demo_role'] = $role;
        }
    }

    public static function can(string $module): bool
    {
        if (!Install::isSchemaReady() || !AdminAuth::check()) {
            return false;
        }

        return Permission::canModule($module);
    }

    public static function canForRole(string $role, string $module): bool
    {
        if (!isset(self::$matrix[$role])) {
            return false;
        }
        $allowed = self::$matrix[$role];
        if (in_array('*', $allowed, true)) {
            return true;
        }

        return in_array($module, $allowed, true);
    }

    /** @return list<string, string> */
    public static function roleLabels(): array
    {
        return [
            'super_admin' => 'Super Admin',
            'order_manager' => 'Order Manager',
            'inventory_manager' => 'Inventory Manager',
            'support' => 'Customer Support',
            'finance' => 'Finance / Refunds',
        ];
    }

    public static function denyIfCannot(string $module): void
    {
        AdminGuard::requireModule($module);
    }
}

/** @param array<string, mixed> $vars */
function admin_layout_start(array $vars): void
{
    layout_admin_start($vars);
}

function admin_layout_end(): void
{
    layout_admin_end();
}

function admin_flash_normalize_type(string $type): string
{
    $type = strtolower(trim($type));
    if (in_array($type, ['success', 'error', 'warning', 'info'], true)) {
        return $type;
    }

    return 'info';
}

function admin_flash_set(string $message, string $type = 'success'): void
{
    if (!isset($_SESSION['admin_flashes']) || !is_array($_SESSION['admin_flashes'])) {
        $_SESSION['admin_flashes'] = [];
    }
    $_SESSION['admin_flashes'][] = [
        'message' => $message,
        'type' => admin_flash_normalize_type($type),
    ];
}

/** @return list<array{message: string, type: string}> */
function admin_flash_consume(): array
{
    $flashes = [];
    if (!empty($_SESSION['admin_flashes']) && is_array($_SESSION['admin_flashes'])) {
        foreach ($_SESSION['admin_flashes'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $msg = trim((string) ($row['message'] ?? ''));
            if ($msg === '') {
                continue;
            }
            $flashes[] = [
                'message' => $msg,
                'type' => admin_flash_normalize_type((string) ($row['type'] ?? 'success')),
            ];
        }
        unset($_SESSION['admin_flashes']);
    }
    if (!empty($_SESSION['admin_flash']) && is_array($_SESSION['admin_flash'])) {
        $legacy = $_SESSION['admin_flash'];
        unset($_SESSION['admin_flash']);
        $msg = trim((string) ($legacy['message'] ?? ''));
        if ($msg !== '') {
            $flashes[] = [
                'message' => $msg,
                'type' => admin_flash_normalize_type((string) ($legacy['type'] ?? 'success')),
            ];
        }
    }

    return $flashes;
}

function admin_flash_render(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    require_once APNI_TOKRI_ROOT . '/includes/partials/admin/flash-messages.php';
    $html = admin_flash_render_html(admin_flash_consume());
    if ($html !== '') {
        echo $html;
    }
}

function admin_handle_demo_post(string $successMessage): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::requireValid();
        admin_redirect_after_persistence_post($successMessage);
    }
}

/** POST without DB: demo flash locally, operational error in production. */
function admin_redirect_after_persistence_post(string $localDemoSuccessMessage): void
{
    if (AdminPersistence::isAvailable()) {
        admin_flash_set($localDemoSuccessMessage);
    } elseif (AppEnvironment::isProduction()) {
        admin_flash_set(AdminPersistence::unavailableMessage(), 'error');
    } else {
        admin_flash_set($localDemoSuccessMessage);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    header('Location: ' . ($path ?: ''), true, 302);
    exit;
}

function admin_flash_persistence_blocked(string $localDemoSuccessMessage): void
{
    if (AppEnvironment::isProduction()) {
        admin_flash_set(AdminPersistence::unavailableMessage(), 'error');
    } else {
        admin_flash_set($localDemoSuccessMessage);
    }
}

function admin_masked_secret(string $label = 'secret'): string
{
    return '************' . substr($label, -4);
}

/**
 * Semantic CSS modifier for a status string (shared by admin tables).
 * ok = completed/positive, bad = failed/negative, info = in-flight/informational,
 * warn = waiting/attention (default), neutral = inactive/closed/draft.
 */
function admin_status_class(string $status): string
{
    $lc = strtolower(trim(str_replace(['_', '-'], ' ', $status)));
    $ok = ['delivered', 'active', 'confirmed', 'paid', 'approved', 'published', 'completed', 'sent', 'resolved', 'captured', 'success', 'in stock', 'yes', 'resolved manual', 'resolved refund', 'passed'];
    $bad = ['cancelled', 'canceled', 'payment failed', 'failed', 'rejected', 'out of stock', 'error', 'expired'];
    $info = ['processing', 'preparing', 'assigned', 'in progress', 'open', 'refunded', 'partially refunded', 'out for delivery', 'dispatched', 'shipped', 'dry run', 'dry_run'];
    $neutral = ['inactive', 'draft', 'closed', 'archived', 'disabled', 'no', 'skipped', 'paused', 'revoked', 'invalid'];
    if (in_array($lc, $ok, true)) {
        return 'at-status--ok';
    }
    if (in_array($lc, $bad, true)) {
        return 'at-status--bad';
    }
    if (in_array($lc, $info, true)) {
        return 'at-status--info';
    }
    if (in_array($lc, $neutral, true)) {
        return 'at-status--neutral';
    }

    return 'at-status--warn';
}

/** Escaped status badge HTML. */
function admin_status_badge(string $status, ?string $label = null): string
{
    $text = $label ?? ucwords(str_replace('_', ' ', $status));
    if (trim($text) === '') {
        return '—';
    }

    return '<span class="at-status ' . admin_status_class($status) . '">' . Security::escape($text) . '</span>';
}
