<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/catalogue/helpers.php';
require_once __DIR__ . '/includes/stage13_phase8_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

stage13_p8_cleanup($pdo);

$html = stage13_p8_render_home_trust();
ok(str_contains($html, 'at-trust') && str_contains($html, 'Fee shown upfront'), 'Default homepage sections render correctly');

AppSettings::set(HomepageTrustService::SETTING_KEY, json_encode([
    'version' => 1,
    'sections' => array_map(static function (array $s): array {
        if (($s['id'] ?? '') === 'why_features') {
            $s['heading'] = 'AT-HP8 Custom Why Heading';
        }

        return $s;
    }, HomepageTrustService::defaultConfig()['sections']),
], JSON_UNESCAPED_UNICODE));
$html2 = stage13_p8_render_home_trust();
ok(str_contains($html2, 'AT-HP8 Custom Why Heading'), 'Enabled section renders');

AppSettings::set(HomepageTrustService::SETTING_KEY, json_encode([
    'version' => 1,
    'sections' => array_map(static function (array $s): array {
        if (($s['id'] ?? '') === 'why_features') {
            $s['enabled'] = false;
        }

        return $s;
    }, HomepageTrustService::defaultConfig()['sections']),
], JSON_UNESCAPED_UNICODE));
ok(!str_contains(stage13_p8_render_home_trust(), 'AT-HP8 Custom Why Heading') && !str_contains(stage13_p8_render_home_trust(), 'Why Veggie Tokri'), 'Disabled section does not render');

stage13_p8_cleanup($pdo);
AppSettings::set(HomepageTrustService::SETTING_KEY, json_encode([
    'version' => 1,
    'sections' => array_map(static function (array $s): array {
        if (($s['id'] ?? '') === 'trust_strip') {
            $s['enabled'] = true;
            $s['items'] = [['icon' => 'truck', 'title' => '', 'subtitle' => '']];
        }

        return $s;
    }, HomepageTrustService::defaultConfig()['sections']),
], JSON_UNESCAPED_UNICODE));
ok(!str_contains(stage13_p8_render_home_trust(), 'class="at-trust"'), 'Empty section does not render');

$configOrder = HomepageTrustService::defaultConfig();
foreach ($configOrder['sections'] as &$sec) {
    if (($sec['id'] ?? '') === 'quality') {
        $sec['sort'] = 5;
        $sec['heading'] = 'AT-HP8 Quality First';
    }
    if (($sec['id'] ?? '') === 'why_features') {
        $sec['sort'] = 25;
    }
}
unset($sec);
AppSettings::set(HomepageTrustService::SETTING_KEY, json_encode($configOrder, JSON_UNESCAPED_UNICODE));
$ordered = HomepageTrustService::publicSections('after_subscriptions');
ok(($ordered[0]['id'] ?? '') === 'quality', 'Section order is respected');

ok(Permission::canModule('homepage') === false || AdminAuth::check(), 'Admin permission enforcement baseline');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST[Csrf::fieldName()] = 'invalid-token';
ok(!Csrf::validateRequest(), 'CSRF rejection');
unset($_POST[Csrf::fieldName()]);
$_SERVER['REQUEST_METHOD'] = 'GET';

$save = HomepageTrustService::saveFromRequest([
    'enabled_trust_strip' => '1',
    'sort_trust_strip' => '10',
    'trust_strip_icon_0' => 'truck',
    'trust_strip_title_0' => '<script>alert(1)</script>Safe title',
    'trust_strip_subtitle_0' => 'Sub',
    'trust_strip_icon_1' => 'leaf',
    'trust_strip_title_1' => 'Two',
    'trust_strip_subtitle_1' => 'Sub2',
    'trust_strip_icon_2' => 'refresh',
    'trust_strip_title_2' => 'Three',
    'trust_strip_subtitle_2' => 'Sub3',
    'trust_strip_icon_3' => 'chat',
    'trust_strip_title_3' => 'Four',
    'trust_strip_subtitle_3' => 'Sub4',
    'enabled_why_features' => '1',
    'sort_why_features' => '20',
    'why_heading' => 'Why',
    'why_subtitle' => 'Sub',
    'why_features_icon_0' => 'receipt',
    'why_features_title_0' => 'One',
    'why_features_body_0' => 'Body',
    'why_features_icon_1' => 'box',
    'why_features_title_1' => 'Two',
    'why_features_body_1' => 'Body',
    'why_features_icon_2' => 'star',
    'why_features_title_2' => 'Three',
    'why_features_body_2' => 'Body',
    'why_features_icon_3' => 'lock',
    'why_features_title_3' => 'Four',
    'why_features_body_3' => 'Body',
    'enabled_quality' => '1',
    'sort_quality' => '30',
    'quality_eyebrow' => 'Q',
    'quality_heading' => 'Quality',
    'quality_body' => 'Body',
    'quality_bullet_0' => 'B1',
    'quality_image_path' => 'javascript:alert(1)',
    'quality_cta_label' => 'Go',
    'quality_cta_href' => '/page.php?slug=about-us',
    'enabled_testimonials' => '1',
    'sort_testimonials' => '40',
    'testimonials_heading' => 'Stories',
    'testimonials_subtitle' => 'Sub',
    'enabled_faq_support' => '1',
    'sort_faq_support' => '50',
    'faq_eyebrow' => 'FAQ',
    'faq_heading' => 'Questions',
    'faq_body' => 'Help',
    'faq_limit' => '5',
]);
ok(($save['ok'] ?? false) === false, 'Invalid input handling (bad image path)');

$save2 = HomepageTrustService::saveFromRequest([
    'enabled_trust_strip' => '1',
    'sort_trust_strip' => '10',
    'trust_strip_icon_0' => 'truck',
    'trust_strip_title_0' => '<script>alert(1)</script>Safe title',
    'trust_strip_subtitle_0' => 'Sub',
    'trust_strip_icon_1' => 'leaf',
    'trust_strip_title_1' => 'Two',
    'trust_strip_subtitle_1' => 'Sub2',
    'trust_strip_icon_2' => 'refresh',
    'trust_strip_title_2' => 'Three',
    'trust_strip_subtitle_2' => 'Sub3',
    'trust_strip_icon_3' => 'chat',
    'trust_strip_title_3' => 'Four',
    'trust_strip_subtitle_3' => 'Sub4',
    'enabled_why_features' => '1',
    'sort_why_features' => '20',
    'why_heading' => 'Why',
    'why_subtitle' => 'Sub',
    'why_features_icon_0' => 'receipt',
    'why_features_title_0' => 'One',
    'why_features_body_0' => 'Body',
    'why_features_icon_1' => 'box',
    'why_features_title_1' => 'Two',
    'why_features_body_1' => 'Body',
    'why_features_icon_2' => 'star',
    'why_features_title_2' => 'Three',
    'why_features_body_2' => 'Body',
    'why_features_icon_3' => 'lock',
    'why_features_title_3' => 'Four',
    'why_features_body_3' => 'Body',
    'enabled_quality' => '1',
    'sort_quality' => '30',
    'quality_eyebrow' => 'Q',
    'quality_heading' => 'Quality',
    'quality_body' => 'Body',
    'quality_bullet_0' => 'B1',
    'quality_image_path' => 'assets/images/demo/about-packing.jpg',
    'quality_cta_label' => 'Go',
    'quality_cta_href' => '/page.php?slug=about-us',
    'enabled_testimonials' => '1',
    'sort_testimonials' => '40',
    'testimonials_heading' => 'Stories',
    'testimonials_subtitle' => 'Sub',
    'enabled_faq_support' => '1',
    'sort_faq_support' => '50',
    'faq_eyebrow' => 'FAQ',
    'faq_heading' => 'Questions',
    'faq_body' => 'Help',
    'faq_limit' => '5',
]);
ok($save2['ok'] ?? false, 'Valid save succeeds');
$renderSafe = stage13_p8_render_home_trust();
ok(str_contains($renderSafe, 'Safe title') && !str_contains($renderSafe, '<script>'), 'HTML/script injection protection');

$pdo->prepare(
    "INSERT INTO testimonials (customer_display_name, body, status, consent_recorded_at, featured, sort_order)
     VALUES ('AT-HP8 Approved', 'Approved story text.', ?, NOW(), 1, 0)"
)->execute([ReviewStatus::APPROVED]);
$pdo->prepare(
    "INSERT INTO testimonials (customer_display_name, body, status, consent_recorded_at, featured, sort_order)
     VALUES ('AT-HP8 Pending', 'Pending story.', ?, NOW(), 1, 0)"
)->execute([ReviewStatus::PENDING]);
$pdo->prepare(
    "INSERT INTO testimonials (customer_display_name, body, status, consent_recorded_at, featured, sort_order)
     VALUES ('AT-HP8 Rejected', 'Rejected story.', ?, NOW(), 1, 0)"
)->execute([ReviewStatus::REJECTED]);
$pdo->prepare(
    "INSERT INTO testimonials (customer_display_name, body, status, consent_recorded_at, featured, sort_order)
     VALUES ('AT-HP8 Withdrawn', 'Withdrawn story.', ?, NULL, 1, 0)"
)->execute([ReviewStatus::APPROVED]);

$testHtml = stage13_p8_render_home_trust();
ok(str_contains($testHtml, 'Approved story text.'), 'Existing approved testimonial displayed with valid consent');
ok(!str_contains($testHtml, 'Pending story.'), 'Pending testimonial excluded');
ok(!str_contains($testHtml, 'Rejected story.'), 'Rejected testimonial excluded');
ok(!str_contains($testHtml, 'Withdrawn story.'), 'Withdrawn-consent testimonial excluded');

$pdo->exec("DELETE FROM testimonials WHERE customer_display_name LIKE 'AT-HP8 %'");
ok(str_contains(stage13_p8_render_home_trust(), 'Real stories, when customers share them'), 'Homepage works when no testimonials exist');

$seoBefore = SeoResolver::forHomepage();
$jsonBefore = SeoStructuredData::forHomepage($seoBefore);
HomepageTrustService::saveFromRequest(array_merge($_POST ?? [], []));
$seoAfter = SeoResolver::forHomepage();
ok(
    $seoBefore->documentTitle === $seoAfter->documentTitle
    && $seoBefore->canonicalUrl === $seoAfter->canonicalUrl,
    'Existing homepage SEO metadata unchanged'
);
$jsonAfter = SeoStructuredData::forHomepage($seoAfter);
ok(
    ($jsonBefore !== null && $jsonBefore->hasType('Organization')) === ($jsonAfter !== null && $jsonAfter->hasType('Organization')),
    'Existing homepage JSON-LD remains valid'
);

$dupCount = substr_count(stage13_p8_render_home_trust(), 'class="at-trust"');
ok($dupCount <= 1, 'No duplicate trust sections');

ok(HomepageTrustIcons::normalizeStoredId('message-circle') === 'chat', 'Icon alias message-circle → chat');
ok(HomepageTrustIcons::normalizeStoredId('shopping-basket') === 'basket', 'Icon alias shopping-basket → basket');
ok(HomepageTrustIcons::normalizeStoredId('truck') === 'truck', 'Legacy truck icon id');
$htmlIcons = HomepageTrustRenderer::renderAll(url_base());
ok(str_contains($htmlIcons, '<svg') && str_contains($htmlIcons, 'Fee shown upfront'), 'Trust icons render via at_icon');

$badIcon = HomepageTrustService::saveFromRequest([
    'enabled_trust_strip' => '1',
    'sort_trust_strip' => '10',
    'trust_strip_icon_0' => 'not-a-real-icon',
    'trust_strip_title_0' => 'T',
    'trust_strip_subtitle_0' => 'S',
    'trust_strip_icon_1' => 'leaf',
    'trust_strip_title_1' => 'Two',
    'trust_strip_subtitle_1' => 'S2',
    'trust_strip_icon_2' => 'refresh',
    'trust_strip_title_2' => 'Three',
    'trust_strip_subtitle_2' => 'S3',
    'trust_strip_icon_3' => 'chat',
    'trust_strip_title_3' => 'Four',
    'trust_strip_subtitle_3' => 'S4',
    'enabled_why_features' => '0',
    'sort_why_features' => '20',
    'enabled_quality' => '0',
    'enabled_testimonials' => '0',
    'enabled_faq_support' => '0',
]);
ok(empty($badIcon['ok']) && !empty($badIcon['errors']['trust_strip_icon_0']), 'Unsupported icon rejected on save');

stage13_p8_cleanup($pdo);
AppSettings::set('homepage.trust.config', null);
$defaults = HomepageTrustService::getConfig();
$hasTrustStrip = false;
foreach ($defaults['sections'] ?? [] as $sec) {
    if (($sec['id'] ?? '') === 'trust_strip' && !empty($sec['enabled'])) {
        $hasTrustStrip = true;
    }
}
ok($hasTrustStrip, 'Fresh-install defaults work');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 Phase 8 homepage trust.\n";
exit(0);
