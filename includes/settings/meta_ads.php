<?php

declare(strict_types=1);

/**
 * Meta Ads integration settings (`meta.*` AppSettings keys).
 *
 * Phase 1 stores configuration only — no Meta Pixel script is emitted and no
 * Conversions API traffic is sent from this class or its admin screens.
 */
final class MetaAdsSettings
{
    public const KEY_PIXEL_ENABLED = 'meta.pixel_enabled';
    public const KEY_PIXEL_ID = 'meta.pixel_id';
    public const KEY_CAPI_ENABLED = 'meta.capi_enabled';
    public const KEY_DATASET_ID = 'meta.dataset_id';
    public const KEY_TEST_EVENT_CODE = 'meta.test_event_code';
    public const KEY_CAPI_ACCESS_TOKEN = 'meta.capi_access_token';

    public const TOKEN_STATE_NOT_SET = 'not_set';
    public const TOKEN_STATE_CONFIGURED = 'configured';
    public const TOKEN_STATE_UNREADABLE = 'unreadable';

    /** @var array<string, string> */
    public const SECTIONS = [
        'overview' => 'Overview',
        'pixel' => 'Pixel settings',
        'capi' => 'Conversions API',
        'status' => 'Connection & status',
    ];

    public static function normalizeSection(?string $section): string
    {
        $section = strtolower(trim((string) $section));

        return isset(self::SECTIONS[$section]) ? $section : 'overview';
    }

    public static function sectionUrl(string $section): string
    {
        $section = self::normalizeSection($section);

        return url_base() . '/admin/meta/index.php?section=' . rawurlencode($section);
    }

    public static function pixelEnabled(): bool
    {
        return AppSettings::get(self::KEY_PIXEL_ENABLED, '0') === '1';
    }

    /** Normalized numeric Meta Pixel ID, or null when unset/invalid. */
    public static function pixelId(): ?string
    {
        return self::normalizeNumericId(AppSettings::get(self::KEY_PIXEL_ID, ''));
    }

    public static function pixelConfigured(): bool
    {
        return self::pixelId() !== null;
    }

    public static function capiEnabled(): bool
    {
        return AppSettings::get(self::KEY_CAPI_ENABLED, '0') === '1';
    }

    /** Explicitly configured dataset ID, or null. */
    public static function datasetId(): ?string
    {
        return self::normalizeNumericId(AppSettings::get(self::KEY_DATASET_ID, ''));
    }

    /** Dataset used by the Conversions API; Meta datasets share the Pixel ID when not overridden. */
    public static function effectiveDatasetId(): ?string
    {
        return self::datasetId() ?? self::pixelId();
    }

    public static function testEventCode(): ?string
    {
        return self::normalizeTestEventCode(
            trim((string) (AppSettings::get(self::KEY_TEST_EVENT_CODE, '') ?? ''))
        );
    }

    /** Enabled AND a valid ID stored. The pixel script itself ships in Phase 2. */
    public static function pixelReady(): bool
    {
        return self::pixelEnabled() && self::pixelConfigured();
    }

    /** Enabled AND dataset resolvable AND a readable token. No events are sent yet (Phase 3). */
    public static function capiReady(): bool
    {
        return self::capiEnabled()
            && self::effectiveDatasetId() !== null
            && self::accessTokenState() === self::TOKEN_STATE_CONFIGURED;
    }

    /**
     * Whether an access token row exists and can be decrypted.
     *
     * @return 'not_set'|'configured'|'unreadable'
     */
    public static function accessTokenState(): string
    {
        if (!Install::isSchemaReady()) {
            return self::TOKEN_STATE_NOT_SET;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([self::KEY_CAPI_ACCESS_TOKEN]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || trim((string) ($row['setting_value'] ?? '')) === '') {
                return self::TOKEN_STATE_NOT_SET;
            }
            $plain = AppSettings::getSecret(self::KEY_CAPI_ACCESS_TOKEN, '');

            return is_string($plain) && $plain !== ''
                ? self::TOKEN_STATE_CONFIGURED
                : self::TOKEN_STATE_UNREADABLE;
        } catch (Throwable $e) {
            error_log('MetaAdsSettings::accessTokenState: ' . $e->getMessage());

            return self::TOKEN_STATE_UNREADABLE;
        }
    }

    public static function hasAccessToken(): bool
    {
        return self::accessTokenState() === self::TOKEN_STATE_CONFIGURED;
    }

    /** @return array{pixel_enabled: bool, pixel_id: string, capi_enabled: bool, dataset_id: string, test_event_code: string, token_state: string} */
    public static function adminFormState(): array
    {
        return [
            'pixel_enabled' => self::pixelEnabled(),
            'pixel_id' => trim((string) (AppSettings::get(self::KEY_PIXEL_ID, '') ?? '')),
            'capi_enabled' => self::capiEnabled(),
            'dataset_id' => trim((string) (AppSettings::get(self::KEY_DATASET_ID, '') ?? '')),
            'test_event_code' => trim((string) (AppSettings::get(self::KEY_TEST_EVENT_CODE, '') ?? '')),
            'token_state' => self::accessTokenState(),
        ];
    }

    /** @return array<string, bool|string|null> */
    public static function statusSummary(): array
    {
        $tokenState = self::accessTokenState();
        $datasetId = self::effectiveDatasetId();

        return [
            'pixel_enabled' => self::pixelEnabled(),
            'pixel_configured' => self::pixelConfigured(),
            'pixel_ready' => self::pixelReady(),
            'capi_enabled' => self::capiEnabled(),
            'capi_configured' => $datasetId !== null && $tokenState === self::TOKEN_STATE_CONFIGURED,
            'capi_ready' => self::capiReady(),
            'token_state' => $tokenState,
            'effective_dataset_id' => $datasetId,
            'test_event_code' => self::testEventCode(),
            'environment' => AppEnvironment::env(),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>, changed?: list<string>}
     */
    public static function savePixelFromPost(array $post): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => AdminPersistence::unavailableMessage()];
        }
        $unexpected = self::unexpectedFields($post, ['pixel_enabled', 'pixel_id']);
        if ($unexpected !== []) {
            return ['ok' => false, 'message' => 'Unexpected form fields: ' . implode(', ', $unexpected)];
        }

        $enabled = !empty($post['pixel_enabled']);
        $idRaw = trim((string) ($post['pixel_id'] ?? ''));
        $id = self::normalizeNumericId($idRaw);

        $errors = [];
        if ($idRaw !== '' && $id === null) {
            $errors['pixel_id'] = 'Meta Pixel ID must be 5–25 digits (numbers only).';
        }
        if ($enabled && $id === null) {
            $errors['pixel_id'] = $errors['pixel_id']
                ?? 'Enter a valid Meta Pixel ID to enable the pixel.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        $changed = [];
        $newEnabled = $enabled ? '1' : '0';
        if ((string) (AppSettings::get(self::KEY_PIXEL_ENABLED, '0') ?? '0') !== $newEnabled) {
            $changed[] = self::KEY_PIXEL_ENABLED;
        }
        $storedId = trim((string) (AppSettings::get(self::KEY_PIXEL_ID, '') ?? ''));
        $newId = $id ?? '';
        if ($storedId !== $newId) {
            $changed[] = self::KEY_PIXEL_ID;
        }

        AppSettings::set(self::KEY_PIXEL_ENABLED, $newEnabled);
        AppSettings::set(self::KEY_PIXEL_ID, $id);

        return ['ok' => true, 'message' => 'Pixel settings saved.', 'changed' => $changed];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>, changed?: list<string>}
     */
    public static function saveCapiFromPost(array $post): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => AdminPersistence::unavailableMessage()];
        }
        $unexpected = self::unexpectedFields(
            $post,
            ['capi_enabled', 'dataset_id', 'test_event_code', 'capi_access_token']
        );
        if ($unexpected !== []) {
            return ['ok' => false, 'message' => 'Unexpected form fields: ' . implode(', ', $unexpected)];
        }

        $enabled = !empty($post['capi_enabled']);
        $datasetRaw = trim((string) ($post['dataset_id'] ?? ''));
        $dataset = self::normalizeNumericId($datasetRaw);
        $codeRaw = trim((string) ($post['test_event_code'] ?? ''));
        $code = self::normalizeTestEventCode($codeRaw);
        $tokenRaw = trim((string) ($post['capi_access_token'] ?? ''));

        $errors = [];
        if ($datasetRaw !== '' && $dataset === null) {
            $errors['dataset_id'] = 'Dataset ID must be 5–25 digits (numbers only).';
        }
        if ($codeRaw !== '' && $code === null) {
            $errors['test_event_code'] = 'Test event code must be 4–32 letters or digits (for example TEST12345).';
        }
        if ($tokenRaw !== '' && !self::accessTokenLooksValid($tokenRaw)) {
            $errors['capi_access_token'] = 'Access token looks invalid (20–512 characters, no spaces).';
        }
        if ($enabled) {
            // Post-save resolution: submitted dataset, else stored Pixel ID.
            if ($dataset === null && self::pixelId() === null) {
                $errors['dataset_id'] = 'Set a Dataset ID or configure the Meta Pixel ID first.';
            }
            $willHaveToken = $tokenRaw !== ''
                || self::accessTokenState() === self::TOKEN_STATE_CONFIGURED;
            if (!$willHaveToken) {
                $errors['capi_access_token'] = 'A valid access token is required to enable the Conversions API.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }

        $changed = [];
        $newEnabled = $enabled ? '1' : '0';
        if ((string) (AppSettings::get(self::KEY_CAPI_ENABLED, '0') ?? '0') !== $newEnabled) {
            $changed[] = self::KEY_CAPI_ENABLED;
        }
        if (trim((string) (AppSettings::get(self::KEY_DATASET_ID, '') ?? '')) !== ($dataset ?? '')) {
            $changed[] = self::KEY_DATASET_ID;
        }
        if (trim((string) (AppSettings::get(self::KEY_TEST_EVENT_CODE, '') ?? '')) !== ($code ?? '')) {
            $changed[] = self::KEY_TEST_EVENT_CODE;
        }

        AppSettings::set(self::KEY_CAPI_ENABLED, $newEnabled);
        AppSettings::set(self::KEY_DATASET_ID, $dataset);
        AppSettings::set(self::KEY_TEST_EVENT_CODE, $code);
        if ($tokenRaw !== '') {
            AppSettings::set(self::KEY_CAPI_ACCESS_TOKEN, $tokenRaw, true);
            // Record the setting name only — the token value must never be logged.
            $changed[] = self::KEY_CAPI_ACCESS_TOKEN;
        }

        return ['ok' => true, 'message' => 'Conversions API settings saved.', 'changed' => $changed];
    }

    /**
     * Remove the stored access token after an explicit, confirmed admin action.
     * CAPI is force-disabled since a removed token cannot send events.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, changed?: list<string>}
     */
    public static function clearAccessToken(array $post): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => AdminPersistence::unavailableMessage()];
        }
        $unexpected = self::unexpectedFields($post, ['confirm_clear']);
        if ($unexpected !== []) {
            return ['ok' => false, 'message' => 'Unexpected form fields: ' . implode(', ', $unexpected)];
        }
        if (($post['confirm_clear'] ?? '') !== '1') {
            return ['ok' => false, 'message' => 'Token removal was not confirmed.'];
        }
        $changed = [];
        if (self::accessTokenState() !== self::TOKEN_STATE_NOT_SET) {
            AppSettings::set(self::KEY_CAPI_ACCESS_TOKEN, null);
            $changed[] = self::KEY_CAPI_ACCESS_TOKEN;
        }
        if (self::capiEnabled()) {
            AppSettings::set(self::KEY_CAPI_ENABLED, '0');
            $changed[] = self::KEY_CAPI_ENABLED;
        }

        return [
            'ok' => true,
            'message' => $changed !== [] ? 'Access token removed.' : 'No access token was stored.',
            'changed' => $changed,
        ];
    }

    /** Meta Pixel and Dataset IDs are numeric strings. */
    private static function normalizeNumericId(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('/^\d{5,25}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    /** Meta test event codes are short alphanumeric tokens (e.g. TEST12345). */
    private static function normalizeTestEventCode(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9]{4,32}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    /** Plausibility check only — no outbound validation against Meta in Phase 1. */
    private static function accessTokenLooksValid(string $token): bool
    {
        $len = strlen($token);

        return $len >= 20 && $len <= 512 && preg_match('/^[\x21-\x7E]+$/', $token) === 1;
    }

    /**
     * Reject anything outside the expected field set for the action.
     *
     * @param array<string, mixed> $post
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function unexpectedFields(array $post, array $allowed): array
    {
        $known = array_merge(['_csrf_token', 'section', 'meta_action'], $allowed);
        $extra = [];
        foreach ($post as $key => $_) {
            if (!in_array((string) $key, $known, true)) {
                $extra[] = (string) $key;
            }
        }
        sort($extra);

        return $extra;
    }
}
