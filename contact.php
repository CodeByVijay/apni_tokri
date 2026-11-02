<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Security::sendSecurityHeaders(Security::isHttps());

$seo = SeoMetadata::fromLegacy('Contact — ' . BrandingSettings::siteName(), 'Get in touch with ' . BusinessSettings::legalName() . ' for orders and support.');
layout_public_start(['seo' => $seo, 'activeNav' => '']);
?>
<div class="at-container py-10 md:py-14 max-w-2xl">
    <h1 class="at-h2">Contact <?= Security::escape(BusinessSettings::legalName()) ?></h1>
    <p class="at-lead mt-2">Reach our support team for order help, delivery questions or feedback.</p>

    <div class="at-card mt-8">
        <div class="at-card__body space-y-4 text-sm">
            <?php
            $phone = BusinessSettings::supportPhoneDisplay();
            $tel = BusinessSettings::supportPhoneTelUrl();
            $email = BusinessSettings::supportEmail();
            $wa = BusinessSettings::whatsappUrl();
            $hasAny = $phone !== null || $email !== null || $wa !== null;
            ?>
            <?php if ($phone !== null && $tel !== null): ?>
                <p class="flex items-center gap-2"><?= at_icon('phone', 'w-5 h-5 text-brand-green') ?>
                    <a class="font-semibold underline at-focusable" href="<?= Security::escape($tel) ?>"><?= Security::escape($phone) ?></a>
                </p>
            <?php endif; ?>
            <?php if ($email !== null): ?>
                <p class="flex items-center gap-2"><?= at_icon('mail', 'w-5 h-5 text-brand-green') ?>
                    <a class="font-semibold underline at-focusable" href="mailto:<?= Security::escape($email) ?>"><?= Security::escape($email) ?></a>
                </p>
            <?php endif; ?>
            <?php if ($wa !== null): ?>
                <p class="flex items-center gap-2"><?= at_icon('chat', 'w-5 h-5 text-brand-green') ?>
                    <a class="font-semibold underline at-focusable" href="<?= Security::escape($wa) ?>" target="_blank" rel="noopener noreferrer">Chat on WhatsApp</a>
                </p>
            <?php endif; ?>
            <?php if (!$hasAny): ?>
                <p class="at-muted">Support contact details will appear here once configured in Admin → Settings → Business.</p>
            <?php endif; ?>
            <p class="pt-2 border-t border-[var(--at-line)]"><a class="at-btn at-btn--secondary at-focusable" href="<?= Security::escape(url_base()) ?>/support.php">Raise a support ticket</a></p>
        </div>
    </div>
</div>
<?php layout_public_end(); ?>
