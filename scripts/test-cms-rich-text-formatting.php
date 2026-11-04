<?php

declare(strict_types=1);

/**
 * CMS rich text: sanitizer preserves semantic structure, rejects unsafe HTML, public rendering uses .cms-body.
 * Needs ext-dom (Apache PHP 7.3 has it): php7.3 scripts/test-cms-rich-text-formatting.php
 * Uses a throwaway at-cms13-rich-* page; never touches real CMS pages.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage13_phase1_fixture.php';

$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

if (!extension_loaded('dom')) {
    fwrite(STDERR, "ext-dom missing (use php7.3).\n");
    exit(1);
}

function san(string $h): string
{
    $r = CmsHtmlSanitizer::sanitize($h);

    return $r['ok'] ? $r['html'] : '';
}

// --- Semantic structure preserved ---
$policy = '<h1>Refund Policy</h1><h2>1. Eligibility</h2><p>Refunds apply to <strong>damaged</strong> or <em>wrong</em> items.</p>'
    . '<ol><li>Report within 24 hours<ul><li>Share a photo</li></ul></li><li>Support reviews</li></ol>'
    . '<ul><li>Bullet one</li></ul><blockquote>Note</blockquote><h3>Contact</h3><p><a href="https://example.com/help">Help</a></p>';
$out = san($policy);
foreach (['<h1>', '<h2>', '<h3>', '<strong>', '<em>', '<ol>', '<ul><li>Share', '<blockquote>', 'href="https://example.com/help"'] as $needle) {
    ok(strpos($out, $needle) !== false, 'Preserved ' . $needle);
}
ok(substr_count($out, '<p>') === 2, 'Paragraphs preserved (no extra paragraphs from whitespace)');

// --- Pasted div/span structure keeps block boundaries ---
$pasted = san('<div>First para</div><div>Second <span style="font-weight:700">text</span></div>');
ok($pasted === '<p>First para</p><p>Second text</p>', 'Pasted <div> blocks become separate <p>');
$mixed = san('<div><h2>Title</h2><div>Body</div></div>');
ok($mixed === '<h2>Title</h2><p>Body</p>', 'Div wrapping blocks is unwrapped; inner div becomes <p>');

// --- Security ---
ok(strpos(san('<p>a</p><script>alert(1)</script>'), 'script') === false, 'script removed');
ok(strpos(san('<span><script>alert(1)</script>x</span>'), 'script') === false, 'script inside unwrapped span removed');
ok(strpos(san('<div><iframe src="https://x.test"></iframe><p>k</p></div>'), 'iframe') === false, 'iframe removed');
ok(strpos(san('<object data="x"></object><embed src="x">'), 'object') === false && strpos(san('<embed src="x">'), 'embed') === false, 'object/embed removed');
ok(strpos(san('<p onclick="x()" onmouseover="y()" style="color:red">t</p>'), 'on') === false || strpos(san('<p onclick="x()">t</p>'), 'onclick') === false, 'event handler attributes removed');
ok(san('<p onclick="x()" style="color:red">t</p>') === '<p>t</p>', 'inline style/event attributes stripped');
$js = san('<a href="javascript:alert(1)">x</a>');
ok(strpos($js, 'javascript') === false && strpos($js, '<a') === false, 'javascript: link removed');
ok(strpos(san('<a href="data:text/html,hi">x</a>'), 'data:') === false, 'data: link removed');
ok(strpos(san('<a href="JaVaScRiPt:alert(1)">x</a>'), 'script') === false, 'mixed-case javascript: removed');
ok(strpos(san('<a href="https://ok.test/a" onclick="x()">x</a>'), 'onclick') === false, 'link event handler removed');

// --- Public render (fixture page; real pages untouched) ---
Install::ensureConnection();
$pdo = Database::pdo();
stage13_p1_cleanup($pdo);
$slug = 'at-cms13-rich-' . strtolower(Security::randomToken(3));
$create = CmsPageService::create(['title' => 'AT-CMS13 Rich', 'slug' => $slug, 'body_html' => $policy . '<script>bad()</script>'], null);
ok(!empty($create['ok']), 'Create fixture page with pasted-style HTML');
$id = (int) ($create['id'] ?? 0);
$row = CmsPageRepository::findById($id);
ok($row !== null && strpos((string) $row['body_html'], '<h2>1. Eligibility</h2>') !== false && strpos((string) $row['body_html'], 'script') === false, 'Stored HTML keeps headings, drops script (editor reopen value)');
$pub = CmsPageService::publish($id, ['title' => 'AT-CMS13 Rich', 'slug' => $slug, 'body_html' => (string) $row['body_html']], null);
ok(!empty($pub['ok']), 'Publish fixture page');
$cmsPage = CmsPageService::publishedPublicBySlug($slug);
ok($cmsPage !== null, 'Published page readable');
if ($cmsPage !== null) {
    ob_start();
    require APNI_TOKRI_ROOT . '/includes/partials/cms-page-body.php';
    $html = (string) ob_get_clean();
    ok(strpos($html, 'class="cms-body"') !== false, 'Public page uses .cms-body container');
    ok(strpos($html, '<ol><li>Report') !== false && strpos($html, '<strong>damaged</strong>') !== false, 'Public page renders ordered list and bold');
    ok(strpos($html, 'prose') === false, 'No dependency on missing Tailwind typography (prose)');
}
stage13_p1_cleanup($pdo);

// --- CSS ---
$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
ok(preg_match('/\.cms-body\s*\{[^}]*max-width:\s*50rem/s', $css) === 1, 'CMS width ~800px');
ok(preg_match('/\.cms-body\s*\{[^}]*line-height:\s*1\.75/s', $css) === 1, 'CMS line-height 1.75');
ok(strpos($css, '.cms-body ul { list-style: disc; }') !== false && strpos($css, '.cms-body ol { list-style: decimal; }') !== false, 'List markers restored');
ok(strpos($css, '.cms-body h2') !== false && strpos($css, '.cms-body blockquote') !== false, 'Heading and blockquote styles defined');

echo $fail === 0 ? "PASS: CMS rich text formatting.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
