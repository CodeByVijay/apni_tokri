<?php

declare(strict_types=1);

/**
 * Branded HTML wrapper for customer notification emails (Apni Tokri).
 */
final class CustomerEmailLayout
{
    /**
     * Wrap rendered template body in a responsive, client-safe layout.
     */
    public static function wrap(string $innerHtml): string
    {
        $innerHtml = trim($innerHtml);
        if ($innerHtml === '') {
            return '';
        }
        $site = trim((string) (AppSettings::get('app.name', 'Apni Tokri') ?? 'Apni Tokri'));
        if ($site === '') {
            $site = 'Apni Tokri';
        }
        $year = date('Y');
        $siteEsc = Security::escape($site);
        $footer = Security::escape('© ' . $year . ' ' . $site . '. All rights reserved.');

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $siteEsc . '</title></head><body style="margin:0;padding:0;background:#f4f6f5;font-family:Helvetica,Arial,sans-serif;color:#1a1a1a;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e2e8e4;">'
            . '<tr><td style="background:#2d7048;padding:20px 24px;"><span style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:0.02em;">'
            . $siteEsc . '</span></td></tr>'
            . '<tr><td style="padding:24px;font-size:15px;line-height:1.55;">' . $innerHtml . '</td></tr>'
            . '<tr><td style="padding:16px 24px 24px;font-size:12px;color:#64748b;border-top:1px solid #e2e8e4;">'
            . $footer . '</td></tr></table></td></tr></table></body></html>';
    }
}
