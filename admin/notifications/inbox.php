<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

header('Location: ' . url_base() . '/admin/notifications/index.php', true, 301);
exit;
