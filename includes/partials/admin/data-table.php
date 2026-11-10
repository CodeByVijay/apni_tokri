<?php

declare(strict_types=1);

/** @var list<array<string, string>> $columns label => key; optional 'align' => 'num'|'actions', 'class' => extra cell classes */
/** @var list<array<string, string>> $rows */
/** @var list<array<string, string>>|null $tableRows */
/** @var string|null $tableClass */
/** @var string|null $emptyMessage */
/** @var bool $enableBulkSelect */
/** @var string|null $bulkFormId */
$rows = $rows ?? $tableRows ?? [];
$tableClass = ($tableClass ?? 'at-admin-table') . ' at-admin-table--cards';
$emptyMessage = $emptyMessage ?? 'No records found.';
$enableBulkSelect = !empty($enableBulkSelect);
$bulkFormId = isset($bulkFormId) && is_string($bulkFormId) && $bulkFormId !== '' ? $bulkFormId : '';
$cellClass = static function (array $col): string {
    $c = [];
    $align = (string) ($col['align'] ?? '');
    if ($align === 'num') {
        $c[] = 'is-num';
    } elseif ($align === 'actions') {
        $c[] = 'is-actions';
    }
    if (!empty($col['class'])) {
        $c[] = (string) $col['class'];
    }

    return $c === [] ? '' : ' class="' . Security::escape(implode(' ', $c)) . '"';
};
?>
<div class="at-admin-table-wrap">
    <table class="<?= Security::escape($tableClass) ?>">
        <thead>
            <tr>
                <?php if ($enableBulkSelect): ?>
                    <th class="is-bulk-select" scope="col">
                        <input type="checkbox" class="at-checkbox at-focusable" id="at-products-select-all" aria-label="Select all products on this page">
                    </th>
                <?php endif; ?>
                <?php foreach ($columns as $col): ?>
                    <th<?= $cellClass($col) ?>><?php
                        if (!empty($col['label_html'])) {
                            echo (string) $col['label_html'];
                        } else {
                            echo Security::escape($col['label']);
                        }
                    ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= count($columns) + ($enableBulkSelect ? 1 : 0) ?>" class="at-admin-table__empty"><?= Security::escape((string) $emptyMessage) ?></td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr<?= $enableBulkSelect ? ' data-at-product-row' : '' ?>>
                        <?php if ($enableBulkSelect):
                            $bid = (int) ($row['bulk_id'] ?? 0);
                            $bname = (string) ($row['bulk_name'] ?? '');
                            $bstatus = (string) ($row['bulk_status'] ?? '');
                            ?>
                            <td class="is-bulk-select" data-label="Select">
                                <input type="checkbox"
                                       class="at-checkbox at-focusable js-product-bulk-select"
                                       <?= $bulkFormId !== '' ? 'form="' . Security::escape($bulkFormId) . '" ' : '' ?>
                                       name="product_ids[]"
                                       value="<?= (int) $bid ?>"
                                       data-product-name="<?= Security::escape($bname) ?>"
                                       data-product-status="<?= Security::escape($bstatus) ?>"
                                       aria-label="<?= Security::escape('Select ' . $bname) ?>">
                            </td>
                        <?php endif; ?>
                        <?php foreach ($columns as $col): ?>
                            <td<?= $cellClass($col) ?> data-label="<?= Security::escape($col['label']) ?>"><?php
                            $cell = (string) ($row[$col['key']] ?? '');
                            if (in_array($col['key'], ['status', 'active', 'verified'], true) && $cell !== ''):
                                ?><span class="at-status <?= admin_status_class($cell) ?>"><?= Security::escape($cell) ?></span><?php
                            elseif ($col['key'] === 'link' && $cell !== ''):
                                if (!empty($row['action_href'])):
                                    ?><span class="at-admin-actions"><?= admin_action_edit_link((string) $row['action_href'], $cell) ?></span><?php
                                else:
                                    ?><span class="font-bold" style="color:var(--at-green)"><?= Security::escape($cell) ?></span><?php
                                endif;
                            elseif ($col['key'] === 'code' && $cell !== ''):
                                ?><span class="font-mono font-bold" style="color:var(--at-green)"><?= Security::escape($cell) ?></span><?php
                            elseif (!empty($col['raw'])):
                                echo (string) ($row[$col['key']] ?? '');
                            else:
                                echo Security::escape($cell);
                            endif;
                            ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
