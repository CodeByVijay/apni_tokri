<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('seo');
$useDb = AdminPersistence::isAvailable();
$sectionRaw = $_POST['section'] ?? $_GET['section'] ?? 'dashboard';
$seoSection = SeoAdminCenter::normalizeSection(is_string($sectionRaw) ? $sectionRaw : 'dashboard');
$redirectAfter = $seoSection === 'catalog'
    ? SeoAdminCenter::catalogSectionUrl(SeoAdminCenter::catalogParamsFromRequest())
    : SeoAdminCenter::sectionUrl($seoSection);
if ($seoSection !== 'catalog' && (isset($_GET['page']) || isset($_GET['q']))) {
    $redirectAfter .= '&' . http_build_query(array_filter([
        'page' => isset($_GET['page']) ? (int) $_GET['page'] : null,
        'q' => isset($_GET['q']) && is_string($_GET['q']) && $_GET['q'] !== '' ? $_GET['q'] : null,
    ]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['seo_action']) && is_string($_POST['seo_action']) ? $_POST['seo_action'] : '';
    if ($action === '' && isset($_POST['action']) && is_string($_POST['action'])) {
        $legacy = $_POST['action'];
        $action = $legacy === 'delete' ? 'redirect_delete' : ($legacy === 'save' ? 'redirect_save' : $legacy);
    }

    if ($action === 'set_ui_mode') {
        $mode = isset($_POST['ui_mode']) && is_string($_POST['ui_mode']) ? $_POST['ui_mode'] : SeoAdminCenter::MODE_SIMPLE;
        SeoAdminCenter::setUiMode($mode);
        admin_flash_set($mode === SeoAdminCenter::MODE_ADVANCED ? 'Advanced mode enabled.' : 'Simple mode enabled.');
        header('Location: ' . $redirectAfter, true, 302);
        exit;
    }

    if (!$useDb && $action !== 'set_ui_mode') {
        admin_flash_persistence_blocked('Change saved (demo).');
        header('Location: ' . $redirectAfter, true, 302);
        exit;
    }

    switch ($action) {
        case 'redirect_delete':
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $result = SeoRedirectService::deleteById($id);
            if ($result['ok']) {
                AuditLog::write('seo.redirect.delete', 'seo_redirect', $id, []);
                admin_flash_set('Redirect removed.');
            } else {
                admin_flash_set((string) ($result['message'] ?? 'Could not delete redirect.'), 'error');
            }
            break;
        case 'redirect_save':
            $from = isset($_POST['from']) && is_string($_POST['from']) ? trim($_POST['from']) : '';
            $to = isset($_POST['to']) && is_string($_POST['to']) ? trim($_POST['to']) : '';
            $result = SeoRedirectService::registerPermanent($from, $to);
            if ($result['ok']) {
                AuditLog::write('seo.redirect.save', 'seo_redirect', null, ['from' => $from, 'to' => $to]);
                admin_flash_set('301 redirect saved.');
            } else {
                admin_flash_set((string) ($result['message'] ?? 'Could not save redirect.'), 'error');
            }
            break;
        case 'save_homepage':
            $result = SeoAdminCenter::saveHomepageFromPost($_POST);
            admin_flash_set((string) ($result['message'] ?? 'Saved.'), !empty($result['ok']) ? 'success' : 'error');
            break;
        case 'save_google':
            $result = SeoAdminCenter::saveGoogleFromPost($_POST);
            admin_flash_set((string) ($result['message'] ?? 'Saved.'), !empty($result['ok']) ? 'success' : 'error');
            break;
        case 'save_robots_extra':
            $raw = isset($_POST['robots_extra']) && is_string($_POST['robots_extra']) ? $_POST['robots_extra'] : '';
            $result = SeoAdminCenter::persistRobotsExtra($raw);
            if (!empty($result['ok'])) {
                SeoAdminCenter::markWizardStep('sitemap', true);
            }
            admin_flash_set((string) ($result['message'] ?? 'Saved.'), !empty($result['ok']) ? 'success' : 'error');
            break;
        case 'save_templates':
            $result = SeoAdminCenter::saveTemplatesFromPost($_POST);
            admin_flash_set((string) ($result['message'] ?? 'Saved.'), !empty($result['ok']) ? 'success' : 'error');
            break;
        case 'save_product_seo':
            $result = SeoAdminCenter::saveProductSeoFromPost($_POST);
            admin_flash_set((string) ($result['message'] ?? 'Saved.'), !empty($result['ok']) ? 'success' : 'error');
            if (!empty($result['ok']) && isset($_POST['product_id'])) {
                $p = SeoAdminCenter::catalogParamsFromRequest();
                $p['product_id'] = (int) $_POST['product_id'];
                $redirectAfter = SeoAdminCenter::catalogSectionUrl($p);
            }
            break;
        case 'apply_product_template':
            $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
            $onlyBlanks = !empty($_POST['only_fill_blanks']);
            $confirm = !empty($_POST['confirm_overwrite']);
            $result = SeoAdminCenter::applyTemplateToProduct($pid, $onlyBlanks, $confirm);
            admin_flash_set((string) ($result['message'] ?? 'Done.'), !empty($result['ok']) ? 'success' : 'error');
            if ($pid > 0) {
                $p = SeoAdminCenter::catalogParamsFromRequest();
                $p['product_id'] = $pid;
                $redirectAfter = SeoAdminCenter::catalogSectionUrl($p);
            }
            break;
        case 'bulk_apply_products':
            $ids = [];
            if (isset($_POST['product_ids_csv']) && is_string($_POST['product_ids_csv'])) {
                foreach (preg_split('/[\s,]+/', $_POST['product_ids_csv']) as $part) {
                    $part = (int) trim($part);
                    if ($part > 0) {
                        $ids[] = $part;
                    }
                }
            }
            if (isset($_POST['product_ids']) && is_array($_POST['product_ids'])) {
                foreach ($_POST['product_ids'] as $id) {
                    $ids[] = (int) $id;
                }
            }
            $ids = array_values(array_unique($ids));
            $onlyBlanks = !empty($_POST['only_fill_blanks']);
            $result = SeoAdminCenter::applyProductTemplateToIds($ids, $onlyBlanks);
            admin_flash_set(
                !empty($result['ok'])
                    ? ((int) ($result['updated'] ?? 0) . ' product(s) updated.')
                    : (string) ($result['message'] ?? 'No products updated.'),
                !empty($result['ok']) ? 'success' : 'error'
            );
            break;
        case 'save_local':
            $result = SeoAdminCenter::saveLocalFromPost($_POST);
            admin_flash_set((string) ($result['message'] ?? 'Saved.'), !empty($result['ok']) ? 'success' : 'error');
            break;
        case 'wizard_mark':
            $step = isset($_POST['wizard_step']) && is_string($_POST['wizard_step']) ? $_POST['wizard_step'] : '';
            if ($step !== '') {
                SeoAdminCenter::markWizardStep($step, !empty($_POST['wizard_done']));
            }
            admin_flash_set('Setup progress updated.');
            break;
        case 'nf_ignore':
            $nfId = isset($_POST['nf_id']) ? (int) $_POST['nf_id'] : 0;
            if (SeoNotFoundLog::ignore($nfId)) {
                admin_flash_set('404 entry ignored.');
            } else {
                admin_flash_set('Could not ignore entry.', 'error');
            }
            break;
        default:
            admin_flash_set('Unknown action.', 'error');
    }
    header('Location: ' . $redirectAfter, true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'SEO Management Center', 'activeAdminNav' => 'seo']);
$pageHeading = 'SEO Management Center';
$pageDescription = 'Manage search titles, technical settings and redirects in one place — no code required.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
AdminPersistence::renderUnavailableAlert();
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/seo-ui.php';
echo '<div class="at-seo-saas">';
require APNI_TOKRI_ROOT . '/includes/partials/admin/seo-nav.php';
require APNI_TOKRI_ROOT . '/includes/partials/admin/seo-section-intro.php';

$sectionFile = APNI_TOKRI_ROOT . '/admin/seo/sections/' . $seoSection . '.php';
echo '<div class="at-seo-saas__content">';
if (is_file($sectionFile)) {
    require $sectionFile;
} else {
    echo '<p class="at-seo-saas__empty">Section not found.</p>';
}
echo '</div></div>';
admin_layout_end();
