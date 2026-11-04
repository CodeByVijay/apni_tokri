<?php

declare(strict_types=1);

/**
 * Server-side protection against duplicate menu-plan POSTs (double-click / refresh).
 * Tokens are single-use; a repeated submit with the same token redirects idempotently when save completed.
 */
final class MenuPlanSubmitGuard
{
    private const SESSION_KEY = '_menu_plan_submit_tokens';
    private const MAX_AGE_SECONDS = 3600;
    private const MAX_TOKENS = 40;

    public static function issueSaveToken(): string
    {
        self::ensureSession();
        $token = Security::randomToken(16);
        $_SESSION[self::SESSION_KEY][$token] = [
            'kind' => 'save',
            'issued' => time(),
            'completed_plan_id' => null,
        ];
        self::prune();

        return $token;
    }

    public static function issueActionToken(string $action): string
    {
        $action = self::normalizeAction($action);
        self::ensureSession();
        $token = Security::randomToken(16);
        $_SESSION[self::SESSION_KEY]['action:' . $action . ':' . $token] = [
            'kind' => 'action',
            'action' => $action,
            'issued' => time(),
            'consumed' => false,
        ];
        self::prune();

        return $token;
    }

    /**
     * @return array{ok: bool, idempotent?: bool, plan_id?: int, message?: string}
     */
    public static function beginSave(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'message' => 'Missing save token. Refresh the page and try again.'];
        }
        self::ensureSession();
        $entry = $_SESSION[self::SESSION_KEY][$token] ?? null;
        if (!is_array($entry) || ($entry['kind'] ?? '') !== 'save') {
            return ['ok' => false, 'message' => 'This save was already submitted or expired. Refresh the page and try again.'];
        }
        if (!empty($entry['completed_plan_id'])) {
            return ['ok' => true, 'idempotent' => true, 'plan_id' => (int) $entry['completed_plan_id']];
        }

        return ['ok' => true];
    }

    public static function completeSave(string $token, int $planId): void
    {
        self::ensureSession();
        if (isset($_SESSION[self::SESSION_KEY][$token]) && is_array($_SESSION[self::SESSION_KEY][$token])) {
            $_SESSION[self::SESSION_KEY][$token]['completed_plan_id'] = $planId;
        }
    }

    /**
     * @return array{ok: bool, idempotent?: bool, message?: string}
     */
    public static function consumeAction(string $action, string $token): array
    {
        $action = self::normalizeAction($action);
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'message' => 'Missing action token. Refresh and try again.'];
        }
        self::ensureSession();
        $key = 'action:' . $action . ':' . $token;
        $entry = $_SESSION[self::SESSION_KEY][$key] ?? null;
        if (!is_array($entry)) {
            return ['ok' => false, 'message' => 'This action was already submitted or expired. Refresh the page.'];
        }
        if (!empty($entry['consumed'])) {
            return ['ok' => true, 'idempotent' => true];
        }
        $_SESSION[self::SESSION_KEY][$key]['consumed'] = true;

        return ['ok' => true];
    }

    private static function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['activate', 'deactivate', 'delete'], true)) {
            return 'invalid';
        }

        return $action;
    }

    private static function ensureSession(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
    }

    private static function prune(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return;
        }
        $now = time();
        foreach ($_SESSION[self::SESSION_KEY] as $k => $entry) {
            if (!is_array($entry)) {
                unset($_SESSION[self::SESSION_KEY][$k]);
                continue;
            }
            $issued = (int) ($entry['issued'] ?? 0);
            if ($issued > 0 && ($now - $issued) > self::MAX_AGE_SECONDS) {
                unset($_SESSION[self::SESSION_KEY][$k]);
            }
        }
        if (count($_SESSION[self::SESSION_KEY]) > self::MAX_TOKENS) {
            uasort($_SESSION[self::SESSION_KEY], static function ($a, $b): int {
                return ((int) ($a['issued'] ?? 0)) <=> ((int) ($b['issued'] ?? 0));
            });
            $_SESSION[self::SESSION_KEY] = array_slice($_SESSION[self::SESSION_KEY], -self::MAX_TOKENS, null, true);
        }
    }
}
