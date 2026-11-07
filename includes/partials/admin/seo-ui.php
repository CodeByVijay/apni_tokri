<?php

declare(strict_types=1);

/** Shared SEO Management Center UI helpers (markup only). */
final class SeoAdminUi
{
    /** @return array<string, array{title: string, lead: string}> */
    public static function sectionIntros(): array
    {
        return [
            'dashboard' => [
                'title' => 'SEO Dashboard',
                'lead' => 'See how your shop looks to Google and what to improve next.',
            ],
            'setup' => [
                'title' => 'SEO Setup Assistant',
                'lead' => 'Step-by-step checklist for a new store owner — skip anytime and return later.',
            ],
            'catalog' => [
                'title' => 'Products & Categories',
                'lead' => 'Search titles and descriptions for every product and category page.',
            ],
            'pages' => [
                'title' => 'Pages SEO',
                'lead' => 'Homepage and content pages that appear in Google search.',
            ],
            'local' => [
                'title' => 'Local SEO',
                'lead' => 'Help customers in Indore find your business online.',
            ],
            'technical' => [
                'title' => 'Technical SEO',
                'lead' => 'Sitemap and robots.txt — how search engines crawl your site.',
            ],
            'google' => [
                'title' => 'Google Integrations',
                'lead' => 'Verify your site and connect Analytics (optional).',
            ],
            'redirects' => [
                'title' => 'Redirects & 404 Errors',
                'lead' => 'Fix broken links and guide visitors to the right pages.',
            ],
        ];
    }

    /** @var array<string, string> */
    public const NAV_SHORT = [
        'dashboard' => 'Dashboard',
        'setup' => 'Setup',
        'catalog' => 'Products',
        'pages' => 'Pages',
        'local' => 'Local',
        'technical' => 'Technical',
        'google' => 'Google',
        'redirects' => 'Redirects',
    ];

    public static function healthTone(int $score): string
    {
        if ($score >= 80) {
            return 'ok';
        }
        if ($score >= 50) {
            return 'warn';
        }

        return 'risk';
    }

    public static function severityClass(string $severity): string
    {
        return match ($severity) {
            'critical' => 'is-critical',
            'warning' => 'is-warn',
            default => 'is-info',
        };
    }

    public static function checkStateClass(bool $passed): string
    {
        return $passed ? 'is-pass' : 'is-warn';
    }
}
