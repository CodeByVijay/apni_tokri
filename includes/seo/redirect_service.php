<?php

declare(strict_types=1);

final class SeoRedirectService
{
    /** @return array{ok: true}|array{ok: false, message: string} */
    public static function deleteById(int $id): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => AdminPersistence::unavailableMessage()];
        }
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid redirect.'];
        }
        if (!SeoRedirectRepository::deleteById($id)) {
            return ['ok' => false, 'message' => 'Redirect not found or already removed.'];
        }

        return ['ok' => true];
    }

    /**
     * Register a permanent internal redirect and collapse chains.
     *
     * @return array{ok: true}|array{ok: false, message: string}
     */
    public static function registerPermanent(string $fromPath, string $toPath): array
    {
        $fromVal = SeoRedirectPath::validateInternalPath($fromPath);
        if (!$fromVal['ok']) {
            return ['ok' => false, 'message' => $fromVal['message']];
        }
        $toVal = SeoRedirectPath::validateInternalPath($toPath);
        if (!$toVal['ok']) {
            return ['ok' => false, 'message' => $toVal['message']];
        }
        $from = $fromVal['path'];
        $to = $toVal['path'];
        if ($from === $to) {
            return ['ok' => false, 'message' => 'Self-redirect is not allowed.'];
        }
        if (self::wouldCreateLoop($from, $to)) {
            return ['ok' => false, 'message' => 'Redirect would create a loop.'];
        }
        $existing = SeoRedirectRepository::findByFromPath($from);
        if ($existing !== null && (string) ($existing['to_path'] ?? '') !== $to) {
            return ['ok' => false, 'message' => 'Redirect source path is already mapped elsewhere.'];
        }

        try {
            Database::transaction(static function () use ($from, $to): void {
                self::applySlugChangeRedirect($from, $to);
            });

            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('SeoRedirectService::registerPermanent: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save redirect.'];
        }
    }

    /** Resolve redirect chain to final destination path. */
    public static function resolveFinalDestination(string $fromPath): ?string
    {
        $start = SeoRedirectPath::redirectIdentity($fromPath);
        if ($start === '') {
            return null;
        }
        $row = SeoRedirectRepository::findByFromPath($start);
        if ($row === null) {
            return null;
        }
        $dest = SeoRedirectPath::normalize((string) ($row['to_path'] ?? ''));
        if ($dest === '' || $dest === $start) {
            return null;
        }
        $visited = [$start => true, $dest => true];
        for ($depth = 0; $depth < SeoRedirectPath::MAX_RESOLVE_DEPTH; $depth++) {
            $nextRow = SeoRedirectRepository::findByFromPath($dest);
            if ($nextRow === null) {
                return $dest;
            }
            $next = SeoRedirectPath::normalize((string) ($nextRow['to_path'] ?? ''));
            if ($next === '' || isset($visited[$next])) {
                return null;
            }
            $visited[$next] = true;
            $dest = $next;
        }

        return null;
    }

    public static function wouldCreateLoop(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        $walker = $to;
        $visited = [$from => true];
        for ($i = 0; $i < SeoRedirectPath::MAX_RESOLVE_DEPTH; $i++) {
            if (isset($visited[$walker])) {
                return true;
            }
            $visited[$walker] = true;
            $row = SeoRedirectRepository::findByFromPath($walker);
            if ($row === null) {
                return false;
            }
            $walker = SeoRedirectPath::normalize((string) ($row['to_path'] ?? ''));
            if ($walker === '' || $walker === $from) {
                return $walker === $from;
            }
        }

        return true;
    }

    /**
     * Issue HTTP redirect for current request when applicable (GET/HEAD only).
     */
    public static function maybeRedirectCurrentRequest(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }
        $current = SeoRedirectPath::currentRedirectIdentity();
        if ($current === '') {
            return;
        }
        if (!self::shouldProcessPath($current)) {
            return;
        }
        if (!Install::isSchemaReady()) {
            return;
        }
        $dest = self::resolveFinalDestination($current);
        if ($dest === null || $dest === $current) {
            return;
        }
        if (self::redirectDestinationBlocked($dest)) {
            return;
        }
        $location = self::redirectLocationWithSafeTracking(
            $dest,
            isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''
        );
        header('Location: ' . $location, true, 301);
        header('X-Robots-Tag: noindex, nofollow');
        exit;
    }

    public static function shouldProcessPath(string $path): bool
    {
        $normalized = SeoRedirectPath::normalize($path);
        $relative = $normalized;
        $base = url_base();
        if ($base !== '' && str_starts_with($relative, $base)) {
            $relative = substr($relative, strlen($base)) ?: '/';
        }
        $pathOnly = parse_url($relative, PHP_URL_PATH);
        if (!is_string($pathOnly) || $pathOnly === '') {
            $pathOnly = $relative;
        }
        $pathOnly = strtolower($pathOnly);
        if ($pathOnly === '' || $pathOnly === '/') {
            return true;
        }
        if ($pathOnly === '/robots.txt' || $pathOnly === '/robots.php') {
            return false;
        }
        if ($pathOnly === '/sitemap.xml' || $pathOnly === '/sitemap.php') {
            return false;
        }
        if (preg_match('#/sitemap-[0-9]+\\.xml$#', $pathOnly) === 1) {
            return false;
        }
        $skip = ['/admin', '/webhooks', '/cron', '/scripts', '/includes', '/database', '/storage', '/api'];
        foreach ($skip as $prefix) {
            if (str_starts_with($pathOnly, $prefix . '/') || $pathOnly === $prefix) {
                return false;
            }
        }

        return true;
    }

    /** Block redirects that would expose draft/private CMS content without normal checks (defense in depth). */
    public static function redirectDestinationBlocked(string $destPath): bool
    {
        if (!preg_match('#/page\.php\?slug=([^&]+)#', $destPath, $m)) {
            return false;
        }
        $slug = rawurldecode($m[1]);
        if ($slug === '' || !Validation::slug($slug) || !CmsPageService::schemaReady()) {
            return false;
        }
        $row = CmsPageRepository::findBySlug($slug);
        if ($row === null) {
            return false;
        }
        $status = (string) ($row['status'] ?? '');
        $visibility = (string) ($row['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC);
        if ($status !== PublishStatus::PUBLISHED) {
            return true;
        }
        if ($visibility === CmsPageService::VISIBILITY_PRIVATE) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{ok: true}|array{ok: false, message: string}
     */
    public static function registerCmsSlugChangeIfNeeded(array $before, array $after): array
    {
        $oldSlug = (string) ($before['slug'] ?? '');
        $newSlug = (string) ($after['slug'] ?? '');
        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return ['ok' => true];
        }
        $wasPublic = (string) ($before['status'] ?? '') === PublishStatus::PUBLISHED
            && (string) ($before['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC) === CmsPageService::VISIBILITY_PUBLIC;
        $isPublic = (string) ($after['status'] ?? '') === PublishStatus::PUBLISHED
            && (string) ($after['visibility'] ?? CmsPageService::VISIBILITY_PUBLIC) === CmsPageService::VISIBILITY_PUBLIC;
        if (!$wasPublic || !$isPublic) {
            return ['ok' => true];
        }

        $validated = self::validateSlugChangeRedirect(
            SeoRedirectPath::cmsPage($oldSlug),
            SeoRedirectPath::cmsPage($newSlug)
        );
        if (!$validated['ok']) {
            return $validated;
        }
        self::applySlugChangeRedirect($validated['from'], $validated['to']);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $before
     * @return array{ok: true}|array{ok: false, message: string}
     */
    public static function registerProductSlugChangeIfNeeded(array $before, string $newSlug, string $newStatus): array
    {
        $oldSlug = (string) ($before['slug'] ?? '');
        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return ['ok' => true];
        }
        if ((string) ($before['status'] ?? '') !== 'published' || $newStatus !== 'published') {
            return ['ok' => true];
        }

        $validated = self::validateSlugChangeRedirect(
            SeoRedirectPath::productPage($oldSlug),
            SeoRedirectPath::productPage($newSlug)
        );
        if (!$validated['ok']) {
            return $validated;
        }
        self::applySlugChangeRedirect($validated['from'], $validated['to']);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $before
     * @return array{ok: true}|array{ok: false, message: string}
     */
    public static function registerCategorySlugChangeIfNeeded(array $before, string $newSlug, string $newStatus): array
    {
        $oldSlug = (string) ($before['slug'] ?? '');
        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return ['ok' => true];
        }
        if ((string) ($before['status'] ?? '') !== 'published' || $newStatus !== 'published') {
            return ['ok' => true];
        }

        $validated = self::validateSlugChangeRedirect(
            SeoRedirectPath::categoryPage($oldSlug),
            SeoRedirectPath::categoryPage($newSlug)
        );
        if (!$validated['ok']) {
            return $validated;
        }
        self::applySlugChangeRedirect($validated['from'], $validated['to']);

        return ['ok' => true];
    }

    /**
     * Slug-change registration: collapse chains and update the source mapping to the latest URL.
     *
     * @return array{ok: true}|array{ok: false, message: string}
     */
    public static function registerPermanentSlugChange(string $fromPath, string $toPath): array
    {
        $fromVal = SeoRedirectPath::validateInternalPath($fromPath);
        if (!$fromVal['ok']) {
            return ['ok' => false, 'message' => $fromVal['message']];
        }
        $toVal = SeoRedirectPath::validateInternalPath($toPath);
        if (!$toVal['ok']) {
            return ['ok' => false, 'message' => $toVal['message']];
        }
        $from = $fromVal['path'];
        $to = $toVal['path'];
        if ($from === $to) {
            return ['ok' => false, 'message' => 'Self-redirect is not allowed.'];
        }
        $validated = self::validateSlugChangeRedirect($fromPath, $toPath);
        if (!$validated['ok']) {
            return $validated;
        }
        try {
            Database::transaction(static function () use ($validated): void {
                self::applySlugChangeRedirect($validated['from'], $validated['to']);
            });

            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('SeoRedirectService::registerPermanentSlugChange: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save redirect.'];
        }
    }

    /**
     * @return array{ok: true, from: string, to: string}|array{ok: false, message: string}
     */
    public static function validateSlugChangeRedirect(string $fromPath, string $toPath): array
    {
        $fromVal = SeoRedirectPath::validateInternalPath($fromPath);
        if (!$fromVal['ok']) {
            return ['ok' => false, 'message' => $fromVal['message']];
        }
        $toVal = SeoRedirectPath::validateInternalPath($toPath);
        if (!$toVal['ok']) {
            return ['ok' => false, 'message' => $toVal['message']];
        }
        $from = $fromVal['path'];
        $to = $toVal['path'];
        if ($from === $to) {
            return ['ok' => false, 'message' => 'Self-redirect is not allowed.'];
        }
        if (self::wouldCreateLoop($from, $to)) {
            return ['ok' => false, 'message' => 'Redirect would create a loop.'];
        }

        return ['ok' => true, 'from' => $from, 'to' => $to];
    }

    public static function applySlugChangeRedirect(string $from, string $to): void
    {
        SeoRedirectRepository::updateToPath($from, $to);
        SeoRedirectRepository::upsertPermanent($from, $to);
    }

    /** Build 301 Location URL; canonical destination plus safe request tracking params only. */
    public static function redirectLocationWithSafeTracking(string $canonicalDest, string $requestUri): string
    {
        $location = url_to($canonicalDest);
        $tracking = SeoRedirectPath::safeTrackingQueryForRedirect($requestUri);
        if ($tracking === '') {
            return $location;
        }

        return $location . (str_contains($canonicalDest, '?') ? '&' : '?') . $tracking;
    }
}
