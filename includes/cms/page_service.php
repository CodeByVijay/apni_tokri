<?php

declare(strict_types=1);

final class CmsPageService
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';

    /** Seeded legal/system pages that must not be removed from admin. */
    private const PROTECTED_SLUGS = [
        'about-us',
        'contact-us',
        'privacy-policy',
        'terms',
        'refund-policy',
        'shipping-policy',
        'subscription-terms',
        'disclaimer',
    ];

    /** @var bool */
    private static $testThrowOnVersionInsert = false;

    public static function testThrowOnNextVersionInsert(bool $enabled): void
    {
        self::$testThrowOnVersionInsert = $enabled;
    }

    /**
     * Footer policy / info links (published + public visibility + show_in_footer).
     *
     * @return list<array{title: string, slug: string, href: string}>
     */
    public static function footerLinksForPublic(): array
    {
        if (!self::schemaReady()) {
            return [];
        }
        $base = url_base();
        $links = [];
        foreach (CmsPageRepository::listForPublicFooter() as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '' || !Validation::slug($slug)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $links[] = [
                'title' => $title,
                'slug' => $slug,
                'href' => $base . '/page.php?slug=' . rawurlencode($slug),
            ];
        }

        return $links;
    }

    /** @return array<string, mixed>|null Published public page for storefront */
    public static function publishedPublicBySlug(string $slug): ?array
    {
        if ($slug === '' || !Validation::slug($slug)) {
            return null;
        }
        if (!self::schemaReady()) {
            return null;
        }
        $row = CmsPageRepository::findBySlug($slug);
        if ($row === null) {
            return null;
        }
        if ((string) ($row['status'] ?? '') !== PublishStatus::PUBLISHED) {
            return null;
        }
        if ((string) ($row['visibility'] ?? self::VISIBILITY_PUBLIC) !== self::VISIBILITY_PUBLIC) {
            return null;
        }

        return self::normalizeRow($row);
    }

    /** @return array<string, mixed>|null Published page if viewer may access (public or authorized private) */
    public static function publishedViewableBySlug(string $slug): ?array
    {
        if ($slug === '' || !Validation::slug($slug)) {
            return null;
        }
        if (!self::schemaReady()) {
            return null;
        }
        $row = CmsPageRepository::findBySlug($slug);
        if ($row === null || (string) ($row['status'] ?? '') !== PublishStatus::PUBLISHED) {
            return null;
        }
        $visibility = (string) ($row['visibility'] ?? self::VISIBILITY_PUBLIC);
        if ($visibility === self::VISIBILITY_PRIVATE && !self::viewerAuthorizedForPrivate()) {
            return null;
        }

        return self::normalizeRow($row);
    }

    public static function viewerAuthorizedForPrivate(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        return AdminAuth::check();
    }

    /** @return array<string, mixed>|null */
    public static function getByIdForAdmin(int $id): ?array
    {
        if ($id <= 0 || !self::schemaReady()) {
            return null;
        }
        $row = CmsPageRepository::findById($id);

        return $row ? self::normalizeRow($row) : null;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public static function listForAdmin(int $page = 1, int $perPage = 20): array
    {
        return self::searchForAdmin('', null, null, $page, $perPage);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function searchForAdmin(
        string $query,
        ?string $status,
        ?string $visibility,
        int $page = 1,
        int $perPage = 20
    ): array {
        if (!self::schemaReady()) {
            return ['rows' => [], 'total' => 0];
        }

        return CmsPageRepository::searchForAdmin($query, $status, $visibility, $page, $perPage);
    }

    /** @return array<string, mixed>|null Admin-only preview (any status). */
    public static function pageForAdminPreview(int $id): ?array
    {
        if ($id <= 0 || !self::schemaReady() || !AdminAuth::check() || !Permission::canModule('cms')) {
            return null;
        }
        $row = CmsPageRepository::findById($id);

        return $row ? self::normalizeRow($row) : null;
    }

    /** @return array<string, mixed>|null */
    public static function versionForAdmin(int $versionId): ?array
    {
        if ($versionId <= 0 || !self::schemaReady() || !AdminAuth::check() || !Permission::canModule('cms')) {
            return null;
        }
        $row = CmsPageRepository::findVersionById($versionId);
        if ($row === null) {
            return null;
        }
        $san = CmsHtmlSanitizer::sanitize((string) ($row['body_html'] ?? ''));

        return $san['ok'] ? $row : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function create(array $input, ?int $adminId = null): array
    {
        $validated = self::validateInput($input, null);
        if (!$validated['ok']) {
            return $validated;
        }
        $data = $validated['data'];
        $data['status'] = PublishStatus::DRAFT;
        $data['published_at'] = null;

        try {
            return Database::transaction(static function () use ($data, $adminId): array {
                $id = CmsPageRepository::insert($data);
                self::appendVersion($id, $data, $adminId, 'Initial draft');

                return ['ok' => true, 'id' => $id];
            });
        } catch (Throwable $e) {
            error_log('CmsPageService::create: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not create page.'];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function update(int $id, array $input, ?int $adminId = null, ?string $changeSummary = null): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid page.'];
        }
        $existing = CmsPageRepository::findById($id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'Page not found.'];
        }
        $validated = self::validateInput($input, $id);
        if (!$validated['ok']) {
            return $validated;
        }
        $data = $validated['data'];
        $data['status'] = (string) ($existing['status'] ?? PublishStatus::DRAFT);
        $data['published_at'] = $existing['published_at'] ?? null;

        try {
            return self::commitPageUpdate($id, $existing, $data, $adminId, $changeSummary ?? 'Updated');
        } catch (Throwable $e) {
            error_log('CmsPageService::update: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save page.'];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function saveDraft(int $id, array $input, ?int $adminId = null): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid page.'];
        }
        $existing = CmsPageRepository::findById($id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'Page not found.'];
        }
        $validated = self::validateInput($input, $id);
        if (!$validated['ok']) {
            return $validated;
        }
        $data = $validated['data'];
        $data['status'] = PublishStatus::DRAFT;
        $data['published_at'] = null;

        try {
            return self::commitPageUpdate($id, $existing, $data, $adminId, 'Draft saved');
        } catch (Throwable $e) {
            error_log('CmsPageService::saveDraft: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save draft.'];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, id: int}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function publish(int $id, array $input, ?int $adminId = null): array
    {
        $existing = CmsPageRepository::findById($id);
        if ($id <= 0 || $existing === null) {
            return ['ok' => false, 'message' => 'Page not found.'];
        }
        $validated = self::validateInput($input, $id);
        if (!$validated['ok']) {
            return $validated;
        }
        $data = $validated['data'];
        $blocked = CmsInstallSafety::publishBlockedReason($data);
        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked, 'errors' => ['body_html' => $blocked]];
        }
        $data['status'] = PublishStatus::PUBLISHED;
        $data['published_at'] = date('Y-m-d H:i:s');

        $summary = trim((string) ($input['change_summary'] ?? ''));
        if ($summary === '') {
            $summary = 'Published';
        }

        try {
            return self::commitPageUpdate($id, $existing, $data, $adminId, $summary);
        } catch (Throwable $e) {
            error_log('CmsPageService::publish: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not publish page.'];
        }
    }

    public static function unpublish(int $id, ?int $adminId = null, ?string $changeSummary = null): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid page.'];
        }
        $existing = CmsPageRepository::findById($id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'Page not found.'];
        }
        $data = self::rowToData($existing);
        $data['status'] = PublishStatus::DRAFT;
        $data['published_at'] = null;

        try {
            return Database::transaction(static function () use ($id, $data, $adminId, $changeSummary): array {
                CmsPageRepository::update($id, $data);
                self::appendVersion($id, $data, $adminId, $changeSummary ?? 'Unpublished');

                return ['ok' => true, 'id' => $id];
            });
        } catch (Throwable $e) {
            error_log('CmsPageService::unpublish: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not unpublish page.'];
        }
    }

    /** @return list<array<string, mixed>> */
    public static function versionHistoryForPage(int $cmsPageId): array
    {
        if (!self::schemaReady()) {
            return [];
        }

        return CmsPageRepository::versionHistory($cmsPageId);
    }

    public static function schemaReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'cms_page_versions'"
            );

            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, message: string, errors?: array<string, string>}
     */
    private static function validateInput(array $input, ?int $excludeId): array
    {
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            $errors['title'] = 'Title is required (max 255 characters).';
        }
        $existing = $excludeId !== null ? CmsPageRepository::findById($excludeId) : null;
        $slugRes = CatalogueSlug::resolveForAdminSave('cms_page', $title, $input, $existing, $excludeId);
        if (!$slugRes['ok']) {
            $errors = array_merge($errors, $slugRes['errors']);
            $slug = '';
        } else {
            $slug = $slugRes['slug'];
        }
        $bodyRaw = (string) ($input['body_html'] ?? '');
        $san = CmsHtmlSanitizer::sanitize($bodyRaw);
        if (!$san['ok']) {
            $errors['body_html'] = $san['message'] ?? 'Invalid HTML content.';
        }
        $visibility = (string) ($input['visibility'] ?? self::VISIBILITY_PUBLIC);
        if (!in_array($visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE], true)) {
            $visibility = self::VISIBILITY_PUBLIC;
        }
        $footerOrder = $existing !== null && isset($existing['footer_display_order'])
            ? (int) $existing['footer_display_order'] : null;
        if (array_key_exists('footer_display_order', $input)) {
            $rawOrder = trim((string) ($input['footer_display_order'] ?? ''));
            if ($rawOrder === '') {
                $footerOrder = null;
            } elseif (!ctype_digit($rawOrder) || (int) $rawOrder > 999999) {
                $errors['footer_display_order'] = 'Footer display order must be a whole number from 0 to 999999.';
            } else {
                $footerOrder = (int) $rawOrder;
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        return [
            'ok' => true,
            'data' => [
                'title' => $title,
                'slug' => $slug,
                'footer_display_order' => $footerOrder,
                'body_html' => $san['html'],
                'show_in_footer' => !empty($input['show_in_footer']) ? 1 : 0,
                'visibility' => $visibility,
                'meta_title' => self::nullableTrim($input['meta_title'] ?? null, 191),
                'meta_description' => self::nullableTrim($input['meta_description'] ?? null, 255),
                'focus_keyword' => self::nullableTrim($input['focus_keyword'] ?? null, 191),
                'canonical_url' => self::nullableTrim($input['canonical_url'] ?? null, 512),
                'og_title' => self::nullableTrim($input['og_title'] ?? null, 191),
                'og_description' => self::nullableTrim($input['og_description'] ?? null, 255),
                'og_image' => self::nullableTrim($input['og_image'] ?? null, 512),
                'robots_index' => !empty($input['robots_index']) ? 1 : 0,
                'robots_follow' => !empty($input['robots_follow']) ? 1 : 0,
                'published_at' => null,
                'status' => PublishStatus::DRAFT,
            ],
        ];
    }

    private static function nullableTrim($value, int $maxLen): ?string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $maxLen);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $data
     * @return array{ok: true, id: int}|array{ok: false, message: string}
     */
    private static function commitPageUpdate(
        int $id,
        array $existing,
        array $data,
        ?int $adminId,
        string $summary
    ): array {
        return Database::transaction(static function () use ($id, $existing, $data, $adminId, $summary): array {
            $redirect = SeoRedirectService::registerCmsSlugChangeIfNeeded($existing, $data);
            if (!$redirect['ok']) {
                throw new RuntimeException($redirect['message'] ?? 'Redirect registration failed.');
            }
            CmsPageRepository::update($id, $data);
            self::appendVersion($id, $data, $adminId, $summary);

            return ['ok' => true, 'id' => $id];
        });
    }

    /** @param array<string, mixed> $data */
    private static function appendVersion(int $cmsPageId, array $data, ?int $adminId, string $summary): void
    {
        if (self::$testThrowOnVersionInsert) {
            self::$testThrowOnVersionInsert = false;
            throw new RuntimeException('Simulated version insert failure');
        }
        $version = CmsPageRepository::nextVersionNumber($cmsPageId);
        CmsPageRepository::insertVersion($cmsPageId, $version, $data, $adminId, $summary);
    }

    /** @param array<string, mixed> $row */
    private static function rowToData(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'body_html' => (string) ($row['body_html'] ?? ''),
            'show_in_footer' => (int) ($row['show_in_footer'] ?? 0),
            'footer_display_order' => isset($row['footer_display_order']) ? (int) $row['footer_display_order'] : null,
            'visibility' => (string) ($row['visibility'] ?? self::VISIBILITY_PUBLIC),
            'meta_title' => $row['meta_title'] ?? null,
            'meta_description' => $row['meta_description'] ?? null,
            'focus_keyword' => $row['focus_keyword'] ?? null,
            'canonical_url' => $row['canonical_url'] ?? null,
            'og_title' => $row['og_title'] ?? null,
            'og_description' => $row['og_description'] ?? null,
            'og_image' => $row['og_image'] ?? null,
            'robots_index' => (int) ($row['robots_index'] ?? 1),
            'robots_follow' => (int) ($row['robots_follow'] ?? 1),
            'published_at' => $row['published_at'] ?? null,
            'status' => (string) ($row['status'] ?? PublishStatus::DRAFT),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function normalizeRow(array $row): array
    {
        $row['body_html'] = (string) ($row['body_html'] ?? '');
        $san = CmsHtmlSanitizer::sanitize($row['body_html']);
        if ($san['ok']) {
            $row['body_html'] = $san['html'];
        } else {
            $row['body_html'] = '';
        }

        return $row;
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteForAdmin(int $id): array
    {
        if ($id <= 0 || !self::schemaReady() || !AdminAuth::check() || !Permission::canModule('cms')) {
            return ['ok' => false, 'message' => 'Not allowed.'];
        }
        $row = CmsPageRepository::findById($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Page not found.'];
        }
        $slug = (string) ($row['slug'] ?? '');
        if (in_array($slug, self::PROTECTED_SLUGS, true)) {
            return ['ok' => false, 'message' => 'This system page cannot be deleted. You can unpublish or edit it instead.'];
        }
        try {
            CmsPageRepository::deleteById($id);
            AuditLog::write('cms_page.delete', 'cms_page', (string) $id, ['slug' => $slug]);

            return ['ok' => true, 'message' => 'CMS page deleted.'];
        } catch (Throwable $e) {
            error_log('CmsPageService::deleteForAdmin: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not delete page.'];
        }
    }
}
