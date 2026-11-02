<?php

declare(strict_types=1);

final class AppErrorHandler
{
    /** @param array<string, mixed> $config */
    public static function register(array $config): void
    {
        $debug = (bool) ($config['app']['debug'] ?? false);

        error_reporting(E_ALL);
        ini_set('log_errors', '1');

        $logDir = APNI_TOKRI_ROOT . '/storage/logs';
        if (is_dir($logDir) && is_writable($logDir)) {
            ini_set('error_log', $logDir . '/php-errors.log');
        }

        set_exception_handler(static function (Throwable $e) use ($debug): void {
            self::logThrowable($e);

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, ($debug ? $e->__toString() : 'An error occurred.') . PHP_EOL);
                exit(1);
            }

            http_response_code(500);
            if ($debug) {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Application error: ' . Security::escape($e->getMessage());
            } else {
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head>';
                echo '<body><p>Something went wrong. Please try again later.</p></body></html>';
            }
            exit(1);
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($debug): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    private static function logThrowable(Throwable $e): void
    {
        $line = sprintf(
            "[%s] %s in %s:%d\n",
            date('c'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        error_log($line);
    }
}
