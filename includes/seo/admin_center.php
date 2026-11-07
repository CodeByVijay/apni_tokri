<?php

declare(strict_types=1);

/**
 * Admin SEO Management Center — dashboard metrics, settings, templates, wizard progress.
 */
final class SeoAdminCenter
{
    public const MODE_SIMPLE = 'simple';
    public const MODE_ADVANCED = 'advanced';

    public const KEY_UI_MODE = 'seo.center.ui_mode';
    public const KEY_WIZARD_JSON = 'seo.center.wizard_progress';
    public const KEY_ROBOTS_EXTRA = 'seo.center.robots_extra';
    public const KEY_GSC_VERIFY = 'seo.center.gsc_verify_meta';
    public const KEY_GSC_PROPERTY = 'seo.center.gsc_property_url';
    public const KEY_GA4_ID = 'seo.center.ga4_measurement_id';
    public const KEY_GTM_ID = 'seo.center.gtm_container_id';
    public const KEY_GTM_ENABLED = 'seo.center.gtm_enabled';
    public const KEY_TEMPLATE_PRODUCT_TITLE = 'seo.template.product_title';
    public const KEY_TEMPLATE_PRODUCT_DESC = 'seo.template.product_description';
    public const KEY_TEMPLATE_CATEGORY_TITLE = 'seo.template.category_title';
    public const KEY_TEMPLATE_CATEGORY_DESC = 'seo.template.category_description';
    public const KEY_LOCAL_SERVICE_AREA = 'seo.local.service_area';
    public const KEY_LOCAL_HOURS = 'seo.local.business_hours';

    public const DEFAULT_PRODUCT_TITLE = '{product_name} Online in {city} | {brand_name}';
    public const DEFAULT_PRODUCT_DESC = 'Order {product_name} online in {city}. Shop fresh quality products from {brand_name}.';
    public const DEFAULT_CATEGORY_TITLE = '{category_name} — {brand_name}';
    public const DEFAULT_CATEGORY_DESC = 'Browse {category_name} at {brand_name}. Fresh delivery in {city}.';

    /** @var list<string> */
    public const SECTIONS = [
        'dashboard' => 'SEO Dashboard',
        'setup' => 'SEO Setup Assistant',
        'catalog' => 'Products & Categories',
        'pages' => 'Pages SEO',
        'local' => 'Local SEO',
        'technical' => 'Technical SEO',
        'google' => 'Google Integrations',
        'redirects' => 'Redirects & 404 Errors',
    ];

    public static function uiMode(): string
    {
        if (isset($_SESSION['seo_center_ui_mode']) && is_string($_SESSION['seo_center_ui_mode'])) {
            $m = $_SESSION['seo_center_ui_mode'];
            if ($m === self::MODE_ADVANCED) {
                return self::MODE_ADVANCED;
            }
        }
        $stored = AppSettings::get(self::KEY_UI_MODE, '') ?? '';
        if ($stored === self::MODE_ADVANCED) {
            return self::MODE_ADVANCED;
        }

        return self::MODE_SIMPLE;
    }

    public static function setUiMode(string $mode): void
    {
        $mode = $mode === self::MODE_ADVANCED ? self::MODE_ADVANCED : self::MODE_SIMPLE;
        $_SESSION['seo_center_ui_mode'] = $mode;
        AppSettings::set(self::KEY_UI_MODE, $mode);
    }

    public static function isSimple(): bool
    {
        return self::uiMode() !== self::MODE_ADVANCED;
    }

    public static function sectionUrl(string $section): string
    {
        $section = self::normalizeSection($section);

        return url_base() . '/admin/seo/index.php?section=' . rawurlencode($section);
    }

    public static function normalizeSection(?string $section): string
    {
        $section = strtolower(trim((string) $section));

        return isset(self::SECTIONS[$section]) ? $section : 'dashboard';
    }

    /** @return array<string, bool> */
    public static function wizardProgress(): array
    {
        $raw = AppSettings::get(self::KEY_WIZARD_JSON, '') ?? '';
        if ($raw === '') {
            return self::defaultWizardProgress();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::defaultWizardProgress();
        }
        $base = self::defaultWizardProgress();
        foreach ($base as $k => $_) {
            if (array_key_exists($k, $decoded)) {
                $base[$k] = !empty($decoded[$k]);
            }
        }

        return $base;
    }

    /** @return array<string, bool> */
    private static function defaultWizardProgress(): array
    {
        return [
            'identity' => false,
            'business' => false,
            'homepage' => false,
            'products' => false,
            'categories' => false,
            'gsc' => false,
            'sitemap' => false,
            'done' => false,
        ];
    }

    /** @param array<string, bool> $progress */
    public static function saveWizardProgress(array $progress): void
    {
        $out = self::defaultWizardProgress();
        foreach ($out as $k => $_) {
            if (array_key_exists($k, $progress)) {
                $out[$k] = !empty($progress[$k]);
            }
        }
        AppSettings::set(self::KEY_WIZARD_JSON, json_encode($out, JSON_UNESCAPED_UNICODE));
    }

    public static function markWizardStep(string $step, bool $done = true): void
    {
        $p = self::wizardProgress();
        if (array_key_exists($step, $p)) {
            $p[$step] = $done;
            self::saveWizardProgress($p);
        }
    }

    /**
     * Transparent health report for the dashboard (no guaranteed ranking claims).
     *
     * @return array{
     *   score: int,
     *   checks: list<array{id: string, label: string, passed: bool, weight: int, detail: string}>,
     *   recommendations: list<array{severity: string, message: string, section: string, action_label?: string}>,
     *   counts: array<string, int|string>
     * }
     */
    public static function healthReport(): array
    {
        $counts = self::catalogCounts();
        $checks = [];
        $add = static function (string $id, string $label, bool $passed, int $weight, string $detail) use (&$checks): void {
            $checks[] = ['id' => $id, 'label' => $label, 'passed' => $passed, 'weight' => $weight, 'detail' => $detail];
        };

        $home = SeoResolver::forHomepage();
        $homeTitle = trim($home->documentTitle);
        $homeDesc = trim((string) ($home->metaDescription ?? ''));
        $add('home_title', 'Homepage has a search title', $homeTitle !== '', 10, $homeTitle !== '' ? 'Title is set.' : 'Add a homepage search title under Pages SEO.');
        $add('home_desc', 'Homepage has a meta description', $homeDesc !== '', 10, $homeDesc !== '' ? 'Description is set.' : 'Add a short summary for Google under Pages SEO.');

        $add('products_title', 'Published products have SEO titles', (int) $counts['products_missing_title'] === 0, 15,
            (int) $counts['products_missing_title'] === 0
                ? 'All published products have a custom SEO title or use the product name.'
                : (string) $counts['products_missing_title'] . ' product(s) could use an SEO title.');
        $add('products_desc', 'Published products have meta descriptions', (int) $counts['products_missing_description'] === 0, 15,
            (int) $counts['products_missing_description'] === 0
                ? 'All published products have a description for search.'
                : (string) $counts['products_missing_description'] . ' product(s) are missing descriptions.');

        $add('categories_title', 'Categories have SEO titles', (int) $counts['categories_missing_title'] === 0, 10,
            (int) $counts['categories_missing_title'] . ' categor(ies) missing custom titles.');
        $add('categories_desc', 'Categories have meta descriptions', (int) $counts['categories_missing_description'] === 0, 10,
            (int) $counts['categories_missing_description'] . ' categor(ies) missing descriptions.');

        $cmsMissing = (int) $counts['cms_missing_meta'];
        $add('pages_meta', 'Public CMS pages have SEO metadata', $cmsMissing === 0, 10,
            $cmsMissing === 0 ? 'Public pages have titles and descriptions.' : $cmsMissing . ' public page(s) need SEO text.');

        $sitemapCount = 0;
        try {
            $sitemapCount = count(SeoSitemap::collectIndexableUrls());
        } catch (Throwable $e) {
            $sitemapCount = 0;
        }
        $add('sitemap', 'XML sitemap has indexable URLs', $sitemapCount > 0, 15,
            $sitemapCount > 0 ? $sitemapCount . ' URL(s) in sitemap.' : 'Sitemap is empty or not available yet.');

        $robotsOk = SeoRobots::disallowsPublicCataloguePaths();
        $add('robots_safe', 'robots.txt does not block the shop', $robotsOk, 10,
            $robotsOk ? 'Product and category URLs are not disallowed.' : 'robots.txt may be blocking public pages.');

        $dupTitles = (int) $counts['duplicate_product_titles'];
        $add('dup_titles', 'No duplicate product SEO titles', $dupTitles === 0, 5,
            $dupTitles === 0 ? 'No duplicate custom product titles detected.' : $dupTitles . ' duplicate product title(s).');

        $noindexRisk = (int) $counts['products_noindex'];
        $add('product_index', 'Published products allow indexing', $noindexRisk === 0, 5,
            $noindexRisk === 0 ? 'No published products are set to noindex.' : $noindexRisk . ' product(s) are noindex.');

        $weightTotal = 0;
        $weightPassed = 0;
        foreach ($checks as $c) {
            $weightTotal += (int) $c['weight'];
            if ($c['passed']) {
                $weightPassed += (int) $c['weight'];
            }
        }
        $score = $weightTotal > 0 ? (int) round(($weightPassed / $weightTotal) * 100) : 0;

        $recommendations = [];
        if ((int) $counts['products_missing_description'] > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => (int) $counts['products_missing_description'] . ' products do not have SEO descriptions.',
                'section' => 'catalog',
                'action_label' => 'Review products',
            ];
        }
        if ((int) $counts['categories_missing_title'] > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => (int) $counts['categories_missing_title'] . ' categories are missing search titles.',
                'section' => 'catalog',
                'action_label' => 'Review categories',
            ];
        }
        if ($sitemapCount === 0) {
            $recommendations[] = [
                'severity' => 'critical',
                'message' => 'Your XML sitemap has no indexable URLs yet.',
                'section' => 'technical',
                'action_label' => 'Check sitemap',
            ];
        }
        if (!self::gscConfigured()) {
            $recommendations[] = [
                'severity' => 'info',
                'message' => 'Google Search Console is not configured yet.',
                'section' => 'google',
                'action_label' => 'Configure',
            ];
        }
        if (SeoNotFoundLog::countActive() > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => SeoNotFoundLog::countActive() . ' broken URL(s) were recently requested.',
                'section' => 'redirects',
                'action_label' => 'Review 404s',
            ];
        }

        $redirectTotal = 0;
        if (Install::isSchemaReady()) {
            try {
                $redirectTotal = (int) Database::pdo()->query('SELECT COUNT(*) FROM seo_redirects')->fetchColumn();
            } catch (Throwable $e) {
                $redirectTotal = 0;
            }
        }

        $counts['redirects'] = $redirectTotal;
        $counts['sitemap_urls'] = $sitemapCount;
        $counts['health_score'] = $score;
        $counts['gsc_status'] = self::gscConfigured() ? 'Configured' : 'Not configured yet';
        $counts['ga4_status'] = self::ga4Configured() ? 'Configured' : 'Not configured yet';

        return [
            'score' => $score,
            'checks' => $checks,
            'recommendations' => $recommendations,
            'counts' => $counts,
        ];
    }

    /** @return array<string, int> */
    public static function catalogCounts(): array
    {
        $out = [
            'products_published' => 0,
            'products_missing_title' => 0,
            'products_missing_description' => 0,
            'products_noindex' => 0,
            'categories_published' => 0,
            'categories_missing_title' => 0,
            'categories_missing_description' => 0,
            'cms_public' => 0,
            'cms_missing_meta' => 0,
            'duplicate_product_titles' => 0,
        ];
        if (!Install::isSchemaReady()) {
            return $out;
        }
        try {
            $pdo = Database::pdo();
            $out['products_published'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM products WHERE status = 'published'"
            )->fetchColumn();
            $out['products_missing_title'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM products WHERE status = 'published' AND TRIM(COALESCE(meta_title,'')) = ''"
            )->fetchColumn();
            $out['products_missing_description'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM products WHERE status = 'published'
                 AND TRIM(COALESCE(meta_description,'')) = '' AND TRIM(COALESCE(short_description,'')) = '' AND TRIM(COALESCE(description,'')) = ''"
            )->fetchColumn();
            $out['products_noindex'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM products WHERE status = 'published' AND robots_index = 0"
            )->fetchColumn();
            $out['categories_published'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM categories WHERE status = 'published'"
            )->fetchColumn();
            $out['categories_missing_title'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM categories WHERE status = 'published' AND TRIM(COALESCE(meta_title,'')) = ''"
            )->fetchColumn();
            $out['categories_missing_description'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM categories WHERE status = 'published' AND TRIM(COALESCE(meta_description,'')) = '' AND TRIM(COALESCE(description,'')) = ''"
            )->fetchColumn();
            $cmsPub = $pdo->prepare(
                'SELECT COUNT(*) FROM cms_pages WHERE status = ? AND (visibility = ? OR visibility IS NULL OR visibility = ?)'
            );
            $cmsPub->execute([PublishStatus::PUBLISHED, CmsPageService::VISIBILITY_PUBLIC, '']);
            $out['cms_public'] = (int) $cmsPub->fetchColumn();
            $cmsMiss = $pdo->prepare(
                'SELECT COUNT(*) FROM cms_pages WHERE status = ? AND (visibility = ? OR visibility IS NULL OR visibility = ?)
                 AND (TRIM(COALESCE(meta_title,\'\')) = \'\' OR TRIM(COALESCE(meta_description,\'\')) = \'\')'
            );
            $cmsMiss->execute([PublishStatus::PUBLISHED, CmsPageService::VISIBILITY_PUBLIC, '']);
            $out['cms_missing_meta'] = (int) $cmsMiss->fetchColumn();
            $dup = $pdo->query(
                "SELECT COUNT(*) FROM (
                    SELECT meta_title FROM products WHERE status = 'published' AND TRIM(meta_title) != ''
                    GROUP BY meta_title HAVING COUNT(*) > 1
                ) t"
            )->fetchColumn();
            $out['duplicate_product_titles'] = (int) $dup;
        } catch (Throwable $e) {
            error_log('SeoAdminCenter::catalogCounts: ' . $e->getMessage());
        }

        return $out;
    }

    public static function template(string $which): string
    {
        $map = [
            'product_title' => self::KEY_TEMPLATE_PRODUCT_TITLE,
            'product_desc' => self::KEY_TEMPLATE_PRODUCT_DESC,
            'category_title' => self::KEY_TEMPLATE_CATEGORY_TITLE,
            'category_desc' => self::KEY_TEMPLATE_CATEGORY_DESC,
        ];
        $defaults = [
            'product_title' => self::DEFAULT_PRODUCT_TITLE,
            'product_desc' => self::DEFAULT_PRODUCT_DESC,
            'category_title' => self::DEFAULT_CATEGORY_TITLE,
            'category_desc' => self::DEFAULT_CATEGORY_DESC,
        ];
        if (!isset($map[$which])) {
            return '';
        }
        $v = trim(AppSettings::get($map[$which], '') ?? '');

        return $v !== '' ? $v : ($defaults[$which] ?? '');
    }

    public static function applyTemplateTokens(string $template, array $tokens): string
    {
        $city = BusinessSettings::city() ?? 'Indore';
        $brand = BrandingSettings::siteName();
        $defaults = [
            '{city}' => $city,
            '{brand_name}' => $brand,
            '{product_name}' => '',
            '{category_name}' => '',
        ];
        $tokens = array_merge($defaults, $tokens);
        $out = $template;
        foreach ($tokens as $k => $v) {
            $out = str_replace($k, (string) $v, $out);
        }

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }

    /**
     * @param list<int> $productIds
     * @return array{ok: bool, updated: int, message?: string}
     */
    public static function applyProductTemplateToIds(array $productIds, bool $onlyFillBlanks): array
    {
        if (!Install::isSchemaReady() || $productIds === []) {
            return ['ok' => false, 'updated' => 0, 'message' => 'No products selected.'];
        }
        $titleTpl = self::template('product_title');
        $descTpl = self::template('product_desc');
        $pdo = Database::pdo();
        $updated = 0;
        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $stmt = $pdo->prepare('SELECT id, name, meta_title, meta_description, short_description, description FROM products WHERE id = ? AND status = ?');
            $stmt->execute([$pid, 'published']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            $name = (string) ($row['name'] ?? 'Product');
            $newTitle = self::applyTemplateTokens($titleTpl, ['{product_name}' => $name]);
            $newDesc = self::applyTemplateTokens($descTpl, ['{product_name}' => $name]);
            $curTitle = trim((string) ($row['meta_title'] ?? ''));
            $curDesc = trim((string) ($row['meta_description'] ?? ''));
            if ($onlyFillBlanks) {
                if ($curTitle !== '') {
                    $newTitle = $curTitle;
                }
                if ($curDesc !== '') {
                    $newDesc = $curDesc;
                }
            }
            if ($newTitle === $curTitle && $newDesc === $curDesc) {
                continue;
            }
            $pdo->prepare('UPDATE products SET meta_title = ?, meta_description = ?, updated_at = NOW() WHERE id = ?')
                ->execute([mb_substr($newTitle, 0, 191), mb_substr($newDesc, 0, 320), $pid]);
            $updated++;
        }

        return ['ok' => true, 'updated' => $updated];
    }

    public static function gscConfigured(): bool
    {
        return trim(AppSettings::get(self::KEY_GSC_VERIFY, '') ?? '') !== ''
            || trim(AppSettings::get(self::KEY_GSC_PROPERTY, '') ?? '') !== '';
    }

    public static function ga4Configured(): bool
    {
        return self::normalizeGa4Id(AppSettings::get(self::KEY_GA4_ID, '') ?? '') !== null;
    }

    public static function gtmEnabled(): bool
    {
        return AppSettings::get(self::KEY_GTM_ENABLED, '') === '1'
            && self::normalizeGtmId(AppSettings::get(self::KEY_GTM_ID, '') ?? '') !== null;
    }

    public static function gtmContainerId(): ?string
    {
        return self::normalizeGtmId(AppSettings::get(self::KEY_GTM_ID, '') ?? '');
    }

    public static function ga4MeasurementId(): ?string
    {
        return self::normalizeGa4Id(AppSettings::get(self::KEY_GA4_ID, '') ?? '');
    }

    public static function gscVerificationMeta(): ?string
    {
        $content = trim(AppSettings::get(self::KEY_GSC_VERIFY, '') ?? '');
        if ($content === '') {
            return null;
        }
        if (preg_match('/^[a-zA-Z0-9_\-]+$/', $content) !== 1) {
            return null;
        }

        return $content;
    }

    public static function normalizeGa4Id(?string $raw): ?string
    {
        $raw = strtoupper(trim((string) $raw));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^G-[A-Z0-9]{6,12}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    public static function normalizeGtmId(?string $raw): ?string
    {
        $raw = strtoupper(trim((string) $raw));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^GTM-[A-Z0-9]{4,12}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveHomepageFromPost(array $post): array
    {
        $title = trim((string) ($post['home_meta_title'] ?? ''));
        $desc = trim((string) ($post['home_meta_description'] ?? ''));
        $index = !empty($post['home_robots_index']) ? '1' : '0';
        $errors = [];
        if ($title !== '' && mb_strlen($title) > 70) {
            $errors['home_meta_title'] = 'Keep the search title around 70 characters.';
        }
        if ($desc !== '' && mb_strlen($desc) > 320) {
            $errors['home_meta_description'] = 'Keep the summary around 320 characters.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }
        AppSettings::set('seo.home.meta_title', $title !== '' ? $title : null);
        AppSettings::set('seo.home.meta_description', $desc !== '' ? $desc : null);
        AppSettings::set('seo.home.robots_index', $index);
        self::markWizardStep('homepage', $title !== '' && $desc !== '');

        return ['ok' => true, 'message' => 'Homepage SEO saved.'];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveGoogleFromPost(array $post): array
    {
        $verify = trim((string) ($post['gsc_verify_meta'] ?? ''));
        $property = trim((string) ($post['gsc_property_url'] ?? ''));
        $ga4 = trim((string) ($post['ga4_measurement_id'] ?? ''));
        $gtm = trim((string) ($post['gtm_container_id'] ?? ''));
        $gtmOn = !empty($post['gtm_enabled']);
        $errors = [];
        if ($verify !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $verify) !== 1) {
            $errors['gsc_verify_meta'] = 'Paste only the verification code from the meta tag content value.';
        }
        if ($property !== '') {
            $prop = SeoUrl::validatePublicUrl($property);
            if ($prop === null) {
                $errors['gsc_property_url'] = 'Enter a valid https:// URL for your Search Console property.';
            } else {
                $property = $prop;
            }
        }
        if ($ga4 !== '' && self::normalizeGa4Id($ga4) === null) {
            $errors['ga4_measurement_id'] = 'Use a valid GA4 Measurement ID (example: G-XXXXXXXX).';
        }
        if ($gtm !== '' && self::normalizeGtmId($gtm) === null) {
            $errors['gtm_container_id'] = 'Use a valid GTM container ID (example: GTM-XXXX).';
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }
        AppSettings::set(self::KEY_GSC_VERIFY, $verify !== '' ? $verify : null);
        AppSettings::set(self::KEY_GSC_PROPERTY, $property !== '' ? $property : null);
        AppSettings::set(self::KEY_GA4_ID, $ga4 !== '' ? (self::normalizeGa4Id($ga4) ?? $ga4) : null);
        AppSettings::set(self::KEY_GTM_ID, $gtm !== '' ? (self::normalizeGtmId($gtm) ?? $gtm) : null);
        AppSettings::set(self::KEY_GTM_ENABLED, $gtmOn && $gtm !== '' ? '1' : '0');
        self::markWizardStep('gsc', $verify !== '' || $property !== '');

        return ['ok' => true, 'message' => 'Google settings saved.'];
    }

    /**
     * Validates extra robots lines (User-agent / Disallow / Allow only).
     *
     * @return array{ok: bool, message?: string, lines?: string}
     */
    public static function validateRobotsExtra(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", trim($raw));
        if ($raw === '') {
            return ['ok' => true, 'lines' => ''];
        }
        $danger = false;
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(User-agent|Disallow|Allow):\s*.+/i', $line) !== 1) {
                return ['ok' => false, 'message' => 'Only User-agent, Disallow, and Allow lines are supported in the extra block.'];
            }
            if (preg_match('/^Disallow:\s*\/\s*$/i', $line)) {
                $danger = true;
            }
        }
        if ($danger) {
            return ['ok' => false, 'message' => 'Disallow: / would block the entire website. Remove that line.'];
        }

        return ['ok' => true, 'lines' => $raw];
    }

    public static function searchPreview(string $title, string $desc, string $url): string
    {
        $title = $title !== '' ? $title : BrandingSettings::defaultSeoTitle();
        $desc = $desc !== '' ? $desc : 'Add a short summary that may appear below your title in Google results.';

        return '<div class="at-seo-preview"><p class="at-seo-preview__title">' . Security::escape($title)
            . '</p><p class="at-seo-preview__url">' . Security::escape($url)
            . '</p><p class="at-seo-preview__desc">' . Security::escape(mb_substr($desc, 0, 160))
            . (mb_strlen($desc) > 160 ? '…' : '') . '</p></div>';
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string}
     */
    public static function saveTemplatesFromPost(array $post): array
    {
        foreach ([
            'product_title' => self::KEY_TEMPLATE_PRODUCT_TITLE,
            'product_desc' => self::KEY_TEMPLATE_PRODUCT_DESC,
            'category_title' => self::KEY_TEMPLATE_CATEGORY_TITLE,
            'category_desc' => self::KEY_TEMPLATE_CATEGORY_DESC,
        ] as $field => $key) {
            $v = trim((string) ($post[$field] ?? ''));
            AppSettings::set($key, $v !== '' ? mb_substr($v, 0, 320) : null);
        }

        return ['ok' => true, 'message' => 'SEO templates saved.'];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string}
     */
    public static function saveLocalFromPost(array $post): array
    {
        $area = trim((string) ($post['local_service_area'] ?? ''));
        $hours = trim((string) ($post['local_business_hours'] ?? ''));
        AppSettings::set(self::KEY_LOCAL_SERVICE_AREA, $area !== '' ? mb_substr($area, 0, 500) : null);
        AppSettings::set(self::KEY_LOCAL_HOURS, $hours !== '' ? mb_substr($hours, 0, 2000) : null);
        $hasBiz = trim(BusinessSettings::addressLine() ?? '') !== '' && trim(BusinessSettings::city() ?? '') !== '';
        self::markWizardStep('business', $hasBiz);

        return ['ok' => true, 'message' => 'Local SEO notes saved. Update business address under Settings → Business Profile.'];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function persistRobotsExtra(string $raw): array
    {
        $check = self::validateRobotsExtra($raw);
        if (empty($check['ok'])) {
            return ['ok' => false, 'message' => (string) ($check['message'] ?? 'Invalid robots rules.')];
        }
        AppSettings::set(self::KEY_ROBOTS_EXTRA, ($check['lines'] ?? '') !== '' ? $check['lines'] : null);

        return ['ok' => true, 'message' => 'Extra robots.txt rules saved.'];
    }
}
