<?php

declare(strict_types=1);

/**
 * Standard JSON response envelope for AJAX endpoints.
 *
 * Success: { "success": true, "data": { ... }, "message": "optional" }
 * Error:   { "success": false, "error": { "code": "...", "message": "..." }, "errors": { field: msg } }
 */
final class Ajax
{
    public static function isAjaxRequest(): bool
    {
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return strcasecmp((string) $requestedWith, 'XMLHttpRequest') === 0
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** @param array<string, mixed>|list<mixed>|null $data */
    public static function success(?array $data = null, string $message = '', int $httpCode = 200)
    {
        self::send([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $httpCode);
    }

    /**
     * @param array<string, string>|null $fieldErrors
     */
    public static function error(
        string $message,
        int $httpCode = 400,
        string $code = 'error',
        ?array $fieldErrors = null
    ) {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
        if ($fieldErrors !== null && $fieldErrors !== []) {
            $payload['errors'] = $fieldErrors;
        }
        self::send($payload, $httpCode);
    }

    /** @param array<string, mixed> $payload */
    private static function send(array $payload, int $httpCode)
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
