<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$inline = !empty($_GET['inline']);
BusinessSettings::streamUdyamCertificate($inline);
