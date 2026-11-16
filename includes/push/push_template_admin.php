<?php

declare(strict_types=1);

final class PushTemplateAdmin
{
    public const MAX_KEY_LEN = 64;
    public const MAX_EVENT_LEN = 64;
    public const MAX_TITLE_LEN = 255;
    public const MAX_BODY_LEN = 512;
    public const MAX_URL_LEN = 512;

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: list<string>, id?: int}
     */
    public static function create(array $input): array
    {
        if (!PushSchema::tableReady('push_notification_templates')) {
            return ['ok' => false, 'errors' => ['Templates table is not available.']];
        }
        $normalized = self::normalizeInput($input, true);
        $errors = self::validateInput($normalized, true, null);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $dup = $pdo->prepare('SELECT id FROM push_notification_templates WHERE template_key = ? LIMIT 1');
        $dup->execute([$normalized['template_key']]);
        if ($dup->fetchColumn() !== false) {
            return ['ok' => false, 'errors' => ['Template key is already in use.']];
        }
        $stmt = $pdo->prepare(
            'INSERT INTO push_notification_templates
                (template_key, event_type, title_template, body_template, click_url_template, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $normalized['template_key'],
            $normalized['event_type'],
            $normalized['title_template'],
            $normalized['body_template'],
            $normalized['click_url_template'],
            $normalized['is_active'],
        ]);

        return ['ok' => true, 'errors' => [], 'id' => (int) $pdo->lastInsertId()];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: list<string>}
     */
    public static function update(int $id, array $input): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'errors' => ['Invalid template id.']];
        }
        if (!PushSchema::tableReady('push_notification_templates')) {
            return ['ok' => false, 'errors' => ['Templates table is not available.']];
        }
        $row = self::findById($id);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['Template not found.']];
        }
        $isSystem = PushTemplateCatalog::isSystemTemplateKey((string) $row['template_key']);
        $normalized = self::normalizeInput($input, false);
        if ($isSystem) {
            $normalized['template_key'] = (string) $row['template_key'];
            $normalized['event_type'] = (string) $row['event_type'];
        } else {
            if ($normalized['template_key'] === '') {
                $normalized['template_key'] = (string) $row['template_key'];
            }
            if ($normalized['event_type'] === '') {
                $normalized['event_type'] = (string) $row['event_type'];
            }
        }
        $errors = self::validateInput($normalized, false, $row);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        if (!$isSystem) {
            $dup = Database::pdo()->prepare(
                'SELECT id FROM push_notification_templates WHERE template_key = ? AND id <> ? LIMIT 1'
            );
            $dup->execute([$normalized['template_key'], $id]);
            if ($dup->fetchColumn() !== false) {
                return ['ok' => false, 'errors' => ['Template key is already in use.']];
            }
        }
        if ($isSystem) {
            Database::pdo()->prepare(
                'UPDATE push_notification_templates
                 SET title_template = ?, body_template = ?, click_url_template = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $normalized['title_template'],
                $normalized['body_template'],
                $normalized['click_url_template'],
                $normalized['is_active'],
                $id,
            ]);
        } else {
            Database::pdo()->prepare(
                'UPDATE push_notification_templates
                 SET template_key = ?, event_type = ?, title_template = ?, body_template = ?,
                     click_url_template = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $normalized['template_key'],
                $normalized['event_type'],
                $normalized['title_template'],
                $normalized['body_template'],
                $normalized['click_url_template'],
                $normalized['is_active'],
                $id,
            ]);
        }

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @return array{ok: bool, errors: list<string>}
     */
    public static function delete(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'errors' => ['Invalid template id.']];
        }
        if (!PushSchema::tableReady('push_notification_templates')) {
            return ['ok' => false, 'errors' => ['Templates table is not available.']];
        }
        $row = self::findById($id);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['Template not found.']];
        }
        $key = (string) $row['template_key'];
        if (PushTemplateCatalog::isSystemTemplateKey($key)) {
            return [
                'ok' => false,
                'errors' => [
                    'Default system templates cannot be deleted. Deactivate the template or edit its title and body instead.',
                ],
            ];
        }
        $st = Database::pdo()->prepare('DELETE FROM push_notification_templates WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'errors' => ['Template could not be deleted.']];
        }

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        if ($id <= 0 || !PushSchema::tableReady('push_notification_templates')) {
            return null;
        }
        Install::ensureConnection();
        $st = Database::pdo()->prepare('SELECT * FROM push_notification_templates WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   template_key: string,
     *   event_type: string,
     *   title_template: string,
     *   body_template: string,
     *   click_url_template: ?string,
     *   is_active: int
     * }
     */
    private static function normalizeInput(array $input, bool $isCreate): array
    {
        $click = trim((string) ($input['click_url_template'] ?? ''));
        if ($click === '') {
            $click = null;
        }

        return [
            'template_key' => strtolower(trim((string) ($input['template_key'] ?? ''))),
            'event_type' => trim((string) ($input['event_type'] ?? '')),
            'title_template' => trim((string) ($input['title_template'] ?? '')),
            'body_template' => trim((string) ($input['body_template'] ?? '')),
            'click_url_template' => $click,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existingRow
     * @return list<string>
     */
    private static function validateInput(array $data, bool $isCreate, ?array $existingRow): array
    {
        $errors = [];
        $key = (string) $data['template_key'];
        if ($isCreate && $key === '') {
            $errors[] = 'Template key is required.';
        }
        if ($key !== '' && strlen($key) > self::MAX_KEY_LEN) {
            $errors[] = 'Template key is too long.';
        }
        if ($key !== '' && !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $key)) {
            $errors[] = 'Template key may only contain lowercase letters, numbers, dots, underscores, and hyphens.';
        }
        if ($isCreate && PushTemplateCatalog::isSystemTemplateKey($key)) {
            $errors[] = 'That template key is reserved for a system template.';
        }

        $event = (string) $data['event_type'];
        if ($event === '') {
            $errors[] = 'Event type is required.';
        } elseif (strlen($event) > self::MAX_EVENT_LEN) {
            $errors[] = 'Event type is too long.';
        } elseif (!in_array($event, PushTemplateCatalog::allowedEventTypes(), true)) {
            $errors[] = 'Choose a supported event type.';
        }

        $title = (string) $data['title_template'];
        $body = (string) $data['body_template'];
        if ($title === '') {
            $errors[] = 'Notification title is required.';
        } elseif (strlen($title) > self::MAX_TITLE_LEN) {
            $errors[] = 'Notification title is too long.';
        }
        if ($body === '') {
            $errors[] = 'Notification body is required.';
        } elseif (strlen($body) > self::MAX_BODY_LEN) {
            $errors[] = 'Notification body is too long.';
        }
        $click = $data['click_url_template'];
        if ($click !== null && strlen((string) $click) > self::MAX_URL_LEN) {
            $errors[] = 'Click URL template is too long.';
        }

        $bad = PushTemplateCatalog::unsupportedPlaceholders(
            $event,
            [$title, $body, (string) ($click ?? '')]
        );
        foreach ($bad as $item) {
            if (str_contains($item, 'Event type')) {
                $errors[] = $item;
            } else {
                $errors[] = 'Unsupported placeholder {' . $item . '} for event type ' . $event . '.';
            }
        }

        return $errors;
    }
}
