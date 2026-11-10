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
    echo '<nav class="at-firebase-tabs" aria-label="Firebase push sections">';
    echo '<div class="at-firebase-tabs__scroll"><ul class="at-firebase-tabs__list">';
    foreach ($items as $item) {
        $isActive = $item['slug'] === $activeSlug;
        echo '<li class="at-firebase-tabs__item"><a class="at-firebase-tabs__tab';
        if ($isActive) {
            echo ' is-active';
        }
        echo '" href="' . Security::escape($item['href']) . '"';
        if ($isActive) {
            echo ' aria-current="page"';
        }
        echo '>' . Security::escape($item['label']) . '</a></li>';
    }
    echo '</ul></div></nav>';
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

/**
 * @param array{id: string, label: string, status: string, detail: string} $item
 */
function firebase_progress_status_badge(array $item): string
{
    $status = (string) ($item['status'] ?? 'pending');
    $map = [
        'done' => ['mod' => 'ok', 'label' => 'Complete'],
        'pending' => ['mod' => 'warn', 'label' => 'Not yet'],
        'manual' => ['mod' => 'neutral', 'label' => 'Manual check'],
        'na' => ['mod' => 'neutral', 'label' => 'N/A'],
    ];
    $cfg = $map[$status] ?? $map['pending'];

    return '<span class="at-status at-status--' . $cfg['mod'] . ' at-firebase-progress__badge">'
        . Security::escape($cfg['label']) . '</span>';
}

/**
 * @param list<array{id: string, label: string, status: string, detail: string}> $items
 */
function firebase_render_progress_list(string $heading, array $items): void
{
    if ($items === []) {
        return;
    }
    echo '<section class="at-firebase-progress__group" aria-labelledby="' . Security::escape('fbprog-' . md5($heading)) . '">';
    echo '<h3 class="at-firebase-progress__heading" id="' . Security::escape('fbprog-' . md5($heading)) . '">'
        . Security::escape($heading) . '</h3>';
    echo '<ul class="at-firebase-progress__list">';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        echo '<li class="at-firebase-progress__row">';
        echo '<span class="at-firebase-progress__label">' . Security::escape((string) ($item['label'] ?? '')) . '</span>';
        echo firebase_progress_status_badge($item);
        $detail = trim((string) ($item['detail'] ?? ''));
        if ($detail !== '') {
            echo '<p class="at-firebase-progress__detail">' . Security::escape($detail) . '</p>';
        }
        echo '</li>';
    }
    echo '</ul></section>';
}

function firebase_field_help(string $text): void
{
    echo '<p class="text-xs at-muted mt-1 at-firebase-field-help">' . Security::escape($text) . '</p>';
}

/**
 * @param array{web: bool, android: bool} $platforms
 * @param array<string, list<array{id: string, label: string, status: string, detail: string}>> $progress
 */
function firebase_render_setup_guide(int $projectId, array $platforms, array $progress, string $adminBase): void
{
    require __DIR__ . '/firebase-setup-guide.php';
}
