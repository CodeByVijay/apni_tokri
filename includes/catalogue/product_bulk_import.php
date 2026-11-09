<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/spreadsheet/simple_xlsx.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/product_admin_form.php';

/**
 * Bulk product import — aligns with admin product edit form (five tabs).
 */
final class ProductBulkImport
{
    public const MAX_PRODUCTS = 500;
    public const MAX_XLSX_BYTES = 12 * 1024 * 1024;
    public const MAX_ZIP_BYTES = 120 * 1024 * 1024;
    public const MAX_ZIP_ENTRIES = 600;
    public const MAX_ZIP_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const SHEET_INSTRUCTIONS = 'Instructions';
    public const SHEET_PRODUCTS = 'Products';
    public const SHEET_VARIANTS = 'Variants';
    public const SHEET_ATTRIBUTES = 'Attributes';
    public const SHEET_IMAGES = 'Images';
    public const SHEET_SEO = 'SEO';
    public const SHEET_REFERENCE = 'Reference';

    /**
     * @return array{ok: bool, path?: string, message?: string}
     */
    public static function writeTemplate(string $destPath): array
    {
        $categories = [];
        $types = [];
        if (Install::isSchemaReady()) {
            Install::ensureConnection();
            foreach (CategoryRepository::allForAdmin() as $c) {
                if ((string) ($c['status'] ?? '') !== 'published') {
                    continue;
                }
                $categories[] = [
                    (string) ($c['name'] ?? ''),
                    (string) ($c['slug'] ?? ''),
                ];
            }
            foreach (ProductTypeRepository::optionsForProductForm() as $t) {
                $types[] = [(string) ($t['code'] ?? ''), (string) ($t['name'] ?? '')];
            }
        }
        $sampleCode = 'P-EXAMPLE';
        $sheets = [
            self::SHEET_INSTRUCTIONS => [
                ['Bulk import instructions'],
                [''],
                ['Defaults (not editable via workbook)'],
                ['All imported products are saved as status = draft.'],
                ['Featured, bestseller, and new arrival are always off (0).'],
                ['Existing catalogue products are never updated by import.'],
                [''],
                ['product_code'],
                ['Linking key across sheets only — not stored in the database.'],
                [''],
                ['Images ZIP'],
                ['Optional when the Images sheet has no rows.'],
                ['Required when any Images row references a filename — ZIP must include every referenced file (match basename, case-insensitive).'],
                ['Filenames: use simple names (e.g. mango-01.jpg). JPEG, PNG, or WebP only; max 5 MB per file.'],
            ],
            self::SHEET_PRODUCTS => [
                self::productsHeaders(),
                [
                    $sampleCode, 'Sample tomato', 'sample-tomato', 'SKU-EX-001',
                    $categories[0][0] ?? 'vegetables',
                    $types[0][0] ?? ProductTypeRepository::defaultCode(),
                    'Sweet ripe tomatoes', 'Full product description here.',
                ],
            ],
            self::SHEET_VARIANTS => [
                self::variantsHeaders(),
                [$sampleCode, '500 g', 'SKU-EX-001-500G', '45', '40', '100', '5', '1', '0', '1', '1'],
                [$sampleCode, '1 kg', 'SKU-EX-001-1KG', '85', '', '80', '5', '1', '0', '2', '2'],
            ],
            self::SHEET_ATTRIBUTES => [
                self::attributesHeaders(),
                [$sampleCode, 'Origin', 'Maharashtra', '1'],
                [$sampleCode, 'Storage', 'Refrigerate', '2'],
            ],
            self::SHEET_IMAGES => [
                self::imagesHeaders(),
                [$sampleCode, 'sample-tomato.jpg', 'yes', '1', 'Fresh tomatoes'],
            ],
            self::SHEET_SEO => [
                self::seoHeaders(),
                [$sampleCode, 'Sample tomato | Veggie Tokri', 'Buy fresh sample tomatoes online.', 'yes'],
            ],
            self::SHEET_REFERENCE => array_merge(
                [['Categories (published)', '', 'Product types (active code)', '']],
                [['Category name', 'Category slug', 'Type code', 'Type name']],
                self::referenceMergeRows($categories, $types)
            ),
        ];
        if (!SimpleXlsx::write($sheets, $destPath)) {
            return ['ok' => false, 'message' => 'Could not create template file.'];
        }

        return ['ok' => true, 'path' => $destPath];
    }

    /**
     * @param list<array{0: string, 1: string}> $categories
     * @param list<array{0: string, 1: string}> $types
     * @return list<list<string>>
     */
    private static function referenceMergeRows(array $categories, array $types): array
    {
        $max = max(count($categories), count($types));
        $rows = [];
        for ($i = 0; $i < $max; $i++) {
            $rows[] = [
                $categories[$i][0] ?? '',
                $categories[$i][1] ?? '',
                $types[$i][0] ?? '',
                $types[$i][1] ?? '',
            ];
        }
        if ($rows === []) {
            $rows[] = ['', '', 'fresh', 'Fresh produce'];
        }

        return $rows;
    }

    /** @return list<string> */
    public static function productsHeaders(): array
    {
        return [
            'product_code', 'product_name', 'slug', 'sku', 'category', 'product_type',
            'short_description', 'full_description',
        ];
    }

    /** @return list<string> */
    public static function variantsHeaders(): array
    {
        return [
            'product_code', 'variant_name', 'sku', 'price', 'sale_price', 'stock_quantity',
            'low_stock_threshold', 'min_order_quantity', 'max_order_quantity', 'sort_order',
        ];
    }

    /** @return list<string> */
    public static function attributesHeaders(): array
    {
        return ['product_code', 'attribute_label', 'attribute_value', 'sort_order'];
    }

    /** @return list<string> */
    public static function imagesHeaders(): array
    {
        return ['product_code', 'filename', 'is_primary', 'sort_order', 'alt_text'];
    }

    /** @return list<string> */
    public static function seoHeaders(): array
    {
        return ['product_code', 'meta_title', 'meta_description', 'allow_indexing'];
    }

    /**
     * @return array{
     *   products: list<array<string, string>>,
     *   variants: list<array<string, string>>,
     *   attributes: list<array<string, string>>,
     *   images: list<array<string, string>>,
     *   seo: list<array<string, string>>
     * }
     */
    public static function parseWorkbook(string $path): array
    {
        $sheets = SimpleXlsx::read($path);
        $norm = static function (string $name) use ($sheets): array {
            foreach ($sheets as $k => $rows) {
                if (strcasecmp($k, $name) === 0) {
                    return SimpleXlsx::rowsToAssoc($rows);
                }
            }

            return [];
        };

        return [
            'products' => $norm(self::SHEET_PRODUCTS),
            'variants' => $norm(self::SHEET_VARIANTS),
            'attributes' => $norm(self::SHEET_ATTRIBUTES),
            'images' => $norm(self::SHEET_IMAGES),
            'seo' => $norm(self::SHEET_SEO),
        ];
    }

    /**
     * @param array<string, string> $zipPath real path to zip on disk
     * @return array{ok: bool, dir?: string, index?: array<string, string>, message?: string}
     */
    public static function extractImageZip(string $zipPath): array
    {
        $extractRoot = dirname($zipPath) . '/img-' . bin2hex(random_bytes(4));
        if (!@mkdir($extractRoot, 0755, true)) {
            return ['ok' => false, 'message' => 'Could not prepare image extract directory.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            self::rrmdir($extractRoot);

            return ['ok' => false, 'message' => 'Could not open images ZIP.'];
        }
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            self::rrmdir($extractRoot);

            return ['ok' => false, 'message' => 'Images ZIP contains too many entries.'];
        }
        $index = [];
        $uncompressedTotal = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (strpos($name, '..') !== false || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:/', $name) === 1) {
                $zip->close();
                self::rrmdir($extractRoot);

                return ['ok' => false, 'message' => 'Invalid path in images ZIP.'];
            }
            $baseName = self::safeZipEntryBasename($name);
            if ($baseName === null) {
                $zip->close();
                self::rrmdir($extractRoot);

                return ['ok' => false, 'message' => 'Unsafe filename in images ZIP: ' . basename($name)];
            }
            $entrySize = (int) ($stat['size'] ?? 0);
            $uncompressedTotal += max(0, $entrySize);
            if ($uncompressedTotal > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                $zip->close();
                self::rrmdir($extractRoot);

                return ['ok' => false, 'message' => 'Images ZIP exceeds decompressed size limit.'];
            }
            $dest = $extractRoot . '/' . $baseName;
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $out = fopen($dest, 'wb');
            if ($out === false) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
            $valid = self::validateRasterImageFile($dest);
            if (empty($valid['ok'])) {
                $zip->close();
                self::rrmdir($extractRoot);

                return ['ok' => false, 'message' => (string) ($valid['message'] ?? 'Invalid image in ZIP.')];
            }
            $base = strtolower($baseName);
            $index[$base] = $dest;
        }
        $zip->close();

        return ['ok' => true, 'dir' => $extractRoot, 'index' => $index];
    }

    public static function workbookReferencesImages(array $parsed): bool
    {
        foreach ($parsed['images'] ?? [] as $row) {
            if (self::cell($row, 'filename') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function validateRasterImageFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'message' => 'Image file is missing or unreadable.'];
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            return ['ok' => false, 'message' => 'Image file is empty.'];
        }
        if ($size > self::MAX_IMAGE_BYTES) {
            return ['ok' => false, 'message' => 'Image exceeds 5 MB limit.'];
        }
        $allowed = Upload::productRasterAllowed();
        $mime = '';
        if (function_exists('mime_content_type')) {
            $mime = (string) mime_content_type($path);
        }
        if ($mime === '' && class_exists('finfo')) {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $fi->file($path);
        }
        if ($mime === '' || !isset($allowed[$mime])) {
            return ['ok' => false, 'message' => 'Invalid image type (use JPEG, PNG, or WebP).'];
        }
        $probe = @getimagesize($path);
        if ($probe === false) {
            return ['ok' => false, 'message' => 'File is not a valid raster image.'];
        }

        return ['ok' => true];
    }

    private static function safeZipEntryBasename(string $entryName): ?string
    {
        $base = basename(str_replace('\\', '/', $entryName));
        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $base) === 1) {
            return null;
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\-+@() ]{0,190}$/', $base) !== 1) {
            return null;
        }

        return $base;
    }

    /**
     * @param array{
     *   products: list<array<string, string>>,
     *   variants: list<array<string, string>>,
     *   attributes: list<array<string, string>>,
     *   images: list<array<string, string>>,
     *   seo: list<array<string, string>>
     * } $parsed
     * @param array<string, string>|null $imageIndex lowercase basename => path
     * @return array{
     *   ok: bool,
     *   errors: list<array{sheet: string, row: string, product_code: string, reason: string}>,
     *   products: list<array<string, mixed>>,
     *   ready_count: int
     * }
     */
    public static function validate(array $parsed, ?array $imageIndex): array
    {
        $errors = [];
        $addError = static function (
            string $sheet,
            string $row,
            string $code,
            string $reason
        ) use (&$errors): void {
            $errors[] = [
                'sheet' => $sheet,
                'row' => $row,
                'product_code' => $code,
                'reason' => $reason,
            ];
        };
        if ($parsed['products'] === []) {
            $addError(self::SHEET_PRODUCTS, '1', '', 'Products sheet has no data rows.');
        }
        if (count($parsed['products']) > self::MAX_PRODUCTS) {
            $addError(self::SHEET_PRODUCTS, '', '', 'Too many products (max ' . self::MAX_PRODUCTS . ').');
        }

        $categoryBySlug = [];
        $categoryByName = [];
        foreach (self::activeCategories() as $c) {
            $slug = strtolower((string) $c['slug']);
            $name = strtolower((string) $c['name']);
            $categoryBySlug[$slug] = (int) $c['id'];
            $categoryByName[$name] = (int) $c['id'];
        }

        $codesInSheet = [];
        $productSkus = [];
        $variantSkus = [];
        $slugs = [];
        $bundled = [];

        foreach ($parsed['products'] as $row) {
            $code = self::cell($row, 'product_code');
            $rowNum = self::rowNum($row);
            if ($code === '') {
                $addError(self::SHEET_PRODUCTS, $rowNum, '', 'product_code is required.');
                continue;
            }
            if (isset($codesInSheet[$code])) {
                $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'Duplicate product_code in workbook.');
            }
            $codesInSheet[$code] = true;
            $name = self::cell($row, 'product_name');
            if ($name === '') {
                $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'product_name is required.');
            }
            $sku = ProductSkuService::normalize(self::cell($row, 'sku'));
            if ($sku !== '') {
                if (isset($productSkus[$sku])) {
                    $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'Duplicate product SKU in workbook.');
                }
                $productSkus[$sku] = $code;
                if (ProductSkuService::skuInUse($sku, null, null)) {
                    $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'Product SKU already exists in catalogue.');
                }
            }
            $slugRaw = self::cell($row, 'slug');
            $slug = $slugRaw !== '' ? CatalogueSlug::normalizeInput($slugRaw) : '';
            if ($slug !== '') {
                if (!Validation::slug($slug)) {
                    $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'Invalid slug.');
                }
                if (isset($slugs[$slug])) {
                    $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'Duplicate slug in workbook.');
                }
                $slugs[$slug] = $code;
                if (ProductRepository::slugTaken($slug, null)) {
                    $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'Slug already used by an existing product.');
                }
            }
            $cat = self::cell($row, 'category');
            if ($cat === '') {
                $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'category is required.');
            } else {
                $cid = self::resolveCategoryId($cat, $categoryBySlug, $categoryByName);
                if ($cid === null) {
                    $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'Unknown or inactive category.');
                }
            }
            $ptype = strtolower(self::cell($row, 'product_type'));
            if ($ptype === '' || !ProductTypeRepository::isAllowedCode($ptype)) {
                $addError(self::SHEET_PRODUCTS, $rowNum, $code, 'Invalid product_type.');
            }

            $bundled[$code] = [
                'product_code' => $code,
                'general' => $row,
                'variants' => [],
                'attributes' => [],
                'images' => [],
                'seo' => null,
            ];
        }

        foreach ($parsed['variants'] as $row) {
            $code = self::cell($row, 'product_code');
            $rowNum = self::rowNum($row);
            if ($code === '') {
                $addError(self::SHEET_VARIANTS, $rowNum, '', 'product_code is required.');
                continue;
            }
            if (!isset($bundled[$code])) {
                $addError(self::SHEET_VARIANTS, $rowNum, $code, 'Unknown product_code (not in Products sheet).');
                continue;
            }
            $bundled[$code]['variants'][] = $row;
            $vsku = ProductSkuService::normalize(self::cell($row, 'sku'));
            if ($vsku !== '') {
                if (isset($variantSkus[$vsku])) {
                    $addError(self::SHEET_VARIANTS, $rowNum, $code, 'Duplicate variant SKU in workbook.');
                }
                $variantSkus[$vsku] = true;
                if (isset($productSkus[$vsku])) {
                    $addError(self::SHEET_VARIANTS, $rowNum, $code, 'Variant SKU matches another product SKU in workbook.');
                }
                if (ProductSkuService::skuInUse($vsku, null, null)) {
                    $addError(self::SHEET_VARIANTS, $rowNum, $code, 'Variant SKU already exists in catalogue.');
                }
            }
        }

        foreach ($parsed['attributes'] as $row) {
            $code = self::cell($row, 'product_code');
            $rowNum = self::rowNum($row);
            if ($code === '') {
                $addError(self::SHEET_ATTRIBUTES, $rowNum, '', 'product_code is required.');
                continue;
            }
            if (!isset($bundled[$code])) {
                $addError(self::SHEET_ATTRIBUTES, $rowNum, $code, 'Unknown product_code.');
                continue;
            }
            $label = self::cell($row, 'attribute_label');
            $val = self::cell($row, 'attribute_value');
            if ($label === '' && $val === '') {
                continue;
            }
            if ($label === '' || $val === '') {
                $addError(self::SHEET_ATTRIBUTES, $rowNum, $code, 'attribute_label and attribute_value must both be set.');
                continue;
            }
            $bundled[$code]['attributes'][] = $row;
        }

        $imagesReferenced = self::workbookReferencesImages($parsed);
        if ($imagesReferenced && ($imageIndex === null || $imageIndex === [])) {
            $addError(self::SHEET_IMAGES, '', '', 'Images ZIP is required when the Images sheet lists filenames.');
        }

        $primaryCounts = [];
        foreach ($parsed['images'] as $row) {
            $code = self::cell($row, 'product_code');
            $rowNum = self::rowNum($row);
            if ($code === '') {
                $addError(self::SHEET_IMAGES, $rowNum, '', 'product_code is required.');
                continue;
            }
            if (!isset($bundled[$code])) {
                $addError(self::SHEET_IMAGES, $rowNum, $code, 'Unknown product_code.');
                continue;
            }
            $file = self::cell($row, 'filename');
            if ($file === '') {
                $addError(self::SHEET_IMAGES, $rowNum, $code, 'filename is required.');
                continue;
            }
            $bundled[$code]['images'][] = $row;
            if (self::parseBool(self::cell($row, 'is_primary'))) {
                $primaryCounts[$code] = ($primaryCounts[$code] ?? 0) + 1;
            }
            $basename = strtolower(basename(str_replace('\\', '/', $file)));
            if ($imageIndex === null || !isset($imageIndex[$basename])) {
                $addError(self::SHEET_IMAGES, $rowNum, $code, 'Image file not found in uploaded ZIP: ' . $basename);
                continue;
            }
            $validImg = self::validateRasterImageFile($imageIndex[$basename]);
            if (empty($validImg['ok'])) {
                $addError(
                    self::SHEET_IMAGES,
                    $rowNum,
                    $code,
                    'Invalid image file ' . $basename . ': ' . (string) ($validImg['message'] ?? 'rejected')
                );
            }
        }

        foreach ($bundled as $code => $bundle) {
            if (count($bundle['images']) > ProductRepository::MAX_IMAGES_PER_PRODUCT) {
                $addError(self::SHEET_IMAGES, '', $code, 'Too many images (max ' . ProductRepository::MAX_IMAGES_PER_PRODUCT . ').');
            }
            if (($primaryCounts[$code] ?? 0) > 1) {
                $addError(self::SHEET_IMAGES, '', $code, 'Multiple primary images defined.');
            }
            $variantRows = self::variantRowsFromImport($bundle['variants']);
            $vErr = ProductAdminForm::validateVariantRows($variantRows);
            if ($vErr !== []) {
                $addError(self::SHEET_VARIANTS, '', $code, (string) (reset($vErr) ?: 'Invalid variant row.'));
            }
        }

        foreach ($parsed['seo'] as $row) {
            $code = self::cell($row, 'product_code');
            $rowNum = self::rowNum($row);
            if ($code === '') {
                $addError(self::SHEET_SEO, $rowNum, '', 'product_code is required.');
                continue;
            }
            if (!isset($bundled[$code])) {
                $addError(self::SHEET_SEO, $rowNum, $code, 'Unknown product_code.');
                continue;
            }
            $bundled[$code]['seo'] = $row;
            $title = self::cell($row, 'meta_title');
            $desc = self::cell($row, 'meta_description');
            if ($title !== '' && mb_strlen($title) > 70) {
                $addError(self::SHEET_SEO, $rowNum, $code, 'meta_title exceeds 70 characters.');
            }
            if ($desc !== '' && mb_strlen($desc) > 160) {
                $addError(self::SHEET_SEO, $rowNum, $code, 'meta_description exceeds 160 characters (product form limit).');
            }
        }

        $preview = [];
        foreach ($bundled as $code => $bundle) {
            $preview[] = self::previewProduct($code, $bundle);
        }
        $importable = self::filterImportableBundles($errors, $bundled);

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'products' => $preview,
            'ready_count' => count($importable),
        ];
    }

    /**
     * @param list<array<string, mixed>> $products preview bundles with keys set during validate
     * @param array<string, string> $imageIndex
     * @return array{
     *   ok: bool,
     *   imported: int,
     *   failed: int,
     *   results: list<array{product_code: string, ok: bool, product_id?: int, message?: string}>,
     *   report_rows: list<list<string>>
     * }
     */
    public static function importProducts(array $bundledByCode, ?array $imageIndex, ?int $adminUserId): array
    {
        $categoryBySlug = [];
        $categoryByName = [];
        foreach (self::activeCategories() as $c) {
            $categoryBySlug[strtolower((string) $c['slug'])] = (int) $c['id'];
            $categoryByName[strtolower((string) $c['name'])] = (int) $c['id'];
        }

        $results = [];
        $report = [['product_code', 'status', 'product_id', 'message']];
        $imported = 0;
        $failed = 0;

        foreach ($bundledByCode as $code => $bundle) {
            try {
                $productId = self::importOneProduct($code, $bundle, $categoryBySlug, $categoryByName, $imageIndex, $adminUserId);
                $imported++;
                $results[] = ['product_code' => $code, 'ok' => true, 'product_id' => $productId];
                $report[] = [$code, 'imported', (string) $productId, ''];
            } catch (Throwable $e) {
                $failed++;
                $msg = $e->getMessage() !== '' ? $e->getMessage() : 'Import failed.';
                error_log('ProductBulkImport: ' . $code . ': ' . $msg);
                $results[] = ['product_code' => $code, 'ok' => false, 'message' => $msg];
                $report[] = [$code, 'failed', '', $msg];
            }
        }
        CatalogueGateway::reset();

        return [
            'ok' => $failed === 0,
            'imported' => $imported,
            'failed' => $failed,
            'results' => $results,
            'report_rows' => $report,
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, int> $categoryBySlug
     * @param array<string, int> $categoryByName
     * @param array<string, string>|null $imageIndex
     */
    private static function importOneProduct(
        string $code,
        array $bundle,
        array $categoryBySlug,
        array $categoryByName,
        ?array $imageIndex,
        ?int $adminUserId
    ): int {
        $productId = 0;
        try {
            $productId = (int) Database::transaction(static function () use (
                $bundle,
                $categoryBySlug,
                $categoryByName
            ): int {
                $general = is_array($bundle['general'] ?? null) ? $bundle['general'] : [];
                $seoRow = is_array($bundle['seo'] ?? null) ? $bundle['seo'] : [];
                $catRaw = self::cell($general, 'category');
                $categoryId = self::resolveCategoryId($catRaw, $categoryBySlug, $categoryByName);
                if ($categoryId === null) {
                    throw new RuntimeException('Invalid category.');
                }
                $slugManual = self::cell($general, 'slug') !== '';
                $input = [
                    'name' => self::cell($general, 'product_name'),
                    'slug' => self::cell($general, 'slug'),
                    'slug_manual' => $slugManual ? '1' : '0',
                    'sku' => self::cell($general, 'sku'),
                    'category_id' => $categoryId,
                    'status' => 'draft',
                    'product_type' => strtolower(self::cell($general, 'product_type')),
                    'is_featured' => 0,
                    'is_bestseller' => 0,
                    'is_new_arrival' => 0,
                    'short_description' => self::cell($general, 'short_description'),
                    'description' => self::cell($general, 'full_description'),
                    'meta_title' => self::cell($seoRow, 'meta_title'),
                    'meta_description' => self::cell($seoRow, 'meta_description'),
                    'robots_index' => self::parseBool(self::cell($seoRow, 'allow_indexing')) ? '1' : '0',
                ];
                $save = ProductRepository::saveGeneral(null, $input);
                if (empty($save['ok']) || empty($save['id'])) {
                    $msg = $save['message'] ?? (isset($save['errors']) ? implode(' ', $save['errors']) : 'Could not save product.');
                    throw new RuntimeException(is_string($msg) ? $msg : 'Could not save product.');
                }
                $pid = (int) $save['id'];
                $variantRows = ProductAdminForm::variantRowsForRepository(
                    self::variantRowsFromImport(is_array($bundle['variants'] ?? null) ? $bundle['variants'] : [])
                );
                if ($variantRows !== []) {
                    $vRes = ProductRepository::saveVariants($pid, $variantRows);
                    if (empty($vRes['ok'])) {
                        throw new RuntimeException((string) ($vRes['message'] ?? 'Could not save variants.'));
                    }
                }
                $attrs = [];
                foreach (is_array($bundle['attributes'] ?? null) ? $bundle['attributes'] : [] as $a) {
                    $attrs[] = [
                        'label' => self::cell($a, 'attribute_label'),
                        'value' => self::cell($a, 'attribute_value'),
                    ];
                }
                ProductRepository::saveAttributes($pid, $attrs);

                return $pid;
            });
            self::importImages(
                $productId,
                is_array($bundle['images'] ?? null) ? $bundle['images'] : [],
                $imageIndex,
                $adminUserId
            );
        } catch (Throwable $e) {
            if ($productId > 0) {
                $cleanup = ProductRepository::deleteOrArchiveForAdmin($productId);
                if (empty($cleanup['ok'])) {
                    $detail = (string) ($cleanup['message'] ?? 'Could not remove failed import product.');
                    error_log('ProductBulkImport rollback failed for product #' . $productId . ': ' . $detail);

                    throw new RuntimeException($e->getMessage() . ' Rollback warning: ' . $detail);
                }
            }
            throw $e;
        }

        return $productId;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param array<string, string>|null $imageIndex
     */
    private static function importImages(int $productId, array $rows, ?array $imageIndex, ?int $adminUserId): void
    {
        if ($imageIndex === null || $rows === []) {
            return;
        }
        usort($rows, static function (array $a, array $b): int {
            $sa = (int) (self::cell($a, 'sort_order') !== '' ? self::cell($a, 'sort_order') : '0');
            $sb = (int) (self::cell($b, 'sort_order') !== '' ? self::cell($b, 'sort_order') : '0');

            return $sa <=> $sb;
        });
        $hasPrimary = false;
        foreach ($rows as $r) {
            if (self::parseBool(self::cell($r, 'is_primary'))) {
                $hasPrimary = true;
                break;
            }
        }
        $mediaIds = [];
        $primaries = [];
        foreach ($rows as $i => $r) {
            $basename = strtolower(basename(str_replace('\\', '/', self::cell($r, 'filename'))));
            if (!isset($imageIndex[$basename])) {
                throw new RuntimeException('Missing image file: ' . $basename);
            }
            $full = $imageIndex[$basename];
            $validImg = self::validateRasterImageFile($full);
            if (empty($validImg['ok'])) {
                throw new RuntimeException((string) ($validImg['message'] ?? 'Image validation failed for ' . $basename));
            }
            $upload = [
                'name' => basename($full),
                'type' => mime_content_type($full) ?: 'image/jpeg',
                'tmp_name' => $full,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($full),
            ];
            $ing = MediaLibrary::isEnabled()
                ? MediaLibrary::ingestUpload($upload, $adminUserId > 0 ? $adminUserId : null)
                : ['ok' => false];
            if (empty($ing['ok']) || empty($ing['media_id'])) {
                $legacy = Upload::storeProductImage($upload, 'products/' . $productId);
                if (empty($legacy['ok'])) {
                    throw new RuntimeException((string) ($legacy['message'] ?? 'Image ingest failed.'));
                }
                $isPrimary = self::parseBool(self::cell($r, 'is_primary')) || (!$hasPrimary && $i === 0);
                ProductRepository::addImage($productId, (string) $legacy['path'], $isPrimary);
            } else {
                $mid = (int) $ing['media_id'];
                $mediaIds[] = $mid;
                $primaries[$mid] = self::parseBool(self::cell($r, 'is_primary')) || (!$hasPrimary && $i === 0);
            }
        }
        if ($mediaIds !== []) {
            $attach = MediaLibrary::attachToProduct($productId, $mediaIds, !$hasPrimary);
            if (!empty($attach['errors'])) {
                throw new RuntimeException(implode(' ', $attach['errors']));
            }
            foreach ($attach['attached'] as $imageId) {
                // attachToProduct may not honour per-row primary; set via repository if needed
            }
            if (MediaLibrary::isEnabled()) {
                $imgs = ProductRepository::images($productId);
                $wanted = null;
                foreach ($mediaIds as $mid) {
                    if (!empty($primaries[$mid])) {
                        $wanted = $mid;
                        break;
                    }
                }
                if ($wanted === null && $mediaIds !== []) {
                    $wanted = $mediaIds[0];
                }
                foreach ($imgs as $img) {
                    if ((int) ($img['media_id'] ?? 0) === (int) $wanted) {
                        ProductImageAdminService::setPrimary($productId, (int) $img['id']);
                        break;
                    }
                }
                foreach ($imgs as $img) {
                    $alt = '';
                    foreach ($rows as $r) {
                        $base = strtolower(basename(str_replace('\\', '/', self::cell($r, 'filename'))));
                        if ($base !== '' && strpos((string) ($img['file_path'] ?? ''), $base) !== false) {
                            $alt = self::cell($r, 'alt_text');
                            break;
                        }
                    }
                    if ($alt !== '' && !empty($img['id'])) {
                        ProductRepository::updateImageAlt($productId, (int) $img['id'], $alt);
                    }
                }
            }
        }
        if (ProductRepository::imageCount($productId) > ProductRepository::MAX_IMAGES_PER_PRODUCT) {
            throw new RuntimeException('Image limit exceeded.');
        }
    }

    /** @param list<array<string, string>> $variantImportRows */
    /** @return list<array<string, mixed>> */
    private static function variantRowsFromImport(array $variantImportRows): array
    {
        $rows = [];
        foreach ($variantImportRows as $r) {
            $rows[] = [
                'id' => '',
                'name' => self::cell($r, 'variant_name'),
                'sku' => self::cell($r, 'sku'),
                'price' => self::cell($r, 'price'),
                'sale_price' => self::cell($r, 'sale_price'),
                'stock_qty' => self::cell($r, 'stock_quantity') !== '' ? self::cell($r, 'stock_quantity') : '0',
                'low_stock_threshold' => self::cell($r, 'low_stock_threshold') !== '' ? self::cell($r, 'low_stock_threshold') : '5',
                'max_qty_per_order' => self::cell($r, 'max_order_quantity'),
                'min_qty_per_order' => self::cell($r, 'min_order_quantity'),
                'is_active' => 1,
                'sort_order' => self::cell($r, 'sort_order'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private static function previewProduct(string $code, array $bundle): array
    {
        $g = is_array($bundle['general'] ?? null) ? $bundle['general'] : [];
        $seo = is_array($bundle['seo'] ?? null) ? $bundle['seo'] : [];

        return [
            'product_code' => $code,
            'general' => [
                'name' => self::cell($g, 'product_name'),
                'slug' => self::cell($g, 'slug'),
                'sku' => self::cell($g, 'sku'),
                'category' => self::cell($g, 'category'),
                'product_type' => self::cell($g, 'product_type'),
                'status_saved' => 'draft',
                'promotional_flags' => 'off (featured, bestseller, new_arrival)',
                'short_description' => self::cell($g, 'short_description'),
                'description' => self::cell($g, 'full_description'),
            ],
            'variants' => array_map(static function (array $r): array {
                return [
                    'name' => self::cell($r, 'variant_name'),
                    'sku' => self::cell($r, 'sku'),
                    'price' => self::cell($r, 'price'),
                    'sale_price' => self::cell($r, 'sale_price'),
                    'stock' => self::cell($r, 'stock_quantity'),
                ];
            }, is_array($bundle['variants'] ?? null) ? $bundle['variants'] : []),
            'attributes' => array_map(static function (array $r): array {
                return [
                    'label' => self::cell($r, 'attribute_label'),
                    'value' => self::cell($r, 'attribute_value'),
                ];
            }, is_array($bundle['attributes'] ?? null) ? $bundle['attributes'] : []),
            'images' => array_map(static function (array $r): array {
                return [
                    'filename' => self::cell($r, 'filename'),
                    'is_primary' => self::cell($r, 'is_primary'),
                    'sort_order' => self::cell($r, 'sort_order'),
                ];
            }, is_array($bundle['images'] ?? null) ? $bundle['images'] : []),
            'seo' => [
                'meta_title' => self::cell($seo, 'meta_title'),
                'meta_description' => self::cell($seo, 'meta_description'),
                'allow_indexing' => self::cell($seo, 'allow_indexing'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function activeCategories(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            "SELECT id, name, slug FROM categories WHERE status = 'published' ORDER BY sort_order, name"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, int> $bySlug
     * @param array<string, int> $byName
     */
    private static function resolveCategoryId(string $raw, array $bySlug, array $byName): ?int
    {
        $t = trim($raw);
        if ($t === '') {
            return null;
        }
        $slugKey = strtolower($t);
        if (isset($bySlug[$slugKey])) {
            return $bySlug[$slugKey];
        }
        $nameKey = strtolower($t);
        if (isset($byName[$nameKey])) {
            return $byName[$nameKey];
        }

        return null;
    }

    /** @param array<string, string> $row */
    private static function cell(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    /** @param array<string, string> $row */
    private static function rowNum(array $row): string
    {
        return (string) ($row['__row'] ?? '');
    }

    private static function parseBool(string $v): bool
    {
        $t = strtolower(trim($v));

        return in_array($t, ['1', 'yes', 'true', 'y', 'on'], true);
    }

    public static function cleanupJobExtractDir(?string $extractDir): void
    {
        if ($extractDir === null || $extractDir === '') {
            return;
        }
        $extractDir = realpath($extractDir);
        $jobsRoot = realpath(APNI_TOKRI_ROOT . '/uploads/tmp/bulk-import');
        if ($extractDir === false || $jobsRoot === false || !str_starts_with($extractDir, $jobsRoot)) {
            return;
        }
        self::rrmdir($extractDir);
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function cleanupJobAfterImport(string $jobId, array $state): void
    {
        $dir = isset($state['images_dir']) && is_string($state['images_dir']) ? $state['images_dir'] : null;
        self::cleanupJobExtractDir($dir);
    }

    public static function jobDir(string $jobId): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $jobId) ?? '';

        return APNI_TOKRI_ROOT . '/uploads/tmp/bulk-import/' . $safe;
    }

    public static function newJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @param array<string, mixed> $state */
    public static function saveJobState(string $jobId, array $state): void
    {
        $dir = self::jobDir($jobId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create import job directory.');
        }
        file_put_contents($dir . '/state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed>|null */
    public static function loadJobState(string $jobId): ?array
    {
        $path = self::jobDir($jobId) . '/state.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param list<array<string, mixed>> $bundled rebuild from validate
     * @return array<string, array<string, mixed>>
     */
    public static function bundleMapFromParsed(array $parsed, array $validationPreview): array
    {
        $codes = [];
        foreach ($parsed['products'] as $row) {
            $code = self::cell($row, 'product_code');
            if ($code === '') {
                continue;
            }
            $codes[$code] = [
                'general' => $row,
                'variants' => [],
                'attributes' => [],
                'images' => [],
                'seo' => null,
            ];
        }
        foreach ($parsed['variants'] as $row) {
            $c = self::cell($row, 'product_code');
            if (isset($codes[$c])) {
                $codes[$c]['variants'][] = $row;
            }
        }
        foreach ($parsed['attributes'] as $row) {
            $c = self::cell($row, 'product_code');
            if (isset($codes[$c])) {
                $codes[$c]['attributes'][] = $row;
            }
        }
        foreach ($parsed['images'] as $row) {
            $c = self::cell($row, 'product_code');
            if (isset($codes[$c])) {
                $codes[$c]['images'][] = $row;
            }
        }
        foreach ($parsed['seo'] as $row) {
            $c = self::cell($row, 'product_code');
            if (isset($codes[$c])) {
                $codes[$c]['seo'] = $row;
            }
        }

        return $codes;
    }

    /**
     * @param list<array{sheet: string, row: string, product_code: string, reason: string}> $errors
     * @param array<string, array<string, mixed>> $bundles
     * @return array<string, array<string, mixed>>
     */
    public static function filterImportableBundles(array $errors, array $bundles): array
    {
        $blocked = [];
        foreach ($errors as $e) {
            $code = (string) ($e['product_code'] ?? '');
            if ($code !== '') {
                $blocked[$code] = true;
            }
        }
        foreach ($errors as $e) {
            if (($e['product_code'] ?? '') === '' && ($e['sheet'] ?? '') === self::SHEET_PRODUCTS) {
                return [];
            }
        }
        $out = [];
        foreach ($bundles as $code => $bundle) {
            if (!isset($blocked[$code])) {
                $out[$code] = $bundle;
            }
        }

        return $out;
    }

    public static function writeReportCsv(string $path, array $reportRows): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        $out = fopen($path, 'wb');
        if ($out === false) {
            return false;
        }
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($reportRows as $line) {
            fputcsv($out, $line);
        }
        fclose($out);

        return true;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            if (is_dir($p)) {
                self::rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
