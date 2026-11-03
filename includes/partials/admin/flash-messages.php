<?php

declare(strict_types=1);

/**
 * Dismissible admin flash messages (rendered once per request from layout).
 *
 * @param list<array{message: string, type: string}> $flashes
 */
function admin_flash_render_html(array $flashes): string
{
    if ($flashes === []) {
        return '';
    }
    if (!function_exists('at_icon')) {
        require_once APNI_TOKRI_ROOT . '/includes/layout/icons.php';
    }
    $iconMap = [
        'success' => 'check',
        'error' => 'circle-x',
        'warning' => 'alert-triangle',
        'info' => 'info',
    ];
    $html = '<div class="at-admin-flashes" data-at-admin-flashes>';
    foreach ($flashes as $flash) {
        $message = trim((string) ($flash['message'] ?? ''));
        if ($message === '') {
            continue;
        }
        $type = admin_flash_normalize_type((string) ($flash['type'] ?? 'success'));
        $role = in_array($type, ['error', 'warning'], true) ? 'alert' : 'status';
        $icon = $iconMap[$type] ?? 'info';
        $html .= '<div class="at-alert at-alert--' . Security::escape($type) . ' at-admin-flash" role="'
            . Security::escape($role) . '" data-at-admin-flash>';
        $html .= '<span class="at-admin-flash__icon" aria-hidden="true">' . at_icon($icon, 'at-admin-flash__svg') . '</span>';
        $html .= '<p class="at-admin-flash__text">' . Security::escape($message) . '</p>';
        $html .= '<button type="button" class="at-admin-flash__close at-focusable" aria-label="Dismiss message" title="Dismiss">'
            . at_icon('close', 'at-admin-flash__close-icon') . '</button>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}
