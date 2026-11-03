<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/persistence.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';

Security::sendSecurityHeaders(Security::isHttps());

AdminGuard::requireAuthentication();
