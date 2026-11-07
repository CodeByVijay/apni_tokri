<?php

declare(strict_types=1);

final class HomepageTrustService
{
    public const SETTING_KEY = 'homepage.trust.config';

    /** @return array<string, mixed> */
    public static function defaultConfig(): array
    {
        return [
            'version' => 1,
            'sections' => [
                self::sectionDefaults('trust_strip', 10),
                self::sectionDefaults('why_features', 20),
                self::sectionDefaults('quality', 30),
                self::sectionDefaults('testimonials', 40),
                self::sectionDefaults('faq_support', 50),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function getConfig(): array
    {
        $defaults = self::defaultConfig();
        if (!Install::isSchemaReady()) {
            return $defaults;
        }
        $raw = AppSettings::get(self::SETTING_KEY, '');
        if ($raw === null || trim($raw) === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return self::mergeWithDefaults($decoded, $defaults);
    }

    /** @return list<array<string, mixed>> Enabled sections sorted for public render. */
    public static function publicSections(?string $placement = null): array
    {
        $sections = self::getConfig()['sections'] ?? [];
        if (!is_array($sections)) {
            return [];
        }
        $out = [];
        foreach ($sections as $section) {
            if (!is_array($section) || empty($section['enabled'])) {
                continue;
            }
            if ($placement !== null && (string) ($section['placement'] ?? '') !== $placement) {
                continue;
            }
            $normalized = self::normalizeSection($section);
            if ($normalized === null) {
                continue;
            }
            if ($normalized['id'] === 'testimonials') {
                $out[] = $normalized;
                continue;
            }
            if (!$normalized['has_content']) {
                continue;
            }
            $out[] = $normalized;
        }
        usort($out, static function (array $a, array $b): int {
        return ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
    });

        return $out;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: true}|array{ok: false, message: string, errors?: array<string, string>}
     */
    public static function saveFromRequest(array $post): array
    {
        $sections = [];
        $ids = ['trust_strip', 'why_features', 'quality', 'testimonials', 'faq_support'];
        foreach ($ids as $id) {
            $sections[] = self::parseSectionFromPost($id, $post);
        }
        $config = ['version' => 1, 'sections' => $sections];
        $errors = self::validateConfig($config);
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Validation failed.', 'errors' => $errors];
        }
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database not ready.'];
        }
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'message' => 'Could not encode configuration.'];
        }
        AppSettings::set(self::SETTING_KEY, $json);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public static function validateConfig(array $config): array
    {
        $errors = [];
        $sections = $config['sections'] ?? [];
        if (!is_array($sections)) {
            $errors['sections'] = 'Invalid sections.';

            return $errors;
        }
        $seen = [];
        foreach ($sections as $i => $section) {
            if (!is_array($section)) {
                $errors['section_' . $i] = 'Invalid section.';
                continue;
            }
            $id = (string) ($section['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                $errors['section_' . $id] = 'Duplicate or missing section id.';
            }
            $seen[$id] = true;
            if ($id === 'quality') {
                $img = (string) ($section['image_path'] ?? '');
                if ($img !== '' && !self::isAllowedImagePath($img)) {
                    $errors['quality_image'] = 'Invalid image path.';
                }
                $href = (string) ($section['cta_href'] ?? '');
                if ($href !== '' && !self::isAllowedInternalHref($href)) {
                    $errors['quality_cta'] = 'Invalid link.';
                }
            }
            if ($id === 'trust_strip' || $id === 'why_features') {
                foreach ($section['items'] ?? [] as $j => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $icon = trim((string) ($item['icon'] ?? ''));
                    if ($icon !== '' && HomepageTrustIcons::normalizeStoredId($icon) === null) {
                        $errors[$id . '_icon_' . $j] = 'Unsupported icon “' . $icon . '”. Choose an icon from the picker.';
                    }
                }
            }
        }

        return $errors;
    }

    public static function sanitizeText(?string $value, int $maxLen): string
    {
        $value = trim(strip_tags((string) ($value ?? '')));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr($value, 0, $maxLen);
    }

    /** @return array<string, mixed> */
    private static function sectionDefaults(string $id, int $sort): array
    {
        if ($id === 'trust_strip') {
            return [
                'id' => $id,
                'type' => 'trust_strip',
                'placement' => 'after_hero',
                'enabled' => true,
                'sort' => $sort,
                'items' => [
                    ['icon' => 'truck', 'title' => 'Fee shown upfront', 'subtitle' => 'No surprises at payment'],
                    ['icon' => 'leaf', 'title' => 'Fresh, daily', 'subtitle' => 'Handled with care'],
                    ['icon' => 'refresh', 'title' => 'Clear refunds', 'subtitle' => 'Simple, written policy'],
                    ['icon' => 'chat', 'title' => 'Local support', 'subtitle' => 'Real people in Indore'],
                ],
            ];
        }
        if ($id === 'why_features') {
            return [
                'id' => $id,
                'type' => 'why_features',
                'placement' => 'after_subscriptions',
                'enabled' => true,
                'sort' => $sort,
                'heading' => 'Why Veggie Tokri',
                'subtitle' => 'Honest basics, done properly.',
                'items' => [
                    ['icon' => 'receipt', 'title' => 'Transparent pricing', 'body' => 'Item price, delivery fee and discounts are itemised before you pay.'],
                    ['icon' => 'box', 'title' => 'Proper packing', 'body' => 'Fresh and cut produce is packed to arrive in good condition.'],
                    ['icon' => 'star', 'title' => 'Verified reviews only', 'body' => 'Reviews come from delivered orders and are approved before they appear.'],
                    ['icon' => 'lock', 'title' => 'No pressure tactics', 'body' => 'No fake countdowns, no invented stock levels or ratings.'],
                ],
            ];
        }
        if ($id === 'quality') {
            return [
                'id' => $id,
                'type' => 'quality',
                'placement' => 'after_subscriptions',
                'enabled' => true,
                'sort' => $sort,
                'eyebrow' => 'Quality & packing',
                'heading' => 'Checked by hand, packed with care',
                'body' => 'Every order is checked and packed according to our actual process. We update this section from the CMS before launch, and we never display unverified “organic” or “same-day” claims.',
                'bullets' => [
                    'Sorted for firmness and colour before packing',
                    'Cut produce kept chilled with a clear use-by on the pack',
                    'Packaged foods show ingredients and allergens as per the label',
                ],
                'image_path' => 'assets/images/demo/about-packing.jpg',
                'cta_label' => 'About Veggie Tokri',
                'cta_href' => '/page.php?slug=about-us',
            ];
        }
        if ($id === 'testimonials') {
            return [
                'id' => $id,
                'type' => 'testimonials',
                'placement' => 'after_subscriptions',
                'enabled' => true,
                'sort' => $sort,
                'heading' => 'What customers say',
                'subtitle' => 'Featured stories from customers who gave consent — not paid or fabricated reviews.',
            ];
        }

        return [
            'id' => 'faq_support',
            'type' => 'faq_support',
            'placement' => 'after_subscriptions',
            'enabled' => true,
            'sort' => $sort,
            'eyebrow' => 'FAQs',
            'heading' => 'Questions, answered',
            'body' => 'Delivery, payments, reviews and refunds — the short answers. Still stuck? We are one message away.',
            'faq_limit' => 5,
        ];
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $defaults */
    private static function mergeWithDefaults(array $stored, array $defaults): array
    {
        $defaultById = [];
        foreach ($defaults['sections'] as $section) {
            $defaultById[(string) $section['id']] = $section;
        }
        $merged = [];
        $storedSections = $stored['sections'] ?? [];
        if (!is_array($storedSections)) {
            $storedSections = [];
        }
        foreach ($storedSections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $id = (string) ($section['id'] ?? '');
            if ($id === '' || !isset($defaultById[$id])) {
                continue;
            }
            $combined = array_replace($defaultById[$id], $section);
            if (isset($section['items']) && is_array($section['items'])) {
                $combined['items'] = $section['items'];
            }
            if (isset($section['bullets']) && is_array($section['bullets'])) {
                $combined['bullets'] = $section['bullets'];
            }
            $merged[] = $combined;
        }
        foreach ($defaultById as $id => $section) {
            $found = false;
            foreach ($merged as $m) {
                if (($m['id'] ?? '') === $id) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $merged[] = $section;
            }
        }

        return ['version' => 1, 'sections' => $merged];
    }

    /** @param array<string, mixed> $section */
    private static function normalizeSection(array $section): ?array
    {
        $id = (string) ($section['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $section['sort'] = (int) ($section['sort'] ?? 0);
        $section['has_content'] = self::sectionHasContent($section);

        return $section;
    }

    /** @param array<string, mixed> $section */
    private static function sectionHasContent(array $section): bool
    {
        $type = (string) ($section['type'] ?? $section['id'] ?? '');
        if ($type === 'trust_strip' || $type === 'why_features') {
            foreach ($section['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (self::sanitizeText($item['title'] ?? '', 120) !== '') {
                    return true;
                }
            }

            return false;
        }
        if ($type === 'quality') {
            return self::sanitizeText($section['heading'] ?? '', 191) !== '';
        }
        if ($type === 'testimonials') {
            return self::sanitizeText($section['heading'] ?? '', 191) !== '';
        }
        if ($type === 'faq_support') {
            return self::sanitizeText($section['heading'] ?? '', 191) !== '';
        }

        return false;
    }

    /** @param array<string, mixed> $post */
    private static function parseSectionFromPost(string $id, array $post): array
    {
        $base = self::sectionDefaults($id, (int) ($post['sort_' . $id] ?? 0));
        $base['enabled'] = !empty($post['enabled_' . $id]);
        $base['sort'] = max(0, min(999, (int) ($post['sort_' . $id] ?? $base['sort'])));
        if ($id === 'trust_strip') {
            $items = [];
            for ($i = 0; $i < 4; $i++) {
                $iconRaw = self::sanitizeText($post['trust_strip_icon_' . $i] ?? '', 32);
                $icon = HomepageTrustIcons::normalizeStoredId($iconRaw) ?? $iconRaw;
                $items[] = [
                    'icon' => $icon,
                    'title' => self::sanitizeText($post['trust_strip_title_' . $i] ?? '', 120),
                    'subtitle' => self::sanitizeText($post['trust_strip_subtitle_' . $i] ?? '', 160),
                ];
            }
            $base['items'] = $items;

            return $base;
        }
        if ($id === 'why_features') {
            $items = [];
            for ($i = 0; $i < 4; $i++) {
                $iconRaw = self::sanitizeText($post['why_features_icon_' . $i] ?? '', 32);
                $icon = HomepageTrustIcons::normalizeStoredId($iconRaw) ?? $iconRaw;
                $items[] = [
                    'icon' => $icon,
                    'title' => self::sanitizeText($post['why_features_title_' . $i] ?? '', 120),
                    'body' => self::sanitizeText($post['why_features_body_' . $i] ?? '', 500),
                ];
            }
            $base['items'] = $items;
            $base['heading'] = self::sanitizeText($post['why_heading'] ?? '', 191);
            $base['subtitle'] = self::sanitizeText($post['why_subtitle'] ?? '', 255);

            return $base;
        }
        if ($id === 'quality') {
            $base['eyebrow'] = self::sanitizeText($post['quality_eyebrow'] ?? '', 120);
            $base['heading'] = self::sanitizeText($post['quality_heading'] ?? '', 191);
            $base['body'] = self::sanitizeText($post['quality_body'] ?? '', 2000);
            $bullets = [];
            for ($i = 0; $i < 5; $i++) {
                $b = self::sanitizeText($post['quality_bullet_' . $i] ?? '', 255);
                if ($b !== '') {
                    $bullets[] = $b;
                }
            }
            $base['bullets'] = $bullets;
            $base['image_path'] = self::sanitizeText($post['quality_image_path'] ?? '', 255);
            $base['cta_label'] = self::sanitizeText($post['quality_cta_label'] ?? '', 120);
            $base['cta_href'] = self::sanitizeText($post['quality_cta_href'] ?? '', 255);

            return $base;
        }
        if ($id === 'testimonials') {
            $base['heading'] = self::sanitizeText($post['testimonials_heading'] ?? '', 191);
            $base['subtitle'] = self::sanitizeText($post['testimonials_subtitle'] ?? '', 500);

            return $base;
        }
        $base['eyebrow'] = self::sanitizeText($post['faq_eyebrow'] ?? '', 120);
        $base['heading'] = self::sanitizeText($post['faq_heading'] ?? '', 191);
        $base['body'] = self::sanitizeText($post['faq_body'] ?? '', 2000);
        $base['faq_limit'] = max(1, min(10, (int) ($post['faq_limit'] ?? 5)));

        return $base;
    }

    private static function isAllowedImagePath(string $path): bool
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return false;
        }

        return preg_match('#^assets/images/[a-zA-Z0-9/_.-]+$#', $path) === 1;
    }

    private static function isAllowedInternalHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^https?://#i', $href) || str_starts_with($href, '//')) {
            return false;
        }
        if (!str_starts_with($href, '/')) {
            $href = '/' . $href;
        }

        return SeoUrl::validatePublicUrl(SeoUrl::absolute($href)) !== null;
    }
}
