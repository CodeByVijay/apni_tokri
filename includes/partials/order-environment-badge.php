<?php

declare(strict_types=1);

/** @var array{text: string, class: string}|null $orderEnvironmentBadge */
if (!isset($orderEnvironmentBadge) || $orderEnvironmentBadge === null) {
    return;
}
?>
<span class="<?= Security::escape((string) $orderEnvironmentBadge['class']) ?> ml-2"><?= Security::escape((string) $orderEnvironmentBadge['text']) ?></span>
