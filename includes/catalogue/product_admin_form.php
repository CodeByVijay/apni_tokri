<?php

declare(strict_types=1);

/** Parse and validate admin product edit form (variants & attributes). */
final class ProductAdminForm
{
    /**
     * @param array<string, mixed> $post
     * @return list<array<string, mixed>>
     */
    public static function variantRowsFromPost(array $post): array
    {
        $rows = [];
        $vNames = $post['variant_name'] ?? [];
        if (!is_array($vNames)) {
            return [];
        }
        $vIds = $post['variant_id'] ?? [];
        $vSkus = $post['variant_sku'] ?? [];
        $vPrices = $post['variant_price'] ?? [];
        $vSales = $post['variant_sale'] ?? [];
        $vStocks = $post['variant_stock'] ?? [];
        $vLows = $post['variant_low'] ?? [];
        $vMaxes = $post['variant_max'] ?? [];
        foreach ($vNames as $i => $vname) {
            $maxRaw = is_array($vMaxes) ? ($vMaxes[$i] ?? '') : '';
            $rows[] = [
                'id' => is_array($vIds) ? ($vIds[$i] ?? '') : '',
                'name' => $vname,
                'sku' => is_array($vSkus) ? ($vSkus[$i] ?? '') : '',
                'price' => is_array($vPrices) ? ($vPrices[$i] ?? '') : '',
                'sale_price' => is_array($vSales) ? ($vSales[$i] ?? '') : '',
                'stock_qty' => is_array($vStocks) ? ($vStocks[$i] ?? 0) : 0,
                'low_stock_threshold' => is_array($vLows) ? ($vLows[$i] ?? 5) : 5,
                'max_qty_per_order' => $maxRaw,
                'is_active' => 1,
                'sort_order' => $i,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    public static function validateVariantRows(array $rows): array
    {
        $errors = [];
        foreach ($rows as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $sku = trim((string) ($row['sku'] ?? ''));
            $priceRaw = trim((string) ($row['price'] ?? ''));
            $saleRaw = trim((string) ($row['sale_price'] ?? ''));
            $stockRaw = trim((string) ($row['stock_qty'] ?? ''));
            $hasOther = $sku !== '' || $priceRaw !== '' || $saleRaw !== '' || ($stockRaw !== '' && $stockRaw !== '0');
            if ($name === '') {
                if ($hasOther) {
                    $errors['variants'] = 'Each variant row with SKU, price, or stock needs a variant name (row ' . ($i + 1) . ').';
                }
                continue;
            }
            if ($priceRaw === '') {
                $errors['variants'] = 'Enter a price for variant “' . $name . '”.';
                break;
            }
            if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
                $errors['variants'] = 'Variant price must be zero or greater for “' . $name . '”.';
                break;
            }
            if ($saleRaw !== '' && (!is_numeric($saleRaw) || (float) $saleRaw < 0)) {
                $errors['variants'] = 'Sale price must be zero or greater for “' . $name . '”.';
                break;
            }
            $maxRaw = trim((string) ($row['max_qty_per_order'] ?? ''));
            if ($maxRaw !== '' && $maxRaw !== '0' && (!ctype_digit($maxRaw) || (int) $maxRaw < 1)) {
                $errors['variants'] = 'Max per order must be a positive number or 0 for unlimited (“' . $name . '”).';
                break;
            }
        }

        return $errors;
    }

    /**
     * Rows for ProductRepository::saveVariants (numeric price, etc.).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function variantRowsForRepository(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $out[] = [
                'id' => $row['id'] ?? '',
                'name' => $row['name'] ?? '',
                'sku' => $row['sku'] ?? '',
                'price' => $row['price'] ?? 0,
                'sale_price' => $row['sale_price'] ?? '',
                'stock_qty' => $row['stock_qty'] ?? 0,
                'low_stock_threshold' => $row['low_stock_threshold'] ?? 5,
                'max_qty_per_order' => $row['max_qty_per_order'] ?? '',
                'is_active' => 1,
                'sort_order' => $i,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array<string, mixed>>
     */
    public static function variantsForDisplay(array $post): array
    {
        $rows = [];
        foreach (self::variantRowsFromPost($post) as $row) {
            $sale = trim((string) ($row['sale_price'] ?? ''));
            $rows[] = [
                'id' => $row['id'] ?? '',
                'name' => (string) ($row['name'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'price' => (string) ($row['price'] ?? ''),
                'sale_price' => $sale === '' ? null : $sale,
                'stock_qty' => (int) ($row['stock_qty'] ?? 0),
                'low_stock_threshold' => (int) ($row['low_stock_threshold'] ?? 5),
                'max_qty_per_order' => $row['max_qty_per_order'] ?? CartQuantityRules::DEFAULT_MAX_PER_ORDER,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{label: string, value: string}>
     */
    public static function attributesFromPost(array $post): array
    {
        $attrs = [];
        $labels = $post['attr_label'] ?? [];
        $values = $post['attr_value'] ?? [];
        if (!is_array($labels)) {
            return [];
        }
        foreach ($labels as $i => $label) {
            $attrs[] = [
                'label' => $label,
                'value' => is_array($values) ? ($values[$i] ?? '') : '',
            ];
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function validateAttributesFromPost(array $post): array
    {
        $errors = [];
        foreach (self::attributesFromPost($post) as $i => $a) {
            $key = trim((string) ($a['label'] ?? ''));
            $val = trim((string) ($a['value'] ?? ''));
            if ($key === '' && $val === '') {
                continue;
            }
            if ($key === '') {
                $errors['attributes'] = 'Attribute label is required when a value is provided (row ' . ($i + 1) . ').';
                break;
            }
            if ($val === '') {
                $errors['attributes'] = 'Attribute value is required for “' . $key . '”.';
                break;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array{attr_key: string, attr_value: string}>
     */
    public static function attributesForDisplay(array $post): array
    {
        $out = [];
        foreach (self::attributesFromPost($post) as $a) {
            $out[] = [
                'attr_key' => (string) ($a['label'] ?? ''),
                'attr_value' => (string) ($a['value'] ?? ''),
            ];
        }

        return $out;
    }
}
