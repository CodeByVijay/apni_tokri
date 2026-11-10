<?php

declare(strict_types=1);

final class FirebaseCredentialManager
{
    /**
     * @return array{ok: bool, json?: array<string, mixed>, client_email?: string, fingerprint?: string, errors?: list<string>}
     */
    public static function parseServiceAccountUpload(string $contents): array
    {
        $contents = trim($contents);
        if ($contents === '') {
            return ['ok' => false, 'errors' => ['Service account JSON is empty.']];
        }
        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['Invalid JSON.']];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'errors' => ['Invalid service account structure.']];
        }
        $type = (string) ($data['type'] ?? '');
        if ($type !== 'service_account') {
            return ['ok' => false, 'errors' => ['JSON must be a Firebase/Google service_account key.']];
        }
        $email = trim((string) ($data['client_email'] ?? ''));
        $projectId = trim((string) ($data['project_id'] ?? ''));
        $key = (string) ($data['private_key'] ?? '');
        $errors = [];
        if ($email === '') {
            $errors[] = 'Missing client_email.';
        }
        if ($projectId === '') {
            $errors[] = 'Missing project_id.';
        }
        if ($key === '') {
            $errors[] = 'Missing private_key.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $fp = hash('sha256', $email . '|' . $projectId);

        return [
            'ok' => true,
            'json' => $data,
            'client_email' => $email,
            'fingerprint' => $fp,
        ];
    }

    public static function encryptForStorage(array $serviceAccount): string
    {
        $json = json_encode($serviceAccount, JSON_UNESCAPED_SLASHES);

        return SecretStore::encrypt($json !== false ? $json : '');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decryptStored(?string $encrypted): ?array
    {
        if ($encrypted === null || trim($encrypted) === '') {
            return null;
        }
        try {
            $plain = SecretStore::decrypt($encrypted);
            $data = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @return array{ok: bool, message: string, error_code?: string}
     */
    public static function testCredentials(array $serviceAccount, int $projectRowId): array
    {
        $oauth = FirebaseOAuth::obtainAccessToken($serviceAccount, $projectRowId);
        if (empty($oauth['ok'])) {
            return [
                'ok' => false,
                'message' => (string) ($oauth['error'] ?? 'Credential test failed.'),
                'error_code' => (string) ($oauth['error_code'] ?? 'test_failed'),
            ];
        }

        return ['ok' => true, 'message' => 'OAuth token obtained for Firebase Messaging scope.'];
    }
}
