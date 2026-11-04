<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('cms');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$cmsPage = CmsPageService::pageForAdminPreview($id);

if ($cmsPage === null) {
    http_response_code(404);
    admin_layout_start(['pageTitle' => 'Preview unavailable', 'activeAdminNav' => 'cms']);
    echo '<div class="at-alert at-alert--error">Preview is not available for this page.</div>';
    admin_layout_end();
    exit;
}

header('X-Robots-Tag: noindex, nofollow', true);

admin_layout_start(['pageTitle' => 'Preview: ' . (string) ($cmsPage['title'] ?? 'Page'), 'activeAdminNav' => 'cms']);
?>
<div class="at-alert at-alert--info mb-4">
    Admin preview — <?= Security::escape((string) ($cmsPage['status'] ?? '')) ?>
    · <?= Security::escape((string) ($cmsPage['visibility'] ?? '')) ?>.
    Draft and private content is not visible on the public site.
</div>
<div class="at-card max-w-3xl">
    <div class="at-card__body">
        <h1 class="at-h2 mb-4"><?= Security::escape((string) ($cmsPage['title'] ?? '')) ?></h1>
        <div class="cms-body">
            <?= (string) ($cmsPage['body_html'] ?? '') ?>
        </div>
    </div>
</div>
<p class="mt-4 text-sm">
    <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/admin/cms/edit.php?id=<?= (int) $id ?>">Back to edit</a>
</p>
<?php admin_layout_end();
