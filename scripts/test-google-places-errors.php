<?php

declare(strict_types=1);

/**
 * Offline tests for GooglePlacesGeocoder error detail extraction/classification.
 * Feeds representative google.rpc error payloads (including the staging
 * PERMISSION_DENIED "Permission denied on the service." shape) through the
 * private helpers via reflection — no network, no DB.
 *
 * Run: php scripts/test-google-places-errors.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/google_places.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_admin.php';

$fail = 0;
function eOk(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? ' OK  ' : 'FAIL ') . $msg . PHP_EOL;
    if (!$cond) {
        ++$fail;
    }
}

/** @param list<mixed> $args */
function priv(string $class, string $method, array $args = []): mixed
{
    $m = new ReflectionMethod($class, $method);

    return $m->invoke(null, ...$args);
}

/** Staging-reported shape: valid key denied at the API-key gate. */
$serviceBlocked = [
    'error' => [
        'code' => 403,
        'message' => 'Permission denied on the service.',
        'status' => 'PERMISSION_DENIED',
        'details' => [
            [
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => 'API_KEY_SERVICE_BLOCKED',
                'domain' => 'googleapis.com',
                'metadata' => [
                    'service' => 'places.googleapis.com',
                    'method' => 'google.maps.places.v1.Places.Autocomplete',
                    'consumer' => 'projects/1234567890',
                ],
            ],
        ],
    ],
];

$info = priv(GooglePlacesGeocoder::class, 'extractGoogleErrorInfo', [$serviceBlocked]);
eOk($info['reason'] === 'API_KEY_SERVICE_BLOCKED', 'ErrorInfo reason extracted (API_KEY_SERVICE_BLOCKED)');
eOk($info['service'] === 'places.googleapis.com', 'ErrorInfo service extracted');
eOk($info['method'] === 'google.maps.places.v1.Places.Autocomplete', 'ErrorInfo method extracted');
eOk(!isset($info['consumer']), 'consumer/project id never extracted');

[$st, $msg] = priv(GooglePlacesGeocoder::class, 'extractGoogleError', [$serviceBlocked]);
eOk($st === 'PERMISSION_DENIED' && $msg === 'Permission denied on the service.', 'top-level status/message still parsed');

$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', [$st, $msg, $info['reason']]);
eOk($tok === 'SERVICE_BLOCKED', 'API_KEY_SERVICE_BLOCKED → SERVICE_BLOCKED token');

$out = priv(GooglePlacesGeocoder::class, 'formatAdminFacingError', [$st, $msg, $tok, $info['reason']]);
eOk($out === 'SERVICE_BLOCKED — Permission denied on the service. [API_KEY_SERVICE_BLOCKED]', 'surfaced error names the gate');

$diag = priv(GooglePlacesGeocoder::class, 'withGoogleErrorInfo', [['base' => 'x'], $info]);
eOk($diag['google_reason'] === 'API_KEY_SERVICE_BLOCKED' && $diag['google_service'] === 'places.googleapis.com'
    && $diag['google_method'] === 'google.maps.places.v1.Places.Autocomplete', 'diagnostic carries reason/service/method');

// API not enabled on project — message embeds a console URL with project id.
$disabled = [
    'error' => [
        'code' => 403,
        'status' => 'PERMISSION_DENIED',
        'message' => 'Places API (New) has not been used in project 999 before or it is disabled. Enable it by visiting https://console.developers.google.com/apis/api/places.googleapis.com/overview?project=999 then retry.',
        'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED', 'domain' => 'googleapis.com',
                'metadata' => ['service' => 'places.googleapis.com']],
        ],
    ],
];
$info = priv(GooglePlacesGeocoder::class, 'extractGoogleErrorInfo', [$disabled]);
eOk($info['reason'] === 'SERVICE_DISABLED', 'SERVICE_DISABLED reason extracted');
[$st, $msg] = priv(GooglePlacesGeocoder::class, 'extractGoogleError', [$disabled]);
$san = priv(GooglePlacesGeocoder::class, 'sanitizeGoogleErrorMessage', [$msg]);
eOk(strpos($san, 'http') === false && strpos($san, '[link]') !== false, 'console URL stripped from surfaced message');
eOk(strpos($san, 'project=999') === false, 'project id not leaked');
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', [$st, $san, $info['reason']]);
eOk($tok === 'API_NOT_ENABLED', 'SERVICE_DISABLED → API_NOT_ENABLED');

// Invalid key.
$badKey = [
    'error' => [
        'code' => 400,
        'status' => 'INVALID_ARGUMENT',
        'message' => 'API key not valid. Please pass a valid API key.',
        'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com',
                'metadata' => ['service' => 'places.googleapis.com']],
        ],
    ],
];
$info = priv(GooglePlacesGeocoder::class, 'extractGoogleErrorInfo', [$badKey]);
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['INVALID_ARGUMENT', 'API key not valid.', $info['reason']]);
eOk($tok === 'API_KEY_INVALID', 'API_KEY_INVALID → API_KEY_INVALID');

// Referrer-restricted key used server-side.
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['PERMISSION_DENIED', 'Requests from referer <empty> are blocked.', 'API_KEY_HTTP_REFERRER_BLOCKED']);
eOk($tok === 'REFERRER_BLOCKED', 'referrer-restricted key → REFERRER_BLOCKED');

// IP-restricted key from wrong egress.
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['PERMISSION_DENIED', 'Requests from this IP address are blocked.', 'API_KEY_IP_ADDRESS_BLOCKED']);
eOk($tok === 'IP_BLOCKED', 'IP-restricted key → IP_BLOCKED');

// Billing.
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['PERMISSION_DENIED', 'This API method requires billing to be enabled.', 'BILLING_DISABLED']);
eOk($tok === 'BILLING_NOT_ENABLED', 'BILLING_DISABLED → BILLING_NOT_ENABLED');

// Quota: structured reason + legacy status fallbacks.
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['RESOURCE_EXHAUSTED', 'Quota exceeded.', 'RATE_LIMIT_EXCEEDED']);
eOk($tok === 'QUOTA_EXCEEDED', 'RATE_LIMIT_* reason → QUOTA_EXCEEDED');
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['OVER_QUERY_LIMIT', '', '']);
eOk($tok === 'QUOTA_EXCEEDED', 'legacy OVER_QUERY_LIMIT still → QUOTA_EXCEEDED');
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['RESOURCE_EXHAUSTED', '', '']);
eOk($tok === 'QUOTA_EXCEEDED', 'bare RESOURCE_EXHAUSTED status → QUOTA_EXCEEDED');

// Heuristic fallbacks unchanged when no structured reason.
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['PERMISSION_DENIED', 'Permission denied on the service.', '']);
eOk($tok === 'PERMISSION_DENIED', 'no details → PERMISSION_DENIED fallback preserved');
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['REQUEST_DENIED', 'You must enable Billing.', '']);
eOk($tok === 'BILLING_NOT_ENABLED', 'billing text heuristic still works');
$tok = priv(GooglePlacesGeocoder::class, 'classifyGoogleError', ['REQUEST_DENIED', 'LegacyApiNotActivatedMapError', '']);
eOk($tok === 'API_NOT_ENABLED', 'legacy-API heuristic still works');

// Malformed/foreign details are ignored safely.
$weird = ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'x', 'details' => [
    ['@type' => 'type.googleapis.com/google.rpc.LocalizedMessage', 'message' => 'hi'],
    ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'not a token!', 'metadata' => ['service' => 'evil\nline']],
]]];
$info = priv(GooglePlacesGeocoder::class, 'extractGoogleErrorInfo', [$weird]);
eOk($info['reason'] === '' && $info['service'] === '', 'malformed ErrorInfo fields rejected by allowlist');
$info2 = priv(GooglePlacesGeocoder::class, 'extractGoogleErrorInfo', [[]]);
eOk($info2 === ['reason' => '', 'service' => '', 'method' => ''], 'empty payload → empty info, no crash');

// key= redaction preserved.
$san = priv(GooglePlacesGeocoder::class, 'sanitizeGoogleErrorMessage', ['Denied: key=AIzaSySECRET123&foo=bar']);
eOk(strpos($san, 'AIzaSySECRET') === false && strpos($san, 'key=[redacted]') !== false, 'key= material still redacted');

// Empty everything → generic message.
$out = priv(GooglePlacesGeocoder::class, 'formatAdminFacingError', ['', '', '']);
eOk($out === 'Google Places request failed.', 'empty error → generic failure string');

// Admin remediation hints map the classified cause to a console fix.
$hint = priv(AddressAutocompleteAdminSettings::class, 'remediationHint', [['class' => 'SERVICE_BLOCKED']]);
eOk(str_contains($hint, 'Places API (New)') && str_contains($hint, 'API restrictions'), 'SERVICE_BLOCKED hint points at key API restrictions');
$hint = priv(AddressAutocompleteAdminSettings::class, 'remediationHint', [['class' => '', 'google_reason' => 'API_KEY_HTTP_REFERRER_BLOCKED']]);
eOk($hint === '', 'hint keyed on class token, not raw reason');
$hint = priv(AddressAutocompleteAdminSettings::class, 'remediationHint', [['class' => 'REFERRER_BLOCKED']]);
eOk(str_contains($hint, 'referrer'), 'REFERRER_BLOCKED hint mentions referrer restrictions');
$hint = priv(AddressAutocompleteAdminSettings::class, 'remediationHint', [['class' => 'UNKNOWN']]);
eOk($hint === '', 'unknown token → no hint');

// Diagnostic flash line includes reason + service.
$line = priv(AddressAutocompleteAdminSettings::class, 'formatDiagnosticFlashLine', [[
    'http_status' => 403,
    'endpoint' => 'https://places.googleapis.com/v1/places:autocomplete',
    'google_status' => 'PERMISSION_DENIED',
    'class' => 'SERVICE_BLOCKED',
    'google_reason' => 'API_KEY_SERVICE_BLOCKED',
    'google_service' => 'places.googleapis.com',
]]);
eOk(str_contains($line, 'reason=API_KEY_SERVICE_BLOCKED (places.googleapis.com)'), 'admin flash shows reason + service');

echo PHP_EOL . ($fail === 0 ? 'ALL OK' : 'FAILURES: ' . $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
