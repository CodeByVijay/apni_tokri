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
 * Absolute URL (scheme + host + port + app path) for links used outside the app —
 * emails, printed documents, external callbacks. The origin comes from the
 * configured app.base_url, never from the request Host header.
 * Returns the root-relative path unchanged when base_url is not a valid absolute URL.
 */
function absolute_url(string $path): string
{
    $relative = url_to($path);
    $base = App::baseUrl();
    if ($base === '') {
        return $relative;
    }
    $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
    $host = (string) parse_url($base, PHP_URL_HOST);
    if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
        return $relative;
    }
    $port = parse_url($base, PHP_URL_PORT);

    return $scheme . '://' . $host . (is_int($port) ? ':' . $port : '') . $relative;
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

function asset_path_normalize(string $relativePath): string
{
    $path = str_replace('\\', '/', trim($relativePath));
    if (str_starts_with($path, '/')) {
        $path = ltrim($path, '/');
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return $path;
}

/**
 * Stable cache-bust token for mutable files under uploads/ (not random per request).
 */
function upload_asset_version(string $relativePath, ?string $contentHash = null, ?int $mediaId = null): string
{
    $rel = asset_path_normalize($relativePath);
    if (!str_starts_with($rel, 'uploads/')) {
        return '0';
    }
    $parts = [];
    if ($mediaId !== null && $mediaId > 0) {
        $parts[] = 'm' . $mediaId;
    }
    $hash = $contentHash !== null ? strtolower(trim($contentHash)) : '';
    if ($hash !== '') {
        $parts[] = substr($hash, 0, 16);
    }
    $full = APNI_TOKRI_ROOT . '/' . $rel;
    if (is_file($full)) {
        $mtime = filemtime($full);
        $size = filesize($full);
        if ($mtime !== false) {
            $parts[] = (string) $mtime;
        }
        if ($size !== false) {
            $parts[] = (string) $size;
        }
    }

    return $parts !== [] ? implode('.', $parts) : '0';
}

/**
 * Versioned public URL for a catalogue/upload file (admin, media library, storefront).
 */
function upload_asset_url(string $relativePath, ?string $contentHash = null, ?int $mediaId = null): string
{
    $rel = asset_path_normalize($relativePath);
    if ($rel === '' || preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    if (!str_starts_with($rel, 'uploads/')) {
        return asset_url($rel);
    }
    $base = url_base();
    $url = ($base === '' ? '' : $base) . '/' . $rel;
    $version = upload_asset_version($rel, $contentHash, $mediaId);
    if ($version !== '0') {
        $url .= '?v=' . rawurlencode($version);
    }

    return $url;
}

/**
 * @param array<string, mixed> $imageRow product_images row (+ optional media_file_sha256 from join)
 */
function product_image_asset_url(array $imageRow): string
{
    $path = (string) ($imageRow['file_path'] ?? '');
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $sha = '';
    if (isset($imageRow['media_file_sha256']) && (string) $imageRow['media_file_sha256'] !== '') {
        $sha = (string) $imageRow['media_file_sha256'];
    } elseif (isset($imageRow['file_sha256']) && (string) $imageRow['file_sha256'] !== '') {
        $sha = (string) $imageRow['file_sha256'];
    }
    $mediaId = isset($imageRow['media_id']) ? (int) $imageRow['media_id'] : 0;

    return upload_asset_url($path, $sha !== '' ? $sha : null, $mediaId > 0 ? $mediaId : null);
}

function asset_url(string $relativePath): string
{
    $rel = asset_path_normalize($relativePath);
    if ($rel === '' || preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    if (str_starts_with($rel, 'uploads/')) {
        return upload_asset_url($rel);
    }
    $base = url_base();
    $url = ($base === '' ? '' : $base) . '/' . $rel;
    if (preg_match('#^assets/(css|js)/.+\.(css|js)$#', $rel) === 1) {
        $file = APNI_TOKRI_ROOT . '/' . $rel;
        if (is_file($file)) {
            $mtime = filemtime($file);
            if ($mtime !== false) {
                $url .= '?v=' . $mtime;
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
