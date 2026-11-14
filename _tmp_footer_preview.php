<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require APNI_TOKRI_ROOT . '/includes/layout/head-public.php';
echo '<main id="main-content" class="at-main--with-bottom-nav flex-1"></main>';
$showBottomNav = true;
$activeNav = 'home';
$cartCount = 0;
require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
