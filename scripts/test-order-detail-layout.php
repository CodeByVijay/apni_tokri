<?php

declare(strict_types=1);

/** Static checks for customer order detail grid structure (no DB). */
$src = file_get_contents(dirname(__DIR__) . '/order-details.php');
$css = file_get_contents(dirname(__DIR__) . '/assets/css/apni-tokri.css');
$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$ok(strpos($src, 'class="at-card at-order-detail__grid-full"') !== false, 'refund card is a grid-full row');
$ok(strpos($src, 'Refund or quality issue') !== false, 'refund section present');
$refundPos = strpos($src, 'Refund or quality issue');
$gridClose = strrpos($src, '<div class="at-order-detail__grid">');
$gridEnd = strpos($src, '</div>', $refundPos);
$ok($gridClose !== false && $refundPos > $gridClose, 'refund section sits inside order-detail grid');
$ok(
    preg_match('/\.at-order-detail__grid-full\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/', $css) === 1,
    'grid-full spans both columns'
);
$unscopedCancelled = (bool) preg_match_all(
    '/(?<![\w-])\.at-stepper--cancelled(?![\w-])/',
    $css,
    $m,
    PREG_OFFSET_CAPTURE
);
$bad = false;
foreach ($m[0] as $hit) {
    $pos = $hit[1];
    $before = substr($css, max(0, $pos - 80), 80);
    if (!str_contains($before, 'at-order-detail__timeline')) {
        $bad = true;
        break;
    }
}
$ok($unscopedCancelled && !$bad, 'cancelled stepper rules scoped to order-detail timeline');
$ok(
    strpos($css, '.at-order-detail__timeline .at-stepper--cancelled .at-stepper__list') !== false,
    'cancelled stepper scoped under order-detail timeline'
);

exit($fail > 0 ? 1 : 0);
