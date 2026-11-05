<?php

declare(strict_types=1);

/**
 * Sanitizer for notification email inner HTML (not the branded wrapper).
 */
final class NotificationEmailHtmlSanitizer
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'p' => ['style'],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'code' => [],
        'h2' => [],
        'h3' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'a' => ['href', 'title', 'style', 'rel'],
    ];

    /** @var list<string> */
    private const DROP = [
        'script', 'iframe', 'object', 'embed', 'style', 'svg', 'form', 'input', 'button', 'textarea', 'link', 'meta',
    ];

    /**
     * @return array{ok: true, html: string}|array{ok: false, message: string}
     */
    public static function sanitize(string $html): array
    {
        if (!extension_loaded('dom')) {
            return ['ok' => false, 'message' => 'HTML sanitization unavailable.'];
        }
        $html = trim($html);
        if ($html === '') {
            return ['ok' => true, 'html' => ''];
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="utf-8" ?><div id="email-root">' . $html . '</div>';
        if (!$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            return ['ok' => false, 'message' => 'Malformed HTML could not be parsed safely.'];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('email-root');
        if ($root === null) {
            return ['ok' => false, 'message' => 'Malformed HTML could not be parsed safely.'];
        }

        self::walk($doc, $root);

        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return ['ok' => true, 'html' => $inner];
    }

    private static function walk(DOMDocument $doc, DOMNode $node): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }
        /** @var DOMElement $el */
        $el = $node;
        $tag = strtolower($el->tagName);
        if ($tag === 'div' && $el->getAttribute('id') === 'email-root') {
            foreach (iterator_to_array($el->childNodes) as $child) {
                self::walk($doc, $child);
            }

            return;
        }

        if (in_array($tag, self::DROP, true)) {
            if ($el->parentNode !== null) {
                $el->parentNode->removeChild($el);
            }

            return;
        }

        if ($tag === 'div') {
            self::unwrap($el);

            return;
        }

        if (!isset(self::ALLOWED[$tag])) {
            self::unwrap($el);

            return;
        }

        $allowedAttrs = self::ALLOWED[$tag];
        if ($el->hasAttributes()) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (!in_array($name, $allowedAttrs, true)) {
                    $el->removeAttribute($attr->name);
                    continue;
                }
                if ($name === 'href') {
                    $href = trim($el->getAttribute('href'));
                    if ($href === '' || !self::safeUrl($href)) {
                        self::unwrap($el);

                        return;
                    }
                    $el->setAttribute('href', $href);
                }
                if ($name === 'style') {
                    $safe = self::safeInlineStyle($el->getAttribute('style'));
                    if ($safe === '') {
                        $el->removeAttribute('style');
                    } else {
                        $el->setAttribute('style', $safe);
                    }
                }
            }
        }

        if ($tag === 'a' && !$el->hasAttribute('rel')) {
            $el->setAttribute('rel', 'noopener noreferrer');
        }

        foreach (iterator_to_array($el->childNodes) as $child) {
            self::walk($doc, $child);
        }
    }

    private static function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    private static function safeUrl(string $url): bool
    {
        return (bool) preg_match('/^(https?:\/\/|mailto:|\/|#)/i', $url);
    }

    private static function safeInlineStyle(string $style): string
    {
        $allowedProps = [
            'color', 'background', 'background-color', 'padding', 'padding-top', 'padding-right', 'padding-bottom',
            'padding-left', 'border-radius', 'text-decoration', 'font-weight', 'font-size', 'display', 'margin',
            'margin-top', 'margin-bottom',
        ];
        $out = [];
        foreach (explode(';', $style) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || strpos($chunk, ':') === false) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $chunk, 2));
            $prop = strtolower($prop);
            if (!in_array($prop, $allowedProps, true)) {
                continue;
            }
            if (preg_match('/expression|javascript:|url\s*\(/i', $val)) {
                continue;
            }
            $out[] = $prop . ': ' . $val;
        }

        return implode('; ', $out);
    }
}
