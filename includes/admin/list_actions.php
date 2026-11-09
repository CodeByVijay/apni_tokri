<?php

declare(strict_types=1);

/**
 * Shared admin row actions (Edit + Delete) and POST delete handler.
 * All deletes: POST + CSRF + permission check + confirm() in browser.
 */

if (!function_exists('at_icon')) {
    require_once APNI_TOKRI_ROOT . '/includes/layout/icons.php';
}

/** Compact icon-only edit control for admin tables. */
function admin_action_edit_link(string $href, string $label = 'Edit'): string
{
    if ($href === '') {
        return '';
    }

    return '<a class="at-admin-actions__btn at-admin-actions__btn--edit at-focusable" href="'
        . Security::escape($href) . '" title="' . Security::escape($label) . '" aria-label="'
        . Security::escape($label) . '">' . at_icon('pencil', 'at-admin-actions__icon') . '</a>';
}

/** @return array{ok: bool, message: string, archived?: bool} */
function admin_delete_entity(string $entity, int $id): array
{
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Invalid record.'];
    }
    switch ($entity) {
        case 'cms_page':
            return CmsPageService::deleteForAdmin($id);
        case 'category':
            return CategoryRepository::deleteForAdmin($id);
        case 'product':
            return ProductRepository::deleteOrArchiveForAdmin($id);
        case 'coupon':
            return CouponRepository::deleteForAdmin($id);
        case 'offer':
            return OfferRepository::deleteForAdmin($id);
        case 'faq':
            $r = FaqService::deleteFaq($id);

            return ['ok' => !empty($r['ok']), 'message' => (string) ($r['message'] ?? 'Could not delete FAQ.')];
        case 'review':
            return ReviewService::deleteForAdmin($id);
        case 'testimonial':
            return TestimonialService::deleteForAdmin($id);
        case 'delivery_slot':
            return DeliverySlotAdminService::deleteForAdmin($id);
        case 'subscription_plan':
            return SubscriptionPlanRepository::deleteForAdmin($id);
        case 'subscription_menu_template':
            return SubscriptionMenuRepository::deleteForAdmin($id);
        default:
            return ['ok' => false, 'message' => 'Unknown delete target.'];
    }
}

/** Hidden fields to preserve list filters/pagination on delete POST. */
function admin_list_delete_hiddens(int $page, int $perPage, string $search = ''): string
{
    $out = '<input type="hidden" name="list_page" value="' . max(1, $page) . '">'
        . '<input type="hidden" name="list_per_page" value="' . max(1, min(100, $perPage)) . '">';
    if ($search !== '') {
        $out .= '<input type="hidden" name="list_q" value="' . Security::escape($search) . '">';
    }

    return $out;
}

function admin_delete_redirect_url(string $redirectBase, array $post): string
{
    $page = max(1, (int) ($post['list_page'] ?? 1));
    $perPage = max(1, min(100, (int) ($post['list_per_page'] ?? 25)));
    $q = trim((string) ($post['list_q'] ?? ''));
    $entity = (string) ($post['entity'] ?? '');
    if (!empty($post['admin_delete_ok']) && in_array($entity, ['subscription_plan', 'subscription_menu_template'], true)) {
        $remaining = $entity === 'subscription_plan'
            ? SubscriptionPlanRepository::countForAdmin($q)
            : SubscriptionMenuRepository::countForAdmin($q);
        $totalPages = max(1, (int) ceil($remaining / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
    }
    $qs = array_filter([
        'page' => $page > 1 ? $page : null,
        'per_page' => $perPage !== 25 ? $perPage : null,
        'q' => $q !== '' ? $q : null,
    ]);

    return $redirectBase . ($qs !== [] ? '?' . http_build_query($qs) : '');
}

/**
 * Process a listing-page delete POST. Returns true when a delete POST was handled (redirect issued).
 */
function admin_process_list_delete(string $permissionModule, string $redirectUrl): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['admin_delete'] ?? '') !== '1') {
        return false;
    }
    if (!Install::isSchemaReady() || !AdminAuth::check()) {
        admin_flash_set('Database admin session required.', 'error');
        header('Location: ' . $redirectUrl, true, 302);
        exit;
    }
    AdminDemoPerms::denyIfCannot($permissionModule);
    Csrf::requireValid();
    $entity = (string) ($_POST['entity'] ?? '');
    $id = (int) ($_POST['record_id'] ?? 0);
    $_POST['admin_delete_ok'] = '0';
    $result = admin_delete_entity($entity, $id);
    if (!empty($result['ok'])) {
        $_POST['admin_delete_ok'] = '1';
    }
    if (!empty($result['ok'])) {
        $type = !empty($result['archived']) ? 'warning' : 'success';
    } else {
        $type = 'error';
    }
    admin_flash_set((string) ($result['message'] ?? ($result['ok'] ? 'Deleted.' : 'Could not delete.')), $type);
    header('Location: ' . admin_delete_redirect_url($redirectUrl, $_POST), true, 302);
    exit;
}

/** Edit + Delete icon buttons for data-table actions column. */
function admin_table_actions(
    string $editHref,
    string $editLabel,
    string $entity,
    int $recordId,
    string $recordLabel,
    int $listPage = 1,
    int $listPerPage = 25,
    string $listSearch = ''
): string {
    $edit = admin_action_edit_link($editHref, $editLabel);
    $form = admin_delete_form($entity, $recordId, $recordLabel, $listPage, $listPerPage, $listSearch);

    return '<span class="at-admin-actions">' . $edit . $form . '</span>';
}

function admin_delete_form(
    string $entity,
    int $recordId,
    string $recordLabel,
    int $listPage = 1,
    int $listPerPage = 25,
    string $listSearch = ''
): string {
    if ($recordId <= 0) {
        return '';
    }
    $csrf = Csrf::field();
    $deleteTitle = $entity === 'product' ? 'Delete product' : 'Delete';
    if ($entity === 'product') {
        return '<form method="post" class="at-admin-actions__form" data-at-product-delete-form data-product-name="'
            . Security::escape($recordLabel) . '">'
            . $csrf
            . admin_list_delete_hiddens($listPage, $listPerPage, $listSearch)
            . '<input type="hidden" name="admin_delete" value="1">'
            . '<input type="hidden" name="entity" value="' . Security::escape($entity) . '">'
            . '<input type="hidden" name="record_id" value="' . (int) $recordId . '">'
            . '<button type="button" class="at-admin-actions__btn at-admin-actions__btn--delete at-focusable" data-at-product-delete-trigger'
            . ' title="' . Security::escape($deleteTitle) . '" aria-label="' . Security::escape($deleteTitle) . '">'
            . at_icon('trash-2', 'at-admin-actions__icon') . '</button>'
            . '</form>';
    }
    $confirm = 'Delete “' . str_replace(['"', "\r", "\n"], '', $recordLabel) . '”? This cannot be undone.';

    return '<form method="post" class="at-admin-actions__form" onsubmit="return confirm(' . json_encode($confirm, JSON_UNESCAPED_UNICODE) . ');">'
        . $csrf
        . admin_list_delete_hiddens($listPage, $listPerPage, $listSearch)
        . '<input type="hidden" name="admin_delete" value="1">'
        . '<input type="hidden" name="entity" value="' . Security::escape($entity) . '">'
        . '<input type="hidden" name="record_id" value="' . (int) $recordId . '">'
        . '<button type="submit" class="at-admin-actions__btn at-admin-actions__btn--delete at-focusable" title="' . Security::escape($deleteTitle) . '" aria-label="' . Security::escape($deleteTitle) . '">'
        . at_icon('trash-2', 'at-admin-actions__icon') . '</button>'
        . '</form>';
}

/** Icon delete button markup for custom POST forms (same styling, caller supplies CSRF + fields). */
function admin_icon_delete_button(string $label = 'Delete', ?string $name = null, ?string $value = null): string
{
    $nameAttr = $name !== null && $name !== '' ? ' name="' . Security::escape($name) . '"' : '';
    $valAttr = $value !== null && $value !== '' ? ' value="' . Security::escape($value) . '"' : '';

    return '<button type="submit"' . $nameAttr . $valAttr . ' class="at-admin-actions__btn at-admin-actions__btn--delete at-focusable" title="'
        . Security::escape($label) . '" aria-label="' . Security::escape($label) . '">'
        . at_icon('trash-2', 'at-admin-actions__icon') . '</button>';
}
