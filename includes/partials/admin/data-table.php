<?php

declare(strict_types=1);

/** @var list<array<string, string>> $columns label => key; optional 'align' => 'num'|'actions', 'class' => extra cell classes */
/** @var list<array<string, string>> $rows */
/** @var string|null $tableClass */
/** @var string|null $emptyMessage */
$tableClass = ($tableClass ?? 'at-admin-table') . ' at-admin-table--cards';
$emptyMessage = $emptyMessage ?? 'No records found.';
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
                <?php foreach ($columns as $col): ?>
                    <th<?= $cellClass($col) ?>><?= Security::escape($col['label']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= count($columns) ?>" class="at-admin-table__empty"><?= Security::escape((string) $emptyMessage) ?></td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <td<?= $cellClass($col) ?> data-label="<?= Security::escape($col['label']) ?>"><?php
                            $cell = (string) ($row[$col['key']] ?? '');
                            if (in_array($col['key'], ['status', 'active', 'verified'], true) && $cell !== ''):
                                ?><span class="at-status <?= admin_status_class($cell) ?>"><?= Security::escape($cell) ?></span><?php
                            elseif ($col['key'] === 'link' && $cell !== ''):
                                if (!empty($row['action_href'])):
                                    ?><a class="font-bold hover:underline" href="<?= Security::escape((string) $row['action_href']) ?>"><?= Security::escape($cell) ?></a><?php
                                else:
                                    ?><span class="font-bold" style="color:var(--at-green)"><?= Security::escape($cell) ?></span><?php
                                endif;
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
