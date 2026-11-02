<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

header('Location: ' . url_to('/admin/dashboard.php'), true, 302);
exit;
