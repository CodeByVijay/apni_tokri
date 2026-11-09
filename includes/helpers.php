<?php

declare(strict_types=1);

final class App
{
    /** @var array<string, mixed>|null */
    private static $config = null;

    /** @param array<string, mixed> $config */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        if (self::$config === null) {
            throw new RuntimeException('Application config not initialized.');
        }

        return self::$config;
    }

    public static function env(): string
    {
        return (string) (self::config()['app']['env'] ?? 'local');
    }

    public static function isDebug(): bool
    {
        return (bool) (self::config()['app']['debug'] ?? false);
    }

    public static function baseUrl(): string
    {
        return rtrim((string) (self::config()['app']['base_url'] ?? ''), '/');
    }

    /** Web path prefix for internal links (URL-encoded segments). */
    public static function urlPath(): string
    {
        $path = (string) (self::config()['app']['url_path'] ?? '');
        if ($path === '') {
            $parsed = parse_url(self::baseUrl(), PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '';
        }
        $segments = array_filter(explode('/', trim($path, '/')));
        $encoded = implode('/', array_map('rawurlencode', $segments));

        return '/' . $encoded;
    }
}

function url_base(): string
{
    return App::urlPath();
}

/**
 * Build an internal URL path with the project prefix applied exactly once.
 * Accepts app-relative (/admin/foo) or already-prefixed (/Apni_Tokri/admin/foo) paths.
 */
function url_to(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return url_base() !== '' ? url_base() . '/' : '/';
    }

    $pathPart = parse_url($path, PHP_URL_PATH);
    if (!is_string($pathPart) || $pathPart === '') {
        $pathPart = $path;
    }
    $query = parse_url($path, PHP_URL_QUERY);
    $fragment = parse_url($path, PHP_URL_FRAGMENT);

    $base = url_base();
    if ($base !== '' && (str_starts_with($pathPart, $base . '/') || $pathPart === $base)) {
        $out = $pathPart;
    } else {
        $out = ($base === '' ? '' : $base) . '/' . ltrim($pathPart, '/');
    }
    if (is_string($query) && $query !== '') {
        $out .= '?' . $query;
    }
    if (is_string($fragment) && $fragment !== '') {
        $out .= '#' . $fragment;
    }

    return $out;
}

/**
 * Strip the project url_path prefix from a request URI for safe ?next= handling.
 */
function url_app_relative(string $requestUri): string
{
    if ($requestUri === '') {
        return '';
    }
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '';
    }
    $base = url_base();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base)) ?: '/';
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    if (str_contains($path, '//')) {
        return '';
    }
    $query = parse_url($requestUri, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        $path .= '?' . $query;
    }

    return $path;
}

function asset_url(string $relativePath): string
{
    $base = url_base();
    $relativePath = ltrim($relativePath, '/');

    $url = ($base === '' ? '' : $base) . '/' . $relativePath;
    $needsVersion = preg_match('#^assets/(css|js)/.+\.(css|js)$#', $relativePath) === 1
        || preg_match('#^uploads/.+\.(jpe?g|png|gif|webp|svg|ico)$#i', $relativePath) === 1;
    if ($needsVersion) {
        $file = APNI_TOKRI_ROOT . '/' . $relativePath;
        if (is_file($file)) {
            $mtime = filemtime($file);
            if ($mtime !== false) {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $mtime;
            }
        }
    }

    return $url;
}

/** @param array<string, mixed> $overrides */
function app_view(string $relativePath, array $overrides = []): void
{
    extract($overrides, EXTR_SKIP);
    $path = APNI_TOKRI_ROOT . '/includes/layout/' . ltrim($relativePath, '/');
    if (!is_readable($path)) {
        throw new RuntimeException('View not found: ' . $relativePath);
    }
    require $path;
}

/**
 * Compact status pill variant for customer portal (orders, tickets, refunds).
 *
 * @return ok|warn|bad|info|neutral
 */
function customer_status_variant(string $status, string $context = 'generic'): string
{
    $s = strtolower(trim(str_replace([' ', '-'], '_', $status)));
    if ($context === 'ticket') {
        switch ($s) {
            case TicketStatus::OPEN:
                return 'info';
            case TicketStatus::ASSIGNED:
                return 'neutral';
            case TicketStatus::IN_PROGRESS:
                return 'warn';
            case TicketStatus::RESOLVED:
                return 'ok';
            case TicketStatus::CLOSED:
                return 'neutral';
            default:
                return 'neutral';
        }
    }
    if ($context === 'refund') {
        switch ($s) {
            case RefundStatus::REQUESTED:
            case RefundStatus::UNDER_REVIEW:
            case RefundStatus::PROCESSING:
            case RefundStatus::REFUND_INITIATED:
                return 'warn';
            case RefundStatus::APPROVED:
            case RefundStatus::REFUNDED:
                return 'ok';
            case RefundStatus::REJECTED:
            case RefundStatus::REFUND_FAILED:
                return 'bad';
            case RefundStatus::CANCELLED:
                return 'neutral';
            default:
                return 'neutral';
        }
    }
    if ($context === 'order') {
        switch ($s) {
            case OrderStatus::CONFIRMED:
            case OrderStatus::DELIVERED:
                return 'ok';
            case OrderStatus::PENDING_PAYMENT:
                return 'warn';
            case OrderStatus::PAYMENT_FAILED:
            case OrderStatus::CANCELLED:
                return 'bad';
            default:
                return 'info';
        }
    }
    switch ($s) {
        default:
            return 'neutral';
    }
}

function customer_status_badge(string $status, ?string $label = null, string $context = 'generic'): string
{
    $variant = customer_status_variant($status, $context);
    $text = $label ?? ucwords(str_replace('_', ' ', $status));

    return '<span class="at-status at-status--' . Security::escape($variant) . '">'
        . Security::escape($text) . '</span>';
}

function app_datetime_from_mysql(?string $mysqlDatetime): ?DateTimeImmutable
{
    if ($mysqlDatetime === null || trim($mysqlDatetime) === '') {
        return null;
    }
    $tzName = class_exists('DeliveryConfig', false) ? DeliveryConfig::timezone() : 'Asia/Kolkata';
    try {
        $tz = new DateTimeZone($tzName);

        return new DateTimeImmutable(trim($mysqlDatetime), $tz);
    } catch (Throwable $e) {
        $ts = strtotime($mysqlDatetime);
        if ($ts === false) {
            return null;
        }

        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($tzName));
    }
}

function customer_format_datetime(?string $mysqlDatetime): string
{
    $dt = app_datetime_from_mysql($mysqlDatetime);
    if ($dt === null) {
        if ($mysqlDatetime === null || trim($mysqlDatetime) === '') {
            return '—';
        }

        return Security::escape($mysqlDatetime);
    }

    return $dt->format('j M Y, g:i A');
}

/** Admin audit timestamps: YYYY-MM-DD hh:mm:ss AM/PM in app timezone. */
function admin_format_audit_datetime(?string $mysqlDatetime): string
{
    $dt = app_datetime_from_mysql($mysqlDatetime);
    if ($dt === null) {
        return '—';
    }

    return $dt->format('Y-m-d h:i:s A');
}
