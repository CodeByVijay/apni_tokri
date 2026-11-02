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

function admin_flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['admin_flash'] = ['message' => $message, 'type' => $type];
}

function admin_flash_render(): void
{
    if (empty($_SESSION['admin_flash']) || !is_array($_SESSION['admin_flash'])) {
        return;
    }
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
    $type = ($flash['type'] ?? 'success') === 'error' ? 'error' : 'info';
    if ($type === 'success') {
        $type = 'success';
    }
    $class = 'at-alert--' . ($type === 'error' ? 'error' : ($type === 'success' ? 'success' : 'info'));
    echo '<div class="at-alert ' . Security::escape($class) . ' mb-4">' . Security::escape((string) ($flash['message'] ?? '')) . '</div>';
}

function admin_handle_demo_post(string $successMessage): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::requireValid();
        if (Install::isSchemaReady() && AdminAuth::check()) {
            AuditLog::write('admin.form_save', null, null, ['message' => $successMessage]);
        }
        admin_flash_set($successMessage);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        header('Location: ' . ($path ?: ''), true, 302);
        exit;
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
    $ok = ['delivered', 'active', 'confirmed', 'paid', 'approved', 'published', 'completed', 'sent', 'resolved', 'captured', 'success', 'in stock', 'yes', 'resolved manual', 'resolved refund'];
    $bad = ['cancelled', 'canceled', 'payment failed', 'failed', 'rejected', 'out of stock', 'error', 'expired'];
    $info = ['processing', 'preparing', 'assigned', 'in progress', 'open', 'refunded', 'partially refunded', 'out for delivery', 'dispatched', 'shipped'];
    $neutral = ['inactive', 'draft', 'closed', 'archived', 'disabled', 'no', 'skipped', 'paused'];
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
