<?php

declare(strict_types=1);

/**
 * Central admin authentication and authorization gate (server-side only).
 */
final class AdminGuard
{
    /** @var list<string> */
    private const PUBLIC_SCRIPTS = [
        'login.php',
    ];

    /** Allowed while factory seed password is still in use. */
    /** @var list<string> */
    private const PASSWORD_CHANGE_SCRIPTS = [
        'change-password.php',
        'logout.php',
    ];

    public static function isPublicScript(string $scriptBasename): bool
    {
        return in_array($scriptBasename, self::PUBLIC_SCRIPTS, true);
    }

    public static function isAjaxRequest(): bool
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return false;
    }

    /**
     * Enforce authenticated admin session for all non-public admin scripts.
     */
    public static function requireAuthentication(): void
    {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (self::isPublicScript($script)) {
            return;
        }

        if (!Install::isSchemaReady()) {
            self::denyUnauthenticated('Admin requires an installed database schema. Sign in after import.');

            return;
        }

        if (!AdminAuth::check()) {
            self::denyUnauthenticated(null);

            return;
        }

        if (AdminAuth::requiresMandatoryPasswordChange() && !in_array($script, self::PASSWORD_CHANGE_SCRIPTS, true)) {
            self::denyPasswordChangeRequired();
        }
    }

    public static function requireModule(string $module): void
    {
        self::requireAuthentication();
        if (!AdminDemoPerms::can($module)) {
            self::denyForbidden();
        }
    }

    private static function denyUnauthenticated(?string $message): void
    {
        if (self::isAjaxRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'unauthenticated'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $next = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? url_app_relative($_SERVER['REQUEST_URI']) : '';
        $login = url_to('/admin/login.php');
        if ($next !== '' && !str_contains($next, '/admin/login.php')) {
            $login .= '?next=' . rawurlencode($next);
        }
        if ($message !== null) {
            $_SESSION['admin_login_notice'] = $message;
        }
        header('Location: ' . $login, true, 302);
        exit;
    }

    private static function denyPasswordChangeRequired(): void
    {
        if (self::isAjaxRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'password_change_required'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . url_to('/admin/change-password.php'), true, 302);
        exit;
    }

    private static function denyForbidden(): void
    {
        if (self::isAjaxRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(403);
        layout_admin_start([
            'pageTitle' => 'Access denied',
            'activeAdminNav' => 'dashboard',
        ]);
        echo '<div class="at-alert at-alert--error">You do not have permission to access this module.</div>';
        layout_admin_end();
        exit;
    }

    /**
     * @return list<string> Absolute paths to admin PHP entry scripts (for audits/tests).
     *
     * @return list<string>
     */
    public static function protectedAdminScripts(): array
    {
        $root = APNI_TOKRI_ROOT . '/admin';
        $paths = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $base = $file->getBasename();
            if ($base === '_init.php') {
                continue;
            }
            if (self::isPublicScript($base)) {
                continue;
            }
            $paths[] = $file->getPathname();
        }
        sort($paths);

        return $paths;
    }
}
