<?php

declare(strict_types=1);

/**
 * Meta Pixel (browser-side) tracking orchestration.
 *
 * Phase 2 scope: builds consent-aware event payloads and the bootstrap config
 * consumed by assets/js/meta-events.js. The pixel script itself is injected
 * client-side only when MetaAdsSettings::pixelReady() AND the environment
 * policy allows it AND MetaConsent::marketingAllowed() — enforced both here
 * (no pixel ID is rendered when not configured) and in JS (consent re-checked
 * at runtime before fbevents.js is ever requested).
 */
final class MetaPixel
{
    public const CURRENCY = 'INR';

    /** Events queued during page render, emitted via the bootstrap config. */
    private static array $pendingEvents = [];

    /**
     * Dev-environment hosts where browser tracking is additionally permitted.
     * This project deliberately runs its remote staging server with
     * app.env=development — env alone cannot tell staging from a local dev
     * machine, so the trusted configured canonical URL decides.
     */
    private const TRACKING_ALLOWED_DEV_HOSTS = [
        'apnitokri.manconnect.club',
    ];

    /**
     * Environment policy for browser tracking.
     *
     * - staging/production envs: allowed (effective activation still requires
     *   the admin enable flag, a valid Pixel ID and marketing consent).
     * - development envs (local/development/dev): allowed ONLY when the
     *   trusted configured canonical URL — BusinessSettings::storefrontBaseUrl(),
     *   which intentionally never derives origin from the HTTP Host header —
     *   resolves to an authorized staging host. Local machines and arbitrary
     *   development hosts therefore stay blocked even when they share the
     *   same app.env value as the staging server.
     */
    public static function environmentAllowed(): bool
    {
        $env = AppEnvironment::env();
        if (!in_array($env, ['local', 'development', 'dev'], true)) {
            return true;
        }
        $host = strtolower((string) parse_url(BusinessSettings::storefrontBaseUrl(), PHP_URL_HOST));

        return in_array($host, self::TRACKING_ALLOWED_DEV_HOSTS, true);
    }

    /** Pixel configured for this environment (consent evaluated separately). */
    public static function configured(): bool
    {
        return MetaAdsSettings::pixelReady() && self::environmentAllowed();
    }

    /** Everything required for the pixel to actually load right now. */
    public static function browserAllowedNow(): bool
    {
        return self::configured() && MetaConsent::marketingAllowed();
    }

    /** @param array<string, mixed>|null $options e.g. ['eventID' => ...] */
    public static function queueEvent(string $name, array $params = [], ?array $options = null): void
    {
        $event = ['name' => $name, 'params' => $params];
        if ($options !== null && $options !== []) {
            $event['options'] = $options;
        }
        self::$pendingEvents[] = $event;
    }

    /** ViewContent — product detail page. */
    public static function queueViewContent(array $product, ?array $variant): void
    {
        $params = [
            'content_ids' => [self::contentId($product, $variant)],
            'content_type' => 'product',
            'content_name' => (string) ($product['name'] ?? ''),
            'currency' => self::CURRENCY,
        ];
        $price = self::displayPrice($variant);
        if ($price !== null) {
            $params['value'] = $price;
        }
        self::queueEvent('ViewContent', $params);
    }

    /** Search — only for a real (non-empty) search term. */
    public static function queueSearch(string $searchTerm): void
    {
        $searchTerm = mb_substr(trim($searchTerm), 0, 100);
        if ($searchTerm === '') {
            return;
        }
        self::queueEvent('Search', ['search_string' => $searchTerm]);
    }

    /**
     * InitiateCheckout — fired once when a customer lands on checkout with a
     * valid (non-empty) cart. Caller passes the resolved cart lines + totals.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $totals
     */
    public static function queueInitiateCheckout(array $items, array $totals): void
    {
        if ($items === []) {
            return;
        }
        $contents = [];
        $contentIds = [];
        $numItems = 0;
        foreach ($items as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $id = self::cartLineContentId($item);
            if ($id === '') {
                continue;
            }
            $contentIds[] = $id;
            $line = ['id' => $id, 'quantity' => $qty];
            $price = $item['unit_price'] ?? null;
            if (is_numeric($price)) {
                $line['item_price'] = (float) $price;
            }
            $contents[] = $line;
            $numItems += $qty;
        }
        if ($contentIds === []) {
            return;
        }
        self::queueEvent('InitiateCheckout', [
            'content_ids' => $contentIds,
            'contents' => $contents,
            'num_items' => $numItems,
            'value' => round((float) ($totals['total'] ?? 0), 2),
            'currency' => self::CURRENCY,
        ]);
    }

    /**
     * Purchase — only when the order was loaded from the database for the
     * owning customer AND payment is confirmed. Session/demo orders are never
     * eligible (they cannot be payment-verified).
     *
     * The eventID is a deterministic, non-PII hash of the order number —
     * stable across browsers and reproducible server-side so Phase 3 CAPI can
     * send the same ID for browser/server deduplication.
     */
    public static function queuePurchaseIfEligible(array $order, bool $loadedFromDatabase): void
    {
        if (!self::purchaseEligible($order, $loadedFromDatabase)) {
            return;
        }
        $contents = [];
        $contentIds = [];
        $numItems = 0;
        foreach ($order['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $id = self::orderItemContentId($item);
            if ($id === '') {
                continue;
            }
            $contentIds[] = $id;
            $line = ['id' => $id, 'quantity' => $qty];
            $price = $item['unit_price'] ?? null;
            if (is_numeric($price)) {
                $line['item_price'] = (float) $price;
            }
            $contents[] = $line;
            $numItems += $qty;
        }
        if ($contentIds === []) {
            return;
        }
        $orderNumber = (string) ($order['id'] ?? '');
        self::queueEvent('Purchase', [
            'content_ids' => $contentIds,
            'content_type' => 'product',
            'contents' => $contents,
            'num_items' => $numItems,
            'value' => round((float) ($order['totals']['total'] ?? 0), 2),
            'currency' => self::CURRENCY,
        ], ['eventID' => self::purchaseEventId($orderNumber)]);
    }

    /** Whether a Purchase event may be emitted for this order view. */
    public static function purchaseEligible(array $order, bool $loadedFromDatabase): bool
    {
        if (!$loadedFromDatabase) {
            return false;
        }
        if ((string) ($order['overview']['payment_status'] ?? '') !== 'paid') {
            return false;
        }
        $publicStatus = strtolower((string) ($order['status'] ?? ''));

        return !in_array($publicStatus, ['cancelled', 'refunded', 'payment_failed'], true);
    }

    /**
     * Deterministic, non-PII Purchase event ID for an order. Derived from the
     * public order number only — no customer data — and stable so the same
     * value can be produced by Phase 3 CAPI for cross-channel deduplication.
     */
    public static function purchaseEventId(string $orderNumber): string
    {
        return 'vtpo_' . substr(hash('sha256', 'vt-purchase|' . strtolower(trim($orderNumber))), 0, 27);
    }

    /**
     * AddToCart payload for the AJAX cart-add response. Server-built so JS
     * only forwards it — kept out of the success path's control flow.
     *
     * @return array<string, mixed>|null null when the line cannot be resolved
     */
    public static function addToCartPayload(string $productSlug, string $variantId, int $qty): ?array
    {
        $product = demo_catalogue()->product($productSlug);
        if ($product === null) {
            return null;
        }
        $variant = null;
        foreach ($product['variants'] ?? [] as $v) {
            if ((string) ($v['id'] ?? '') === $variantId) {
                $variant = $v;
                break;
            }
        }
        if ($variant === null) {
            return null;
        }
        $qty = max(1, $qty);
        $id = self::contentId($product, $variant);
        $price = self::displayPrice($variant);
        $line = ['id' => $id, 'quantity' => $qty];
        if ($price !== null) {
            $line['item_price'] = $price;
        }
        $payload = [
            'content_ids' => [$id],
            'content_type' => 'product',
            'content_name' => (string) ($product['name'] ?? ''),
            'contents' => [$line],
            'num_items' => $qty,
            'currency' => self::CURRENCY,
        ];
        if ($price !== null) {
            $payload['value'] = round($price * $qty, 2);
        }

        return $payload;
    }

    /**
     * Catalogue ID strategy: variant SKU when set, else product SKU, else a
     * stable `v{variant_id}` identifier. SKUs are what a future Meta catalogue
     * feed would use as `id`, so they take precedence.
     */
    public static function contentId(array $product, ?array $variant): string
    {
        $vSku = trim((string) ($variant['sku'] ?? ''));
        if ($vSku !== '') {
            return $vSku;
        }
        $pSku = trim((string) ($product['sku'] ?? ''));
        if ($pSku !== '') {
            return $pSku;
        }
        $vId = trim((string) ($variant['id'] ?? ''));
        if ($vId !== '') {
            return 'v' . $vId;
        }

        return 'p_' . trim((string) ($product['slug'] ?? ''));
    }

    /** Resolve a cart line to a content id via the catalogue (SKU-aware). */
    private static function cartLineContentId(array $item): string
    {
        $product = null;
        $slug = (string) ($item['product_slug'] ?? '');
        if ($slug !== '') {
            $product = demo_catalogue()->product($slug);
        }
        if (is_array($product)) {
            foreach ($product['variants'] ?? [] as $v) {
                if ((string) ($v['id'] ?? '') === (string) ($item['variant_id'] ?? '')) {
                    return self::contentId($product, $v);
                }
            }
        }
        $vid = trim((string) ($item['variant_id'] ?? ''));

        return $vid !== '' ? 'v' . $vid : '';
    }

    /** Order item content id — order_items carries the purchased SKU/variant. */
    private static function orderItemContentId(array $item): string
    {
        $sku = trim((string) ($item['sku'] ?? ''));
        if ($sku !== '') {
            return $sku;
        }
        $vid = trim((string) ($item['variant_id'] ?? ''));

        return $vid !== '' ? 'v' . $vid : '';
    }

    private static function displayPrice(?array $variant): ?float
    {
        if (!is_array($variant)) {
            return null;
        }
        $prices = catalogue_variant_prices($variant);
        $display = (float) ($prices['display'] ?? 0);

        return $display > 0 ? round($display, 2) : null;
    }

    /**
     * Bootstrap config consumed by assets/js/meta-events.js.
     * pixel_id is only rendered when the pixel is configured for this
     * environment — it is never secret, but there is no reason to emit it when
     * tracking cannot run.
     *
     * @return array<string, mixed>
     */
    public static function clientConfig(): array
    {
        $configured = self::configured();

        return [
            'configured' => $configured,
            'pixelId' => $configured ? MetaAdsSettings::pixelId() : null,
            'currency' => self::CURRENCY,
            'consent' => MetaConsent::clientConfig(),
            'events' => self::$pendingEvents,
        ];
    }

    /** Render the nonce-protected bootstrap config + script tags for the footer. */
    public static function renderBootstrap(): void
    {
        echo '<script ' . Security::cspNonceAttr() . '>window.__AT_META__ = '
            . json_encode(self::clientConfig(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . ';</script>' . "\n";
        echo '<script defer src="' . Security::escape(asset_url('assets/js/meta-consent.js')) . '"></script>' . "\n";
        echo '<script defer src="' . Security::escape(asset_url('assets/js/meta-events.js')) . '"></script>' . "\n";
    }
}
