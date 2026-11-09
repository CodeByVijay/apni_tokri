<?php

declare(strict_types=1);

/**
 * Inline SVG icon set (stroke icons, 24x24 viewBox). No external requests.
 * Usage: echo at_icon('truck', 'w-5 h-5');
 */
function at_icon(string $name, string $class = ''): string
{
    static $paths = null;
    if ($paths === null) {
        $paths = [
            'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/>',
            'grid' => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
            'cart' => '<path d="M3 4h2l2.2 11.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 8H6"/><circle cx="9.5" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/>',
            'bag' => '<path d="M6 8h12l1 12H5L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14.2A6.5 6.5 0 0 1 21.5 20"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
            'pin' => '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
            'truck' => '<path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
            'leaf' => '<path d="M5 19c0-8 5-13 15-14 0 10-5 15-13 15"/><path d="M5 19c2-5 5-8 9-10"/>',
            'shield' => '<path d="M12 3 5 6v5.5c0 4.4 3 8 7 9.5 4-1.5 7-5.1 7-9.5V6l-7-3z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
            'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            'refresh' => '<path d="M20 11a8 8 0 0 0-14.5-4M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.5 4M20 20v-4h-4"/>',
            'repeat' => '<path d="M17 3l3 3-3 3"/><path d="M4 11V9a3 3 0 0 1 3-3h13"/><path d="M7 21l-3-3 3-3"/><path d="M20 13v2a3 3 0 0 1-3 3H4"/>',
            'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
            'chat' => '<path d="M4 5h16v11H9l-5 4V5z"/>',
            'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'minus' => '<path d="M5 12h14"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'box' => '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5v-9z"/><path d="m3.5 7.5 8.5 4.5 8.5-4.5M12 12v9"/>',
            'tag' => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.2"/>',
            'star' => '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.8 6.8 19.6l1-5.8L3.5 9.7l5.9-.9L12 3.5z"/>',
            'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
            'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
            'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7M12 17h.01"/>',
            'chart' => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            'percent' => '<path d="M19 5 5 19"/><circle cx="7" cy="7" r="2.2"/><circle cx="17" cy="17" r="2.2"/>',
            'image' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
            'file' => '<path d="M7 3h7l5 5v13H7V3z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
            'bell' => '<path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17z"/><path d="M10 21h4"/>',
            'logout' => '<path d="M10 4H5v16h5"/><path d="M15 8l4 4-4 4M19 12H9"/>',
            'store' => '<path d="M4 9 5.5 4h13L20 9"/><path d="M4 9a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0A2.7 2.7 0 0 0 20 9"/><path d="M5 12v8h14v-8"/>',
            'wallet' => '<path d="M4 7a2 2 0 0 1 2-2h12v4"/><path d="M4 7v11a2 2 0 0 0 2 2h14V9H6a2 2 0 0 1-2-2z"/><circle cx="16.5" cy="14.5" r="1.1"/>',
            'edit' => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/>',
            'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
            'trash' => '<path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13"/>',
            'trash-2' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
            'pause' => '<path d="M8 5v14M16 5v14"/>',
            'skip' => '<path d="m5 5 9 7-9 7V5zM18 5v14"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
            'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
            'sparkle' => '<path d="M12 4v4M12 16v4M4 12h4M16 12h4M7 7l2 2M15 15l2 2M17 7l-2 2M9 15l-2 2"/>',
            'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'circle-x' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
            'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
            'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
            'heart' => '<path d="M12 20s-7.5-4.6-7.5-10A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7.5 3c0 5.4-7.5 10-7.5 10z"/>',
            'basket' => '<path d="M3 10h18l-2 9H5l-2-9z"/><path d="m8 10 3-6M16 10l-3-6M9 14v2M12 14v2M15 14v2"/>',
            'share' => '<path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><path d="M12 16V4"/><path d="m8 8 4-4 4 4"/>',
            'link' => '<path d="M10 13a5 5 0 0 1 7.1 0l1.4 1.4a5 5 0 0 1 0 7.1 5 5 0 0 1-7.1 0"/><path d="M14 11a5 5 0 0 1-7.1 0l-1.4-1.4a5 5 0 0 1 0-7.1 5 5 0 0 1 7.1 0"/>',
        ];
    }
    $inner = $paths[$name] ?? $paths['info'];
    $cls = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';

    // Intrinsic 20x20 fallback: CSS rules/classes override these attributes, but an icon
    // rendered where no sizing rule applies can never expand to fill its container.
    return '<svg' . $cls . ' width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}

/**
 * Filled brand icons for footer / admin social fields.
 * Usage: echo at_social_icon('instagram', 'w-5 h-5');
 */
function at_social_icon(string $platform, string $class = ''): string
{
    static $paths = null;
    if ($paths === null) {
        $paths = [
            'instagram' => '<path fill="currentColor" d="M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4zm5 4.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11zm6.2-.9a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z"/>',
            'facebook' => '<path fill="currentColor" d="M14 4h3.5V1h-3c-2.8 0-4.5 1.7-4.5 4.6V9H7v3.5h3V23h3.5v-10.5H17l.5-3.5h-3.5V6.2c0-1 .3-1.7 1.7-1.7z"/>',
            'youtube' => '<path fill="currentColor" d="M21.6 7.2a2.5 2.5 0 0 0-1.8-1.8C17.8 5 12 5 12 5s-5.8 0-7.8.4A2.5 2.5 0 0 0 2.4 7.2 26 26 0 0 0 2 12a26 26 0 0 0 .4 4.8 2.5 2.5 0 0 0 1.8 1.8C6.2 19 12 19 12 19s5.8 0 7.8-.4a2.5 2.5 0 0 0 1.8-1.8A26 26 0 0 0 22 12a26 26 0 0 0-.4-4.8zM10 15.5v-7l6 3.5-6 3.5z"/>',
            'whatsapp' => '<path fill="currentColor" d="M12 2a10 10 0 0 0-8.7 14.9L2 22l5.3-1.3A10 10 0 1 0 12 2zm5.2 14.2c-.2.6-1.2 1.1-1.7 1.2-.4.1-.9.2-3.1-.7-2.6-1-4.3-3.4-4.4-3.6-.1-.2-1-1.3-1-2.5s.6-1.8.9-2c.2-.3.5-.4.7-.4h.5c.2 0 .4 0 .6.5.2.5.7 1.7.8 1.8.1.1.1.3 0 .4-.1.2-.2.3-.3.5-.1.1-.2.2-.1.4.1.2.5.8 1.2 1.3.8.7 1.5.9 1.7 1 .2.1.4.1.5-.1.2-.2.6-.7.8-.9.2-.2.3-.2.6-.1.2.1 1.4.7 1.7.8.3.1.5.2.6.3.1.2.1 1-.1 1.6z"/>',
            'x' => '<path fill="currentColor" d="M4 3h4.7l3.3 4.6L16.5 3H20l-5.8 7.5L20.5 21h-4.7l-3.6-5-4.4 5H4l6.2-8.1L4 3zm14.8 16h-2.2L7.4 5h2.2l9.2 14z"/>',
        ];
    }
    $inner = $paths[$platform] ?? $paths['instagram'];
    $cls = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';

    return '<svg' . $cls . ' width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}
