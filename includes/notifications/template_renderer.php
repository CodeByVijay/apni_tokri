<?php

declare(strict_types=1);

final class NotificationTemplateRenderer
{
    /** @var list<string> */
    private static $forbiddenKeys = ['otp', 'password', 'api_key', 'secret', 'token'];

    /**
     * @param array<string, mixed> $variables
     */
    public static function render(string $template, array $variables, bool $htmlEscape): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $m) use ($variables, $htmlEscape): string {
                $key = $m[1];
                if (in_array(strtolower($key), self::$forbiddenKeys, true)) {
                    return '';
                }
                $val = isset($variables[$key]) ? (string) $variables[$key] : '';

                return $htmlEscape ? Security::escape($val) : $val;
            },
            $template
        ) ?? $template;
    }

    /**
     * @param array<string, mixed> $templateRow
     * @param array<string, mixed> $variables
     * @return array{subject?: string, html?: string, text?: string, sms?: string, in_app_title?: string, in_app_body?: string}
     */
    public static function renderForChannel(array $templateRow, array $variables): array
    {
        $channel = (string) ($templateRow['channel'] ?? '');
        $out = [];
        if ($channel === 'email') {
            $out['subject'] = self::render((string) ($templateRow['subject'] ?? ''), $variables, false);
            $out['html'] = self::render((string) ($templateRow['body_html'] ?? ''), $variables, true);
            $out['text'] = self::render((string) ($templateRow['body_text'] ?? ''), $variables, false);
        } elseif ($channel === 'sms') {
            $out['sms'] = self::render((string) ($templateRow['sms_body'] ?? ''), $variables, false);
        } elseif ($channel === 'in_app') {
            $out['in_app_title'] = self::render((string) ($templateRow['in_app_title'] ?? ''), $variables, false);
            $out['in_app_body'] = self::render((string) ($templateRow['in_app_body'] ?? ''), $variables, false);
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    public static function sanitizePayloadForLog(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            if (in_array(strtolower((string) $k), self::$forbiddenKeys, true)) {
                $out[$k] = '[redacted]';
            } elseif (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            } else {
                $out[$k] = '[complex]';
            }
        }

        return $out;
    }
}
