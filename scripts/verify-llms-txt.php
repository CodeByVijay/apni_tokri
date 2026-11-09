<?php
declare(strict_types=1);
$path = dirname(__DIR__) . '/llms.txt';
$raw = file_get_contents($path) ?: '';
$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        $fail++;
    }
}
ok(is_file($path), 'llms.txt exists at web root');
ok(str_starts_with(trim($raw), '# Veggie Tokri'), 'starts with H1 Veggie Tokri');
ok(mb_check_encoding($raw, 'UTF-8'), 'valid UTF-8');
preg_match_all('/\]\((https:\/\/[^)]+)\)/', $raw, $m);
$urls = $m[1] ?? [];
ok($urls !== [], 'contains HTTPS markdown links');
ok(count($urls) === count(array_unique($urls)), 'no duplicate link URLs');
foreach ($urls as $u) {
    ok(str_starts_with($u, 'https://veggietokri.com'), 'canonical host: ' . $u);
    $lower = strtolower($u);
    ok(!str_contains($lower, '/admin'), 'not admin: ' . $u);
    ok(!str_contains($lower, 'login.php'), 'not login: ' . $u);
    ok(!str_contains($lower, 'cart.php'), 'not cart: ' . $u);
    ok(!str_contains($lower, 'checkout.php'), 'not checkout: ' . $u);
}
$robotsPath = dirname(__DIR__) . '/robots.txt';
ok(!is_file($robotsPath), 'static robots.txt not created (dynamic robots.php)');
exit($fail === 0 ? 0 : 1);
