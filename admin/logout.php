<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

AdminAuth::logout();
header('Location: ' . url_to('/admin/login.php'), true, 302);
exit;
