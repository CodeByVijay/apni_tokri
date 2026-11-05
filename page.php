<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? trim($_GET['slug']) : '';

if ($slug === '' || !Validation::slug($slug)) {
    http_response_code(404);
    layout_public_start(['seo' => SeoResolver::notFound(), 'activeNav' => '']);
    $emptyTitle = 'Page not found';
    $emptyMessage = 'This page is not available.';
    $emptyIcon = 'file';
    $emptyActionHref = url_base() . '/index.php';
    $emptyActionLabel = 'Home';
    echo '<div class="at-container py-10">';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    echo '</div>';
    layout_public_end();
    exit;
}

if (!CmsPageService::schemaReady()) {
    http_response_code(404);
    layout_public_start(['seo' => SeoResolver::notFound(), 'activeNav' => '']);
    $emptyTitle = 'Page not found';
    $emptyMessage = 'This page is not available yet.';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$row = CmsPageRepository::findBySlug($slug);
if ($row === null) {
    http_response_code(404);
    layout_public_start(['seo' => SeoResolver::notFound(), 'activeNav' => '']);
    $emptyTitle = 'Page not found';
    $emptyMessage = 'No page exists for this address.';
    $emptyIcon = 'file';
    $emptyActionHref = url_base() . '/index.php';
    $emptyActionLabel = 'Home';
    echo '<div class="at-container py-10">';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    echo '</div>';
    layout_public_end();
    exit;
}

$status = (string) ($row['status'] ?? '');
$visibility = (string) ($row['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC);

if ($status !== PublishStatus::PUBLISHED) {
    http_response_code(404);
    layout_public_start(['seo' => SeoResolver::notFound(), 'activeNav' => '']);
    $emptyTitle = 'Page not published';
    $emptyMessage = 'This page exists but is not published yet. Check back later or contact the store.';
    echo '<div class="at-container py-10">';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    echo '</div>';
    layout_public_end();
    exit;
}

if ($visibility === CmsPageService::VISIBILITY_PRIVATE && !CmsPageService::viewerAuthorizedForPrivate()) {
    http_response_code(403);
    layout_public_start(['seo' => SeoResolver::accessDenied(), 'activeNav' => '']);
    $emptyTitle = 'Access denied';
    $emptyMessage = 'This page is restricted to authorized staff.';
    $emptyActionHref = url_base() . '/index.php';
    $emptyActionLabel = 'Home';
    echo '<div class="at-container py-10">';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    echo '</div>';
    layout_public_end();
    exit;
}

$cmsPage = CmsPageService::publishedViewableBySlug($slug);
if ($cmsPage === null) {
    http_response_code(404);
    layout_public_start(['seo' => SeoResolver::notFound(), 'activeNav' => '']);
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$seoCms = SeoResolver::forCmsPage($cmsPage);
layout_public_start([
    'seo' => $seoCms,
    'jsonLd' => SeoStructuredData::forCmsPage($cmsPage, $seoCms),
    'activeNav' => '',
]);
require APNI_TOKRI_ROOT . '/includes/partials/cms-page-body.php';
layout_public_end();
