<?php

declare(strict_types=1);

/**
 * Shared placeholder for routes not yet built (Stage 2+).
 *
 * @param array{title: string, message: string, activeNav?: string} $opts
 */
function render_stub_page(array $opts): void
{
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    Security::sendSecurityHeaders(Security::isHttps());
    layout_public_start([
        'pageTitle' => $opts['title'] . ' — Apni Tokri',
        'activeNav' => $opts['activeNav'] ?? '',
    ]);
    echo '<div class="at-container py-12 md:py-20"><div class="at-empty">';
    echo '<div class="at-empty__icon">' . at_icon('file') . '</div>';
    echo '<h1 class="at-h2 !text-2xl">' . Security::escape($opts['title']) . '</h1>';
    echo '<p class="mt-2 text-sm at-muted">' . Security::escape($opts['message']) . '</p>';
    echo '<a href="' . Security::escape(url_base()) . '/index.php" class="at-btn at-btn--primary at-focusable mt-6">Back to home</a>';
    echo '</div></div>';
    layout_public_end();
}
