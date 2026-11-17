<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('meta');
$useDb = AdminPersistence::isAvailable();
$metaSectionRaw = $_POST['section'] ?? $_GET['section'] ?? 'overview';
$metaSection = MetaAdsSettings::normalizeSection(is_string($metaSectionRaw) ? $metaSectionRaw : 'overview');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['meta_action']) && is_string($_POST['meta_action'])
        ? $_POST['meta_action']
        : '';
    $redirectTo = MetaAdsSettings::sectionUrl($metaSection);

    if (!$useDb) {
        admin_flash_persistence_blocked('Meta Ads settings saved (demo).');
        header('Location: ' . $redirectTo, true, 302);
        exit;
    }

    switch ($action) {
        case 'save_pixel':
            $result = MetaAdsSettings::savePixelFromPost($_POST);
            break;
        case 'save_capi':
            $result = MetaAdsSettings::saveCapiFromPost($_POST);
            break;
        case 'clear_token':
            $result = MetaAdsSettings::clearAccessToken($_POST);
            break;
        default:
            $result = ['ok' => false, 'message' => 'Unknown Meta settings action.'];
    }

    if (!empty($result['ok'])) {
        if (!empty($result['changed']) && is_array($result['changed'])) {
            AuditLog::write('meta.settings.save', 'settings', null, [
                'action' => $action,
                'keys' => implode(',', $result['changed']),
            ]);
        }
        admin_flash_set((string) ($result['message'] ?? 'Saved.'));
    } else {
        admin_flash_set((string) ($result['message'] ?? 'Could not save Meta Ads settings.'), 'error');
        $_SESSION['meta_form_errors'] = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $_SESSION['meta_form_post'] = [
            'pixel_enabled' => !empty($_POST['pixel_enabled']),
            'pixel_id' => trim((string) ($_POST['pixel_id'] ?? '')),
            'capi_enabled' => !empty($_POST['capi_enabled']),
            'dataset_id' => trim((string) ($_POST['dataset_id'] ?? '')),
            'test_event_code' => trim((string) ($_POST['test_event_code'] ?? '')),
        ];
    }
    header('Location: ' . $redirectTo, true, 302);
    exit;
}

$metaErrors = is_array($_SESSION['meta_form_errors'] ?? null) ? $_SESSION['meta_form_errors'] : [];
$metaPosted = is_array($_SESSION['meta_form_post'] ?? null) ? $_SESSION['meta_form_post'] : [];
unset($_SESSION['meta_form_errors'], $_SESSION['meta_form_post']);

$metaForm = MetaAdsSettings::adminFormState();
if ($metaPosted !== []) {
    $metaForm = array_merge($metaForm, array_intersect_key($metaPosted, $metaForm));
}
$metaStatus = MetaAdsSettings::statusSummary();

admin_layout_start(['pageTitle' => 'Meta Ads', 'activeAdminNav' => 'meta']);
$pageHeading = 'Meta Ads integration';
$pageDescription = 'Connect Veggie Tokri to Meta (Facebook & Instagram) for ads measurement.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
AdminPersistence::renderUnavailableAlert();
?>
<nav class="at-admin-tabs" aria-label="Meta Ads sections">
    <?php foreach (MetaAdsSettings::SECTIONS as $key => $label): ?>
        <a
            href="<?= Security::escape(MetaAdsSettings::sectionUrl($key)) ?>"
            class="<?= $metaSection === $key ? 'is-active' : '' ?> at-focusable"
        ><?= Security::escape($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php
require APNI_TOKRI_ROOT . '/admin/meta/sections/' . $metaSection . '.php';
admin_layout_end();
