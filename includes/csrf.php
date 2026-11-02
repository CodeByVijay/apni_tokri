<?php

declare(strict_types=1);

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = Security::randomToken(32);
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        $name = self::fieldName();
        $value = self::token();

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            Security::escape($name),
            Security::escape($value)
        );
    }

    public static function fieldName(): string
    {
        return '_csrf_token';
    }

    public static function validateRequest(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        $token = $_POST[self::fieldName()] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || $token === '') {
            return false;
        }

        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        return Security::hashEquals($sessionToken, $token);
    }

    public static function requireValid(): void
    {
        if (!self::validateRequest()) {
            if (Ajax::isAjaxRequest()) {
                Ajax::error('Invalid or missing CSRF token.', 403);
            }
            http_response_code(403);
            echo 'Invalid or missing CSRF token.';
            exit;
        }
    }
}
