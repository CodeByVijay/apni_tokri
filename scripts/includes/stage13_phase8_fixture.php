<?php

declare(strict_types=1);

function stage13_p8_cleanup(PDO $pdo): void
{
    $pdo->exec("DELETE FROM settings WHERE setting_key = '" . HomepageTrustService::SETTING_KEY . "'");
    $pdo->exec("DELETE FROM testimonials WHERE customer_display_name LIKE 'AT-HP8 %'");
}

function stage13_p8_render_home_trust(): string
{
    return HomepageTrustRenderer::renderAll(url_base(), demo_catalogue());
}
