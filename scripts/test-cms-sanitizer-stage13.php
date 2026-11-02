<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function san(string $html): string
{
    $r = CmsHtmlSanitizer::sanitize($html);
    ok($r['ok'], 'Sanitize succeeds: ' . substr($html, 0, 40));
    return $r['ok'] ? (string) $r['html'] : '';
}

$out = san('<p>Hello <strong>world</strong></p>');
ok(strpos($out, '<strong>') !== false, 'Normal formatting preserved');

$out = san('<h2>Title</h2><ul><li>One</li><li>Two</li></ul>');
ok(strpos($out, '<h2>') !== false && strpos($out, '<li>') !== false, 'Headings and lists preserved');

$out = san('<a href="https://example.com/path">Safe</a>');
ok(strpos($out, 'href="https://example.com/path"') !== false, 'Safe external link preserved');

$out = san('<script>alert(1)</script><p>Ok</p>');
ok(stripos($out, '<script') === false && strpos($out, 'Ok') !== false, 'Script injection removed');

$out = san('<p onclick="alert(1)">X</p>');
ok(stripos($out, 'onclick') === false, 'Event handler injection removed');

$out = san('<a href="javascript:alert(1)">Bad</a><p>Keep</p>');
ok(stripos($out, 'javascript:') === false && strpos($out, 'Keep') !== false, 'javascript: links removed');

$out = san('<img src="javascript:alert(1)" alt="x">');
ok(stripos($out, '<img') === false, 'Unsafe image URL removed');

$out = san('<img src="/uploads/cms/test.webp" alt="ok">');
ok(strpos($out, 'uploads/cms/test.webp') !== false, 'Safe uploads path image kept');

$out = san('<p>Unclosed<b>bold');
ok($out !== '', 'Malformed HTML handled without fatal error');

$xss = san('<img src=x onerror=alert(1)><iframe src="https://evil.test"></iframe><p>Text</p>');
ok(stripos($xss, 'iframe') === false && stripos($xss, 'onerror') === false, 'Stored XSS payloads stripped');

$drop = san('<svg onload="alert(1)"><circle r="1"/></svg><style>body{display:none}</style><object data="x"></object><p>Keep</p>');
ok(stripos($drop, '<svg') === false && stripos($drop, '<style') === false
    && stripos($drop, '<object') === false && stripos($drop, 'circle') === false
    && strpos($drop, 'Keep') !== false, 'Dangerous elements removed with contents');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 13 CMS HTML sanitizer.\n";
exit(0);
