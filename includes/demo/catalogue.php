<?php

declare(strict_types=1);

/**
 * Stage 2 demo catalogue — static sample data until Stage 6 backend.
 * All storefront demo content is flagged; no fabricated live ratings or order counts.
 */
final class DemoCatalogue
{
    /** @var self|null */
    private static $instance = null;

    /** @var array<string, array<string, mixed>> */
    private $productsBySlug = [];

    /** @var array<string, array<string, mixed>> */
    private $categoriesBySlug = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->bootCategories();
        $this->bootProducts();
    }

    public function isDemo(): bool
    {
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return array_values($this->categoriesBySlug);
    }

    /** @return array<string, mixed>|null */
    public function category(string $slug): ?array
    {
        return $this->categoriesBySlug[$slug] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function products(): array
    {
        return array_values($this->productsBySlug);
    }

    /** @return array<string, mixed>|null */
    public function product(string $slug): ?array
    {
        return $this->productsBySlug[$slug] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function productsInCategory(string $categorySlug): array
    {
        return array_values(array_filter(
            $this->productsBySlug,
            static function (array $p) use ($categorySlug): bool {
                return ($p['category_slug'] ?? '') === $categorySlug;
            }
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return [];
        }
        $out = [];
        foreach ($this->productsBySlug as $product) {
            $hay = mb_strtolower(
                ($product['name'] ?? '') . ' ' .
                ($product['short_description'] ?? '') . ' ' .
                ($product['sku'] ?? '')
            );
            if (mb_strpos($hay, $q) !== false) {
                $out[] = $product;
            }
        }

        return $out;
    }

    /** @param list<string> $flags */
    public function productsWithFlag(string $flag, int $limit = 8): array
    {
        $out = [];
        foreach ($this->productsBySlug as $product) {
            if (in_array($flag, $product['flags'] ?? [], true)) {
                $out[] = $product;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function relatedFor(array $product, int $limit = 4): array
    {
        $slugs = $product['related_slugs'] ?? [];
        $out = [];
        foreach ($slugs as $slug) {
            if (isset($this->productsBySlug[$slug])) {
                $out[] = $this->productsBySlug[$slug];
            }
        }
        if (count($out) >= $limit) {
            return array_slice($out, 0, $limit);
        }
        $seen = [];
        foreach ($out as $o) {
            $seen[$o['slug']] = true;
        }
        foreach ($this->productsInCategory($product['category_slug'] ?? '') as $p) {
            if ($p['slug'] === $product['slug'] || isset($seen[$p['slug']])) {
                continue;
            }
            $out[] = $p;
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** Approved demo reviews only — shown with sample badge in UI. */
    public function approvedReviewsFor(string $productSlug): array
    {
        $product = $this->product($productSlug);
        if ($product === null) {
            return [];
        }

        return array_values(array_filter(
            $product['reviews'] ?? [],
            static function (array $r): bool {
                return ($r['status'] ?? '') === 'approved';
            }
        ));
    }

    public function ratingSummary(string $productSlug): ?array
    {
        $reviews = $this->approvedReviewsFor($productSlug);
        if ($reviews === []) {
            return null;
        }
        $sum = 0;
        foreach ($reviews as $r) {
            $sum += (int) ($r['rating'] ?? 0);
        }
        $count = count($reviews);

        return [
            'average' => round($sum / $count, 1),
            'count' => $count,
            'is_demo_sample' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function faqs(int $limit = 0): array
    {
        $faqs = [
            ['q' => 'Apni Tokri kya hai?', 'a' => 'Apni Tokri Indore ke liye fresh fruits, vegetables aur rozmarra ke food products ka online store hai.', 'cat' => 'general'],
            ['q' => 'Kya Indore mein delivery available hai?', 'a' => 'Haan, launch phase mein select Indore areas ke liye delivery configure ki jayegi. Address se pehle serviceability check karein.', 'cat' => 'delivery'],
            ['q' => 'Delivery charges kaise calculate hote hain?', 'a' => 'Cart total, delivery zone aur active offers ke basis par fee checkout se pehle clearly dikhayi jayegi.', 'cat' => 'delivery'],
            ['q' => 'Product ka weight/variant kaise select karein?', 'a' => 'Product page par available weights/sizes list honge. Price aur stock selected variant ke hisaab se update hoga.', 'cat' => 'products'],
            ['q' => 'Payment fail ho to kya karein?', 'a' => 'Aapka cart save rehta hai. Order details se payment dubara try kar sakte hain ya support se contact karein.', 'cat' => 'payments'],
            ['q' => 'Review kaise submit karein?', 'a' => 'Delivered order ke baad hi verified review submit kar sakte hain. Admin approval ke baad review public dikhega.', 'cat' => 'reviews'],
        ];
        if ($limit > 0) {
            return array_slice($faqs, 0, $limit);
        }

        return $faqs;
    }

    private function bootCategories(): void
    {
        $items = [
            ['slug' => 'vegetables', 'name' => 'Fresh Vegetables', 'name_hi' => 'ताज़ी सब्ज़ियाँ', 'icon' => '🥬'],
            ['slug' => 'fruits', 'name' => 'Fresh Fruits', 'name_hi' => 'ताज़े फल', 'icon' => '🍎'],
            ['slug' => 'cut', 'name' => 'Cut & Ready', 'name_hi' => 'कटा हुआ', 'icon' => '🥗'],
            ['slug' => 'pickles', 'name' => 'Pickles & Papad', 'name_hi' => 'अचार और पापड़', 'icon' => '🫙'],
        ];
        foreach ($items as $item) {
            $this->categoriesBySlug[$item['slug']] = $item;
        }
    }

    private function bootProducts(): void
    {
        // Demo product photography lives in assets/images/demo (illustrative, not real stock photos of our supply).
        $img = static function (string $file): string {
            return asset_url('assets/images/demo/' . $file);
        };

        $raw = [
            [
                'slug' => 'fresh-red-tomatoes',
                'sku' => 'VEG-TOM-001',
                'name' => 'Fresh Red Tomatoes',
                'category_slug' => 'vegetables',
                'type' => 'fresh',
                'short_description' => 'Locally sourced tomatoes for daily cooking.',
                'description' => 'Selected for firmness and colour. Store in a cool, dry place away from direct sunlight. Wash before use.',
                'images' => [$img('p-tomato-1.jpg'), $img('p-tomato-2.jpg'), $img('p-tomato-3.jpg')],
                'flags' => ['bestseller', 'featured'],
                'variants' => [
                    ['id' => '500g', 'label' => '500 g', 'price' => 35, 'sale_price' => null, 'stock' => 24, 'low_stock' => 10],
                    ['id' => '1kg', 'label' => '1 kg', 'price' => 65, 'sale_price' => 59, 'stock' => 8, 'low_stock' => 10],
                ],
                'attributes' => [
                    ['label' => 'Storage', 'value' => 'Cool, dry place; refrigerate after cutting'],
                    ['label' => 'Use', 'value' => 'Curries, salads, daily cooking'],
                ],
                'reviews' => [
                    [
                        'status' => 'approved',
                        'rating' => 4,
                        'title' => 'Good for daily use',
                        'body' => 'Sample UI review — tomatoes were firm and fresh on delivery day.',
                        'author' => 'Demo Customer',
                        'verified' => true,
                        'is_sample' => true,
                        'date' => '2026-01-15',
                    ],
                ],
                'related_slugs' => ['green-capsicum', 'onion-red'],
            ],
            [
                'slug' => 'green-capsicum',
                'sku' => 'VEG-CAP-002',
                'name' => 'Green Capsicum',
                'category_slug' => 'vegetables',
                'type' => 'fresh',
                'short_description' => 'Crisp capsicum for stir-fry and stuffing.',
                'description' => 'Handled with care to reduce bruising. Rinse and pat dry before storing.',
                'images' => [$img('p-capsicum.jpg')],
                'flags' => ['new'],
                'variants' => [
                    ['id' => '500g', 'label' => '500 g', 'price' => 48, 'sale_price' => null, 'stock' => 15, 'low_stock' => 8],
                ],
                'attributes' => [
                    ['label' => 'Storage', 'value' => 'Refrigerate in vegetable drawer'],
                ],
                'reviews' => [],
                'related_slugs' => ['fresh-red-tomatoes'],
            ],
            [
                'slug' => 'onion-red',
                'sku' => 'VEG-ONI-003',
                'name' => 'Red Onion',
                'category_slug' => 'vegetables',
                'type' => 'fresh',
                'short_description' => 'Staple onion for Indian cooking.',
                'description' => 'Store in a ventilated basket away from potatoes.',
                'images' => [$img('p-onion.jpg')],
                'flags' => ['bestseller'],
                'variants' => [
                    ['id' => '1kg', 'label' => '1 kg', 'price' => 42, 'sale_price' => null, 'stock' => 40, 'low_stock' => 12],
                ],
                'attributes' => [],
                'reviews' => [],
                'related_slugs' => ['fresh-red-tomatoes'],
            ],
            [
                'slug' => 'banana-robusta',
                'sku' => 'FRT-BAN-010',
                'name' => 'Banana (Robusta)',
                'category_slug' => 'fruits',
                'type' => 'fresh',
                'short_description' => 'Ripe bananas for snacking and shakes.',
                'description' => 'Ripeness may vary; consume within recommended days after delivery.',
                'images' => [$img('p-banana.jpg')],
                'flags' => ['featured'],
                'variants' => [
                    ['id' => '6pc', 'label' => '6 pieces (~800 g)', 'price' => 55, 'sale_price' => null, 'stock' => 20, 'low_stock' => 6],
                ],
                'attributes' => [
                    ['label' => 'Storage', 'value' => 'Room temperature; separate from other fruits if needed'],
                ],
                'reviews' => [],
                'related_slugs' => ['apple-shimla'],
            ],
            [
                'slug' => 'apple-shimla',
                'sku' => 'FRT-APL-011',
                'name' => 'Apple (Shimla)',
                'category_slug' => 'fruits',
                'type' => 'fresh',
                'short_description' => 'Sweet-tart apples.',
                'description' => 'Refrigerate for longer shelf life.',
                'images' => [$img('p-apple.jpg')],
                'flags' => ['new'],
                'variants' => [
                    ['id' => '1kg', 'label' => '1 kg (4–5 pcs approx.)', 'price' => 180, 'sale_price' => 165, 'stock' => 12, 'low_stock' => 5],
                ],
                'attributes' => [],
                'reviews' => [],
                'related_slugs' => ['banana-robusta'],
            ],
            [
                'slug' => 'cut-mixed-salad',
                'sku' => 'CUT-SAL-020',
                'name' => 'Cut Mixed Salad Pack',
                'category_slug' => 'cut',
                'type' => 'cut',
                'short_description' => 'Ready-cut salad vegetables — prepare fresh.',
                'description' => 'Prepared in small batches. Use within use-by guidance on pack. Keep refrigerated.',
                'images' => [$img('p-salad.jpg')],
                'flags' => ['featured', 'bestseller'],
                'variants' => [
                    ['id' => '250g', 'label' => '250 g', 'price' => 75, 'sale_price' => null, 'stock' => 6, 'low_stock' => 8],
                ],
                'attributes' => [
                    ['label' => 'Cut size', 'value' => 'Bite-sized mix'],
                    ['label' => 'Use-by', 'value' => 'Consume within 24 hours of delivery (demo text)'],
                ],
                'reviews' => [],
                'related_slugs' => ['cut-french-beans'],
            ],
            [
                'slug' => 'cut-french-beans',
                'sku' => 'CUT-FB-021',
                'name' => 'Cut French Beans',
                'category_slug' => 'cut',
                'type' => 'cut',
                'short_description' => 'Trimmed beans ready to cook.',
                'description' => 'Refrigerate immediately. Do not freeze unless label allows.',
                'images' => [$img('p-beans.jpg')],
                'flags' => [],
                'variants' => [
                    ['id' => '250g', 'label' => '250 g', 'price' => 45, 'sale_price' => null, 'stock' => 0, 'low_stock' => 5],
                ],
                'attributes' => [
                    ['label' => 'Preparation', 'value' => 'Washed and cut to order in demo flow'],
                ],
                'reviews' => [],
                'related_slugs' => ['cut-mixed-salad'],
            ],
            [
                'slug' => 'mango-pickle-homestyle',
                'sku' => 'PKL-MNG-030',
                'name' => 'Homestyle Mango Pickle',
                'category_slug' => 'pickles',
                'type' => 'packaged',
                'short_description' => 'Traditional mango pickle — packed food.',
                'description' => 'Ingredients and allergen info must match actual label at launch.',
                'images' => [$img('p-pickle.jpg')],
                'flags' => ['bestseller'],
                'variants' => [
                    ['id' => '500g', 'label' => '500 g jar', 'price' => 120, 'sale_price' => null, 'stock' => 30, 'low_stock' => 10],
                ],
                'attributes' => [
                    ['label' => 'Ingredients', 'value' => 'Mango, mustard oil, spices (demo — replace with label)'],
                    ['label' => 'Storage', 'value' => 'Cool, dry place; refrigerate after opening'],
                    ['label' => 'Shelf life', 'value' => 'See pack label'],
                ],
                'reviews' => [],
                'related_slugs' => ['papad-moong'],
            ],
            [
                'slug' => 'papad-moong',
                'sku' => 'PPD-MNG-031',
                'name' => 'Moong Papad',
                'category_slug' => 'pickles',
                'type' => 'packaged',
                'short_description' => 'Roast or fry at home.',
                'description' => 'Store in airtight container once opened.',
                'images' => [$img('p-papad.jpg')],
                'flags' => [],
                'variants' => [
                    ['id' => '200g', 'label' => '200 g pack', 'price' => 55, 'sale_price' => 49, 'stock' => 25, 'low_stock' => 8],
                ],
                'attributes' => [
                    ['label' => 'Ingredients', 'value' => 'Moong dal, spices (demo)'],
                ],
                'reviews' => [],
                'related_slugs' => ['mango-pickle-homestyle'],
            ],
        ];

        foreach ($raw as $row) {
            $row['id'] = $row['slug'];
            $this->productsBySlug[$row['slug']] = $row;
        }
    }
}
