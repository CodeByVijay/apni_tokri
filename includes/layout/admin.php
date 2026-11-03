<?php

declare(strict_types=1);

require_once __DIR__ . '/icons.php';

/** @return list<array{href: string, label: string, slug: string, module: string}> */
function admin_nav_items(): array
{
    $base = url_base() . '/admin';
    $items = [
        ['slug' => 'dashboard', 'module' => 'dashboard', 'href' => $base . '/dashboard.php', 'label' => 'Dashboard'],
        ['slug' => 'orders', 'module' => 'orders', 'href' => $base . '/orders/index.php', 'label' => 'Orders'],
        ['slug' => 'invoices', 'module' => 'invoices', 'href' => $base . '/invoices/index.php', 'label' => 'Invoices'],
        ['slug' => 'products', 'module' => 'products', 'href' => $base . '/products/index.php', 'label' => 'Products'],
        ['slug' => 'categories', 'module' => 'categories', 'href' => $base . '/categories/index.php', 'label' => 'Categories'],
        ['slug' => 'inventory', 'module' => 'inventory', 'href' => $base . '/inventory/index.php', 'label' => 'Inventory'],
        ['slug' => 'customers', 'module' => 'customers', 'href' => $base . '/customers/index.php', 'label' => 'Customers'],
        ['slug' => 'subscriptions', 'module' => 'subscriptions', 'href' => $base . '/subscriptions/index.php', 'label' => 'Subscriptions'],
        ['slug' => 'deliveries', 'module' => 'deliveries', 'href' => $base . '/deliveries/index.php', 'label' => 'Deliveries'],
        ['slug' => 'coupons', 'module' => 'coupons', 'href' => $base . '/coupons/index.php', 'label' => 'Coupons'],
        ['slug' => 'offers', 'module' => 'offers', 'href' => $base . '/offers/index.php', 'label' => 'Offers'],
        ['slug' => 'homepage', 'module' => 'homepage', 'href' => $base . '/homepage/index.php', 'label' => 'Homepage'],
        ['slug' => 'faqs', 'module' => 'faqs', 'href' => $base . '/faqs/index.php', 'label' => 'FAQs'],
        ['slug' => 'cms', 'module' => 'cms', 'href' => $base . '/cms/index.php', 'label' => 'CMS pages'],
        ['slug' => 'reviews', 'module' => 'reviews', 'href' => $base . '/reviews/index.php', 'label' => 'Reviews'],
        ['slug' => 'testimonials', 'module' => 'testimonials', 'href' => $base . '/testimonials/index.php', 'label' => 'Testimonials'],
        ['slug' => 'support', 'module' => 'support', 'href' => $base . '/support/index.php', 'label' => 'Support tickets'],
        ['slug' => 'admin_alerts', 'module' => 'support', 'href' => $base . '/notifications/index.php', 'label' => 'Notifications'],
        ['slug' => 'refunds', 'module' => 'refunds', 'href' => $base . '/refunds/index.php', 'label' => 'Refunds'],
        ['slug' => 'payments', 'module' => 'payments', 'href' => $base . '/payments/index.php', 'label' => 'Payments'],
        ['slug' => 'seo', 'module' => 'seo', 'href' => $base . '/seo/index.php', 'label' => 'SEO & redirects'],
        ['slug' => 'marketing', 'module' => 'marketing', 'href' => $base . '/marketing/index.php', 'label' => 'Marketing & conversion'],
        ['slug' => 'reports', 'module' => 'reports', 'href' => $base . '/reports/index.php', 'label' => 'Reports'],
        ['slug' => 'notifications', 'module' => 'notifications', 'href' => $base . '/notifications/delivery-logs.php', 'label' => 'Notification delivery logs'],
        ['slug' => 'settings', 'module' => 'settings', 'href' => $base . '/settings/index.php', 'label' => 'Settings'],
        ['slug' => 'users', 'module' => 'users', 'href' => $base . '/users/index.php', 'label' => 'Admin users'],
        ['slug' => 'roles', 'module' => 'roles', 'href' => $base . '/roles/index.php', 'label' => 'Roles & permissions'],
    ];
    $filtered = [];
    foreach ($items as $item) {
        if (AdminDemoPerms::can($item['module'])) {
            $filtered[] = $item;
        }
    }

    return $filtered;
}

/** @return array<string, array{group: string, icon: string}> */
function admin_nav_meta(): array
{
    return [
        'dashboard' => ['group' => 'Overview', 'icon' => 'home'],
        'orders' => ['group' => 'Sales', 'icon' => 'receipt'],
        'invoices' => ['group' => 'Sales', 'icon' => 'receipt'],
        'payments' => ['group' => 'Sales', 'icon' => 'wallet'],
        'refunds' => ['group' => 'Sales', 'icon' => 'refresh'],
        'subscriptions' => ['group' => 'Sales', 'icon' => 'repeat'],
        'deliveries' => ['group' => 'Sales', 'icon' => 'truck'],
        'products' => ['group' => 'Catalogue', 'icon' => 'basket'],
        'categories' => ['group' => 'Catalogue', 'icon' => 'grid'],
        'inventory' => ['group' => 'Catalogue', 'icon' => 'box'],
        'customers' => ['group' => 'People', 'icon' => 'users'],
        'reviews' => ['group' => 'People', 'icon' => 'star'],
        'testimonials' => ['group' => 'People', 'icon' => 'heart'],
        'support' => ['group' => 'People', 'icon' => 'chat'],
        'admin_alerts' => ['group' => 'People', 'icon' => 'bell'],
        'coupons' => ['group' => 'Marketing', 'icon' => 'tag'],
        'offers' => ['group' => 'Marketing', 'icon' => 'percent'],
        'marketing' => ['group' => 'Marketing', 'icon' => 'sparkle'],
        'seo' => ['group' => 'Marketing', 'icon' => 'search'],
        'homepage' => ['group' => 'Content', 'icon' => 'image'],
        'faqs' => ['group' => 'Content', 'icon' => 'help'],
        'cms' => ['group' => 'Content', 'icon' => 'file'],
        'reports' => ['group' => 'System', 'icon' => 'chart'],
        'notifications' => ['group' => 'System', 'icon' => 'bell'],
        'settings' => ['group' => 'System', 'icon' => 'settings'],
        'users' => ['group' => 'System', 'icon' => 'user'],
        'roles' => ['group' => 'System', 'icon' => 'lock'],
    ];
}

/**
 * @param array<string, mixed> $vars pageTitle, activeAdminNav
 */
function layout_admin_start(array $vars): void
{
    if (empty($vars['pageTitle']) || !is_string($vars['pageTitle'])) {
        throw new InvalidArgumentException('pageTitle is required');
    }
    $activeAdminNav = $vars['activeAdminNav'] ?? 'dashboard';
    $pageTitle = $vars['pageTitle'];
    $base = url_base();
    $adminUser = AdminAuth::user();
    $meta = admin_nav_meta();
    $items = admin_nav_items();
    $groupOrder = array_flip(['Overview', 'Sales', 'Catalogue', 'People', 'Marketing', 'Content', 'System', 'More']);
    $position = array_flip(array_column($items, 'slug'));
    usort($items, static function (array $a, array $b) use ($meta, $groupOrder, $position): int {
        $ga = $groupOrder[$meta[$a['slug']]['group'] ?? 'More'] ?? 99;
        $gb = $groupOrder[$meta[$b['slug']]['group'] ?? 'More'] ?? 99;

        return $ga === $gb ? $position[$a['slug']] <=> $position[$b['slug']] : $ga <=> $gb;
    });

    require APNI_TOKRI_ROOT . '/includes/layout/head-admin.php';
    echo '<div class="at-admin-layout">';
    echo '<aside class="at-admin-sidebar" aria-label="Admin navigation">';
    echo '<a href="' . Security::escape($base) . '/admin/dashboard.php" class="at-admin-sidebar__brand">';
    echo '<img src="' . Security::escape(asset_url('assets/images/logo.svg')) . '" alt="" width="38" height="38" class="rounded-lg">';
    echo '<span><strong>Apni Tokri</strong><small>Admin</small></span></a>';
    echo '<nav class="flex-1 pb-4">';
    $lastGroup = '';
    foreach ($items as $item) {
        $m = $meta[$item['slug']] ?? ['group' => 'More', 'icon' => 'grid'];
        if ($m['group'] !== $lastGroup) {
            echo '<p class="at-admin-sidebar__group">' . Security::escape($m['group']) . '</p>';
            $lastGroup = $m['group'];
        }
        $active = $item['slug'] === $activeAdminNav ? ' is-active' : '';
        echo '<a class="at-focusable' . $active . '"' . ($active !== '' ? ' aria-current="page"' : '') . ' href="' . Security::escape($item['href']) . '">' . at_icon($m['icon']) . '<span>' . Security::escape($item['label']) . '</span></a>';
    }
    echo '</nav>';
    echo '<div class="px-5 py-4 border-t border-white/10 text-xs text-white/60">';
    echo $adminUser ? Security::escape($adminUser['name']) : 'Admin';
    echo '</div></aside>';

    echo '<div class="at-admin-main">';
    echo '<header class="at-admin-topbar">';
    echo '<div class="flex items-center gap-3 min-w-0 flex-1">';
    echo '<button type="button" id="at-admin-menu-toggle" class="at-iconbtn at-focusable lg:hidden" aria-expanded="false" aria-controls="at-admin-mobile-nav" aria-label="Toggle admin menu">' . at_icon('menu') . '</button>';
    echo '<h1 class="text-lg font-bold truncate">' . Security::escape($pageTitle) . '</h1>';
    echo '</div>';
    if ($adminUser) {
        echo '<span class="hidden md:inline text-sm at-muted">' . Security::escape($adminUser['email']) . '</span>';
    }
    echo '<div class="flex items-center gap-2 flex-shrink-0 overflow-visible">';
    if (AdminAuth::check() && AdminDemoPerms::can('support') && AdminNotificationService::tableReady()) {
        $bellUnread = AdminNotificationService::unreadCountForCurrentAdmin();
        $inboxUrl = url_base() . '/admin/notifications/index.php';
        echo '<div class="relative overflow-visible" id="at-admin-notifications" data-poll-url="' . Security::escape(url_base() . '/admin/ajax/notifications.php') . '" data-csrf="' . Security::escape(Csrf::token()) . '" data-inbox-url="' . Security::escape($inboxUrl) . '">';
        echo '<button type="button" id="at-admin-notifications-toggle" class="at-iconbtn at-focusable relative" aria-expanded="false" aria-controls="at-admin-notifications-panel" aria-haspopup="true" aria-label="Notifications">';
        echo at_icon('bell');
        echo '<span id="at-admin-notifications-badge" class="absolute -top-1 -right-1 min-w-[1.1rem] h-[1.1rem] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-[1.1rem] text-center' . ($bellUnread > 0 ? '' : ' hidden') . '">' . ($bellUnread > 99 ? '99+' : (string) $bellUnread) . '</span>';
        echo '</button>';
        echo '<div id="at-admin-notifications-panel" class="at-admin-notifications-panel" role="dialog" aria-label="Recent notifications">';
        echo '<div class="at-admin-notifications-panel__head">';
        echo '<p class="text-sm font-bold">Notifications</p>';
        echo '<p class="text-xs at-muted">Support alerts</p>';
        echo '</div>';
        echo '<div class="at-admin-notifications-panel__list" id="at-admin-notifications-list"><p class="text-sm at-muted p-4">Loading…</p></div>';
        echo '<div class="at-admin-notifications-panel__foot">';
        echo '<button type="button" id="at-admin-notifications-mark-all" class="at-btn at-btn--ghost at-btn--sm at-focusable">Mark all read</button>';
        echo '<a href="' . Security::escape($inboxUrl) . '" class="at-btn at-btn--secondary at-btn--sm at-focusable">View all</a>';
        echo '</div></div></div>';
    }
    echo '<a href="' . Security::escape($base) . '/index.php" class="at-btn at-btn--ghost at-btn--sm at-focusable hidden sm:inline-flex">' . at_icon('store') . 'View site</a>';
    if (AdminAuth::check()) {
        echo '<a href="' . Security::escape($base) . '/admin/logout.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Sign out</a>';
    } else {
        echo '<a href="' . Security::escape($base) . '/admin/login.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Sign in</a>';
    }
    echo '</div></header>';

    echo '<nav id="at-admin-mobile-nav" class="at-admin-mobile-nav hidden" aria-label="Admin sections">';
    foreach ($items as $item) {
        $active = $item['slug'] === $activeAdminNav ? ' is-active' : '';
        echo '<a class="at-chip at-focusable whitespace-nowrap' . $active . '" href="' . Security::escape($item['href']) . '">' . Security::escape($item['label']) . '</a>';
    }
    echo '</nav>';

    echo '<div class="at-admin-content">';
}

function layout_admin_end(): void
{
    echo '</div></div></div>';
    echo '<script src="' . Security::escape(asset_url('assets/js/apni-tokri.js')) . '"></script>';
    echo '<script src="' . Security::escape(asset_url('assets/js/admin.js')) . '"></script>';
    echo '</body></html>';
}
