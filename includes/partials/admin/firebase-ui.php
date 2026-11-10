<?php

declare(strict_types=1);

/**
 * Shared Firebase admin UI helpers (markup only).
 */

function firebase_admin_subnav(string $activeSlug): void
{
    $base = url_base();
    $items = [
        ['slug' => 'projects', 'href' => $base . '/admin/firebase/index.php', 'label' => 'Projects'],
        ['slug' => 'devices', 'href' => $base . '/admin/push/devices.php', 'label' => 'Devices'],
        ['slug' => 'templates', 'href' => $base . '/admin/push/templates.php', 'label' => 'Templates'],
        ['slug' => 'logs', 'href' => $base . '/admin/push/logs.php', 'label' => 'Logs'],
        ['slug' => 'send', 'href' => $base . '/admin/push/send.php', 'label' => 'Send'],
        ['slug' => 'settings', 'href' => $base . '/admin/push/settings.php', 'label' => 'Settings'],
    ];
    echo '<nav class="at-firebase-subnav mb-4" aria-label="Firebase push sections"><ul class="at-firebase-subnav__list">';
    foreach ($items as $item) {
        $isActive = $item['slug'] === $activeSlug;
        echo '<li><a class="at-firebase-subnav__link' . ($isActive ? ' is-active' : '') . '" href="'
            . Security::escape($item['href']) . '"' . ($isActive ? ' aria-current="page"' : '') . '>'
            . Security::escape($item['label']) . '</a></li>';
    }
    echo '</ul></nav>';
}

function firebase_platform_badge(string $platform): string
{
    $p = strtolower(trim($platform));
    $label = $p === 'android' ? 'Android' : ($p === 'web' ? 'Web' : ucfirst($p));
    $class = $p === 'android' ? 'at-pill at-pill--android' : 'at-pill at-pill--web';

    return '<span class="' . $class . '">' . Security::escape($label) . '</span>';
}

function firebase_target_badge(string $targetType, string $tokenPrefix = ''): string
{
    $t = strtolower(trim($targetType));
    $label = $t === 'fid' ? 'FID' : 'FCM token';
    $class = $t === 'fid' ? 'at-pill at-pill--fid' : 'at-pill at-pill--token';
    $html = '<span class="' . $class . '">' . Security::escape($label) . '</span>';
    if ($tokenPrefix !== '') {
        $html .= ' <code class="at-firebase-token-prefix" title="Token prefix only">' . Security::escape($tokenPrefix) . '…</code>';
    }

    return $html;
}

function firebase_permission_badge(string $permission): string
{
    $p = strtolower(trim($permission));
    $map = ['granted' => 'ok', 'denied' => 'bad', 'default' => 'warn', 'unknown' => 'neutral'];
    $mod = $map[$p] ?? 'neutral';

    return '<span class="at-status at-status--' . $mod . '">' . Security::escape(ucfirst($p !== '' ? $p : 'unknown')) . '</span>';
}

/**
 * @param array<string, scalar|null> $queryBase
 */
function firebase_admin_list_toolbar(
    string $formAction,
    string $searchValue,
    array $queryBase,
    int $perPage,
    ?array $extraFields = null
): void {
    $base = array_filter($queryBase, static fn ($v) => $v !== null && $v !== '');
    ?>
    <form method="get" action="<?= Security::escape($formAction) ?>" class="at-admin-card mb-4 at-firebase-toolbar">
        <div class="at-admin-card__body">
            <div class="at-firebase-toolbar__grid">
                <div class="at-firebase-toolbar__search">
                    <label class="at-label" for="firebase-list-q">Search</label>
                    <input id="firebase-list-q" name="q" class="at-input at-focusable w-full" value="<?= Security::escape($searchValue) ?>"
                           placeholder="Search this list…" autocomplete="off">
                </div>
                <?php if (is_array($extraFields)) {
                    foreach ($extraFields as $field) {
                        if (!is_array($field)) {
                            continue;
                        }
                        $name = (string) ($field['name'] ?? '');
                        $label = (string) ($field['label'] ?? $name);
                        $options = $field['options'] ?? [];
                        $value = (string) ($field['value'] ?? '');
                        if ($name === '') {
                            continue;
                        }
                        echo '<div><label class="at-label" for="firebase-f-' . Security::escape($name) . '">' . Security::escape($label) . '</label>';
                        echo '<select id="firebase-f-' . Security::escape($name) . '" name="' . Security::escape($name) . '" class="at-select at-focusable w-full">';
                        foreach ($options as $optVal => $optLabel) {
                            $sel = (string) $optVal === $value ? ' selected' : '';
                            echo '<option value="' . Security::escape((string) $optVal) . '"' . $sel . '>' . Security::escape((string) $optLabel) . '</option>';
                        }
                        echo '</select></div>';
                    }
                } ?>
                <div class="at-firebase-toolbar__actions">
                    <input type="hidden" name="page" value="1">
                    <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
                    <button type="submit" class="at-btn at-btn--secondary at-focusable">Apply</button>
                    <?php
                    $clearQs = http_build_query(array_filter(['per_page' => $perPage !== 25 ? $perPage : null]));
                    ?>
                    <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($formAction . ($clearQs !== '' ? '?' . $clearQs : '')) ?>">Clear</a>
                </div>
            </div>
        </div>
    </form>
    <?php
}

function firebase_admin_empty_state(string $title, string $detail, ?string $actionHref = null, ?string $actionLabel = null): void
{
    ?>
    <div class="at-firebase-empty">
        <p class="at-firebase-empty__title"><?= Security::escape($title) ?></p>
        <p class="at-firebase-empty__detail"><?= Security::escape($detail) ?></p>
        <?php if ($actionHref !== null && $actionLabel !== null): ?>
            <a class="at-btn at-btn--primary at-focusable mt-3" href="<?= Security::escape($actionHref) ?>"><?= Security::escape($actionLabel) ?></a>
        <?php endif; ?>
    </div>
    <?php
}

function firebase_form_section_start(string $title, string $description = ''): void
{
    echo '<section class="at-firebase-form__section">';
    echo '<header class="at-firebase-form__section-head"><h2 class="at-firebase-form__section-title">' . Security::escape($title) . '</h2>';
    if ($description !== '') {
        echo '<p class="at-firebase-form__section-desc">' . Security::escape($description) . '</p>';
    }
    echo '</header><div class="at-firebase-form__section-body">';
}

function firebase_form_section_end(): void
{
    echo '</div></section>';
}

function firebase_label_required(string $text): string
{
    return Security::escape($text) . ' <span class="at-label__required" aria-hidden="true">*</span>';
}

function firebase_label_optional(string $text): string
{
    return Security::escape($text) . ' <span class="at-label__optional">(optional)</span>';
}
