<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/spreadsheet/simple_xlsx.php';

/**
 * Admin bulk product export (read-only; mirrors import workbook shape).
 */
final class ProductBulkExport
{
    public const MAX_EXPORT = 2000;
    public const SCOPE_ALL = 'all';
    public const SCOPE_FILTERED = 'filtered';
    public const SCOPE_SELECTED = 'selected';
    public const DOWNLOAD_XLSX_NAME = 'apni-tokri-product-export.xlsx';
    public const DOWNLOAD_PACKAGE_NAME = 'apni-tokri-product-export-package.zip';

    public static function productCode(int $productId): string
    {
        return 'P-' . $productId;
    }

    /** @return list<string> */
    public static function productsExportHeaders(): array
    {
        return [
            'export_product_id', 'product_code', 'product_name', 'slug', 'sku', 'category', 'status',
            'product_type', 'short_description', 'full_description',
        ];
    }

    /**
     * @param array{scope: string, query?: string, category?: string, status?: string, product_ids?: list<int>} $filters
     * @return array{ok: bool, count?: int, capped?: bool, message?: string}
     */
    public static function resolveExportCount(array $filters): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database not available.'];
        }
        $scope = (string) ($filters['scope'] ?? self::SCOPE_FILTERED);
        if ($scope === self::SCOPE_SELECTED) {
            $ids = ProductRepository::filterExistingProductIds($filters['product_ids'] ?? []);
            if ($ids === []) {
                return ['ok' => true, 'count' => 0, 'capped' => false];
            }

            return [
                'ok' => true,
                'count' => count($ids),
                'capped' => count($ids) > self::MAX_EXPORT,
            ];
        }
        if ($scope === self::SCOPE_ALL) {
            $total = ProductRepository::countAdminExport('', '', '');

            return ['ok' => true, 'count' => min($total, self::MAX_EXPORT), 'capped' => $total > self::MAX_EXPORT];
        }
        $q = trim((string) ($filters['query'] ?? ''));
        $cat = trim((string) ($filters['category'] ?? ''));
        $st = trim((string) ($filters['status'] ?? ''));
        $total = ProductRepository::countAdminExport($q, $cat, $st);

        return ['ok' => true, 'count' => min($total, self::MAX_EXPORT), 'capped' => $total > self::MAX_EXPORT];
    }

    /**
     * @param array{scope: string, query?: string, category?: string, status?: string, product_ids?: list<int>} $filters
     * @return array{ok: bool, product_ids?: list<int>, message?: string}
     */
    public static function resolveExportProductIds(array $filters): array
    {
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database not available.'];
        }
        $scope = (string) ($filters['scope'] ?? self::SCOPE_FILTERED);
        if ($scope === self::SCOPE_SELECTED) {
            $ids = ProductRepository::filterExistingProductIds($filters['product_ids'] ?? []);
            if ($ids === []) {
                return ['ok' => false, 'message' => 'No valid products selected.'];
            }
            if (count($ids) > self::MAX_EXPORT) {
                return ['ok' => false, 'message' => 'Too many products selected (max ' . self::MAX_EXPORT . ').'];
            }

            return ['ok' => true, 'product_ids' => $ids];
        }
        if ($scope === self::SCOPE_ALL) {
            $ids = ProductRepository::idsForAdminExport('', '', '', self::MAX_EXPORT);
        } else {
            $q = trim((string) ($filters['query'] ?? ''));
            $cat = trim((string) ($filters['category'] ?? ''));
            $st = trim((string) ($filters['status'] ?? ''));
            $ids = ProductRepository::idsForAdminExport($q, $cat, $st, self::MAX_EXPORT);
        }
        if ($ids === []) {
            return ['ok' => false, 'message' => 'No products match this export.'];
        }

        return ['ok' => true, 'product_ids' => $ids];
    }

    /**
     * @param list<int> $productIds
     * @return array{
     *   ok: bool,
     *   sheets?: array<string, list<list<string>>>,
     *   image_files?: array<string, string>,
     *   missing_images?: list<string>,
     *   message?: string
     * }
     */
    public static function buildExportData(array $productIds, bool $collectImageFiles): array
    {
        $productIds = ProductRepository::filterExistingProductIds($productIds);
        if ($productIds === []) {
            return ['ok' => false, 'message' => 'No products to export.'];
        }
        $products = ProductRepository::rowsForExportByIds($productIds);
        $variants = ProductRepository::variantsGroupedByProductIds($productIds);
        $attributes = ProductRepository::attributesGroupedByProductIds($productIds);
        $images = ProductRepository::imagesGroupedByProductIds($productIds);

        $productRows = [self::productsExportHeaders()];
        $variantRows = [ProductBulkImport::variantsHeaders()];
        $attrRows = [ProductBulkImport::attributesHeaders()];
        $imageRows = [ProductBulkImport::imagesHeaders()];
        $seoRows = [ProductBulkImport::seoHeaders()];

        $imageFiles = [];
        $missingImages = [];
        $zipBasenameUsed = [];
        $basenameByPath = [];

        foreach ($products as $p) {
            $pid = (int) ($p['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $code = self::productCode($pid);
            $catName = trim((string) ($p['category_name'] ?? ''));
            if ($catName === '') {
                $catName = (string) ($p['category_slug'] ?? '');
            }
            $productRows[] = [
                (string) $pid,
                $code,
                (string) ($p['name'] ?? ''),
                (string) ($p['slug'] ?? ''),
                (string) ($p['sku'] ?? ''),
                $catName,
                (string) ($p['status'] ?? ''),
                (string) ($p['product_type'] ?? ''),
                (string) ($p['short_description'] ?? ''),
                (string) ($p['description'] ?? ''),
            ];
            foreach ($variants[$pid] ?? [] as $v) {
                $sale = $v['sale_price'] ?? null;
                $variantRows[] = [
                    $code,
                    (string) ($v['name'] ?? ''),
                    (string) ($v['sku'] ?? ''),
                    (string) ($v['price'] ?? ''),
                    $sale !== null && $sale !== '' ? (string) $sale : '',
                    (string) ($v['stock_qty'] ?? '0'),
                    (string) ($v['low_stock_threshold'] ?? ''),
                    (string) ($v['min_qty_per_order'] ?? ''),
                    (string) ($v['max_qty_per_order'] ?? ''),
                    (string) ($v['sort_order'] ?? ''),
                ];
            }
            foreach ($attributes[$pid] ?? [] as $a) {
                $attrRows[] = [
                    $code,
                    (string) ($a['attr_key'] ?? ''),
                    (string) ($a['attr_value'] ?? ''),
                    (string) ($a['sort_order'] ?? ''),
                ];
            }
            foreach ($images[$pid] ?? [] as $img) {
                $filePath = (string) ($img['file_path'] ?? '');
                $basename = self::imageBasenameForRow($img, $pid, $zipBasenameUsed, $basenameByPath);
                $imageRows[] = [
                    $code,
                    $basename,
                    !empty($img['is_primary']) ? 'yes' : 'no',
                    (string) ($img['sort_order'] ?? ''),
                    (string) ($img['alt_text'] ?? ''),
                ];
                if ($collectImageFiles && $basename !== '') {
                    $abs = self::resolveSafeImagePath($filePath);
                    if ($abs === null) {
                        $missingImages[] = $code . ': ' . $basename;
                        continue;
                    }
                    if (!isset($imageFiles[$basename])) {
                        $imageFiles[$basename] = $abs;
                    }
                }
            }
            $seoRows[] = [
                $code,
                (string) ($p['meta_title'] ?? ''),
                (string) ($p['meta_description'] ?? ''),
                !empty($p['robots_index']) ? 'yes' : 'no',
            ];
        }

        ['categories' => $categories, 'types' => $types] = self::referenceLists();

        $sheets = [
            ProductBulkImport::SHEET_INSTRUCTIONS => self::instructionsSheetRows(),
            ProductBulkImport::SHEET_PRODUCTS => $productRows,
            ProductBulkImport::SHEET_VARIANTS => $variantRows,
            ProductBulkImport::SHEET_ATTRIBUTES => $attrRows,
            ProductBulkImport::SHEET_IMAGES => $imageRows,
            ProductBulkImport::SHEET_SEO => $seoRows,
            ProductBulkImport::SHEET_REFERENCE => self::referenceSheetRows($categories, $types),
        ];

        return [
            'ok' => true,
            'sheets' => $sheets,
            'image_files' => $imageFiles,
            'missing_images' => $missingImages,
        ];
    }

    /**
     * @param array{scope: string, query?: string, category?: string, status?: string, product_ids?: list<int>, include_images?: bool} $filters
     * @return array{ok: bool, path?: string, mime?: string, filename?: string, message?: string}
     */
    public static function prepareDownloadFile(array $filters): array
    {
        $resolved = self::resolveExportProductIds($filters);
        if (empty($resolved['ok'])) {
            return ['ok' => false, 'message' => $resolved['message'] ?? 'Export failed.'];
        }
        $includeImages = !empty($filters['include_images']);
        $built = self::buildExportData($resolved['product_ids'], $includeImages);
        if (empty($built['ok']) || empty($built['sheets'])) {
            return ['ok' => false, 'message' => $built['message'] ?? 'Could not build export.'];
        }

        $xlsxPath = self::tempDownloadPath('export', 'xlsx');
        if (!SimpleXlsx::write($built['sheets'], $xlsxPath)) {
            self::unlinkQuiet($xlsxPath);

            return ['ok' => false, 'message' => 'Could not write workbook.'];
        }

        if (!$includeImages) {
            return [
                'ok' => true,
                'path' => $xlsxPath,
                'mime' => AdminFileDownload::MIME_XLSX,
                'filename' => self::DOWNLOAD_XLSX_NAME,
            ];
        }

        $buildDir = dirname($xlsxPath) . '/pkg-' . bin2hex(random_bytes(4));
        if (!@mkdir($buildDir, 0755, true)) {
            self::unlinkQuiet($xlsxPath);

            return ['ok' => false, 'message' => 'Could not create package workspace.'];
        }
        $pkgXlsx = $buildDir . '/' . self::DOWNLOAD_XLSX_NAME;
        if (!@rename($xlsxPath, $pkgXlsx)) {
            @copy($xlsxPath, $pkgXlsx);
            self::unlinkQuiet($xlsxPath);
        }

        $imagesZip = $buildDir . '/images.zip';
        $zipResult = self::writeImagesZip($built['image_files'] ?? [], $imagesZip);
        if (empty($zipResult['ok'])) {
            self::removeDirRecursive($buildDir);
            self::unlinkQuiet($xlsxPath);

            return ['ok' => false, 'message' => $zipResult['message'] ?? 'Could not build images ZIP.'];
        }

        $summary = self::exportSummaryText(
            count($resolved['product_ids']),
            $built['missing_images'] ?? [],
            $zipResult['added'] ?? 0,
            $zipResult['skipped'] ?? 0
        );
        file_put_contents($buildDir . '/export-summary.txt', $summary);

        $outerPath = self::tempDownloadPath('export-pkg', 'zip');
        $outer = new ZipArchive();
        if ($outer->open($outerPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::removeDirRecursive($buildDir);

            return ['ok' => false, 'message' => 'Could not create export package.'];
        }
        $outer->addFile($pkgXlsx, self::DOWNLOAD_XLSX_NAME);
        $outer->addFile($imagesZip, 'images.zip');
        $outer->addFile($buildDir . '/export-summary.txt', 'export-summary.txt');
        $outer->close();
        self::removeDirRecursive($buildDir);

        return [
            'ok' => true,
            'path' => $outerPath,
            'mime' => AdminFileDownload::MIME_ZIP,
            'filename' => self::DOWNLOAD_PACKAGE_NAME,
        ];
    }

    public static function unlinkDownloadTempFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }
        $real = realpath($path);
        $tmpRoot = realpath(sys_get_temp_dir());
        if ($real !== false && $tmpRoot !== false && str_starts_with($real, $tmpRoot)) {
            @unlink($real);

            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string, string> $imageFiles basename => absolute path
     * @return array{ok: bool, added?: int, skipped?: int, message?: string}
     */
    private static function writeImagesZip(array $imageFiles, string $destZip): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'message' => 'ZIP extension unavailable.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($destZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'message' => 'Could not open images ZIP.'];
        }
        $added = 0;
        $skipped = 0;
        $seenPath = [];
        foreach ($imageFiles as $basename => $absPath) {
            $safeName = self::safeZipEntryName($basename);
            if ($safeName === '') {
                $skipped++;
                continue;
            }
            $real = realpath($absPath);
            if ($real === false || !is_file($real) || !is_readable($real)) {
                $skipped++;
                continue;
            }
            if (isset($seenPath[$real])) {
                continue;
            }
            $seenPath[$real] = true;
            if (!$zip->addFile($real, $safeName)) {
                $skipped++;
                continue;
            }
            $added++;
        }
        $zip->close();

        return ['ok' => true, 'added' => $added, 'skipped' => $skipped];
    }

    private static function safeZipEntryName(string $basename): string
    {
        $basename = str_replace('\\', '/', $basename);
        $basename = basename($basename);
        $basename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $basename) ?? '';
        $basename = trim($basename, '.-');
        if ($basename === '' || str_contains($basename, '..')) {
            return '';
        }

        return $basename;
    }

    /**
     * @param array<string, mixed> $imageRow
     * @param array<string, true> $usedBasenames
     * @param array<string, string> $basenameByPath
     */
    private static function imageBasenameForRow(
        array $imageRow,
        int $productId,
        array &$usedBasenames,
        array &$basenameByPath
    ): string {
        $path = (string) ($imageRow['file_path'] ?? '');
        if ($path !== '' && isset($basenameByPath[$path])) {
            return $basenameByPath[$path];
        }
        $base = self::safeZipEntryName(basename($path));
        if ($base === '') {
            $base = 'product-' . $productId . '-' . (int) ($imageRow['sort_order'] ?? 0) . '.jpg';
        }
        if (isset($usedBasenames[$base])) {
            $base = 'p' . $productId . '-' . (int) ($imageRow['id'] ?? 0) . '-' . $base;
        }
        $usedBasenames[$base] = true;
        if ($path !== '') {
            $basenameByPath[$path] = $base;
        }

        return $base;
    }

    public static function resolveSafeImagePath(string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === '' || str_contains($relativePath, '..') || !str_starts_with($relativePath, 'uploads/')) {
            return null;
        }
        $full = APNI_TOKRI_ROOT . '/' . ltrim($relativePath, '/');
        $real = realpath($full);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $root = realpath(APNI_TOKRI_ROOT);
        $uploadsRoot = realpath(APNI_TOKRI_ROOT . '/uploads');
        if ($root === false || $uploadsRoot === false || !str_starts_with($real, $uploadsRoot)) {
            return null;
        }

        return $real;
    }

    /** @return list<list<string>> */
    private static function instructionsSheetRows(): array
    {
        return [
            ['Bulk product export — read-only snapshot'],
            [''],
            ['This workbook is an export of your catalogue. Importing it back will not update existing products unless you use Bulk Import rules separately.'],
            ['export_product_id'],
            ['Stable numeric product ID from the database — use for reference and matching exports to admin records.'],
            ['product_code'],
            ['Links rows across sheets (P-{id}). Not stored in the database; generated for export only.'],
            [''],
            ['Products sheet includes status (draft, published, archived). Import template omits status because import always creates drafts.'],
            ['Images sheet lists basenames only. When you download Excel + Images ZIP, files are in images.zip.'],
            ['Reference sheet lists published categories and active product types for documentation.'],
        ];
    }

    /**
     * @return array{categories: list<array{0: string, 1: string}>, types: list<array{0: string, 1: string}>}
     */
    private static function referenceLists(): array
    {
        $categories = [];
        $types = [];
        if (Install::isSchemaReady()) {
            Install::ensureConnection();
            foreach (CategoryRepository::allForAdmin() as $c) {
                if ((string) ($c['status'] ?? '') !== 'published') {
                    continue;
                }
                $categories[] = [(string) ($c['name'] ?? ''), (string) ($c['slug'] ?? '')];
            }
            foreach (ProductTypeRepository::optionsForProductForm() as $t) {
                $types[] = [(string) ($t['code'] ?? ''), (string) ($t['name'] ?? '')];
            }
        }

        return ['categories' => $categories, 'types' => $types];
    }

    /**
     * @param list<array{0: string, 1: string}> $categories
     * @param list<array{0: string, 1: string}> $types
     * @return list<list<string>>
     */
    private static function referenceSheetRows(array $categories, array $types): array
    {
        $max = max(count($categories), count($types));
        $rows = [
            ['Categories (published)', '', 'Product types (active code)', ''],
            ['Category name', 'Category slug', 'Type code', 'Type name'],
        ];
        for ($i = 0; $i < $max; $i++) {
            $rows[] = [
                $categories[$i][0] ?? '',
                $categories[$i][1] ?? '',
                $types[$i][0] ?? '',
                $types[$i][1] ?? '',
            ];
        }
        if ($max === 0) {
            $rows[] = ['', '', ProductTypeRepository::defaultCode(), 'Fresh produce'];
        }

        return $rows;
    }

    /**
     * @param list<string> $missing
     */
    private static function exportSummaryText(int $productCount, array $missing, int $imagesAdded, int $imagesSkipped): string
    {
        $lines = [
            'Apni Tokri product export summary',
            'Products exported: ' . $productCount,
            'Images in ZIP: ' . $imagesAdded,
            'Image files skipped/unreadable: ' . $imagesSkipped,
        ];
        if ($missing !== []) {
            $lines[] = '';
            $lines[] = 'Missing image files:';
            foreach ($missing as $m) {
                $lines[] = '  - ' . $m;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private static function tempDownloadPath(string $prefix, string $ext): string
    {
        $ext = preg_replace('/[^a-z0-9]+/i', '', $ext) ?? 'bin';
        $safePrefix = preg_replace('/[^a-z0-9_-]+/i', '', $prefix) ?? 'dl';

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR . '/\\')
            . DIRECTORY_SEPARATOR
            . 'at-be-' . $safePrefix . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    }

    private static function unlinkQuiet(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private static function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
