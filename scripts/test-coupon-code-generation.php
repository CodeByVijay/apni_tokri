<?php

declare(strict_types=1);

/**
 * Regression: admin coupon code generation uses VT prefix; validation accepts legacy/manual codes.
 *
 * CLI (no DB): php scripts/test-coupon-code-generation.php
 * CLI (DB):     php scripts/run-in-disposable-db.php scripts/test-coupon-code-generation.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okCouponGen(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        $fail++;
    }
}

$prefix = CouponAdminService::GENERATED_CODE_PREFIX;
okCouponGen($prefix === 'VT', 'GENERATED_CODE_PREFIX is VT');

$lookup = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/coupon-lookup.php');
okCouponGen(str_contains($lookup, 'CouponAdminService::GENERATED_CODE_PREFIX'), 'coupon-lookup uses centralized prefix constant');
okCouponGen(!preg_match("/=\\s*'APNI'/", $lookup), 'coupon-lookup has no hardcoded APNI default');

$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin-coupon-form.js');
okCouponGen(str_contains($js, '?type=code'), 'admin JS requests code generation without hardcoded prefix');
okCouponGen(!str_contains($js, 'prefix=APNI'), 'admin JS does not pass APNI prefix');

$legacyCodes = ['APNI123456', 'APNI-SAVE10', 'APNI_LEGACY'];
$manualCodes = ['FRESH10', 'SAVE_20', 'MY-CODE'];
foreach (array_merge($legacyCodes, $manualCodes) as $code) {
    $norm = CouponAdminService::normalizeCode($code);
    okCouponGen(CouponAdminService::codeIsValid($norm), 'codeIsValid accepts ' . $code);
}

$ref = new ReflectionMethod(CouponAdminService::class, 'generateUniqueCode');
$defaultPrefix = $ref->getParameters()[0]->getDefaultValue();
okCouponGen($defaultPrefix === 'VT', 'generateUniqueCode default prefix is VT');

if (Install::isSchemaReady()) {
    $codes = [];
    for ($i = 0; $i < 5; $i++) {
        $generated = CouponAdminService::generateUniqueCode();
        okCouponGen(str_starts_with($generated, 'VT'), 'generated code starts with VT: ' . $generated);
        okCouponGen(CouponAdminService::codeIsValid($generated), 'generated code passes validation: ' . $generated);
        okCouponGen(!isset($codes[$generated]), 'generated code is unique within batch: ' . $generated);
        $codes[$generated] = true;
    }
    $custom = CouponAdminService::generateUniqueCode('APNI', 6);
    okCouponGen(str_starts_with($custom, 'APNI'), 'explicit APNI prefix still supported for one-off generation');
} else {
    echo "SKIP: database not ready — uniqueness / live generation tests omitted.\n";
    echo "      Run: php scripts/run-in-disposable-db.php scripts/test-coupon-code-generation.php\n";
}

echo $fail === 0 ? "All coupon code generation checks passed.\n" : "{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
