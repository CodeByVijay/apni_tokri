<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Csrf::requireValid();
}

$config = FirebaseProjectManager::publicWebConfig();
if ($config === null) {
    Ajax::success(['enabled' => false], 'Push not configured');
}

Ajax::success([
    'enabled' => true,
    'firebase' => [
        'apiKey' => $config['apiKey'],
        'authDomain' => $config['authDomain'],
        'projectId' => $config['projectId'],
        'storageBucket' => $config['storageBucket'],
        'messagingSenderId' => $config['messagingSenderId'],
        'appId' => $config['appId'],
        'measurementId' => $config['measurementId'],
    ],
    'vapidKey' => $config['vapidKey'],
    'configVersion' => $config['configVersion'],
    'serviceWorkerUrl' => url_base() . '/firebase-messaging-sw.js',
    'registerUrl' => url_base() . '/ajax/push-register.php',
    'unregisterUrl' => url_base() . '/ajax/push-unregister.php',
    'sdkVersion' => '13.2.0',
    'registrationModel' => 'fid',
], 'OK');
