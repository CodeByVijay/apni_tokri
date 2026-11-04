<?php

declare(strict_types=1);

/**
 * DOM-based HTML sanitizer for CMS body content (Stage 13).
 * Fails closed when ext-dom is unavailable.
 */
final class CmsHtmlSanitizer
{
    /** @var list<string> Removed entirely (including children), not unwrapped. */
    private const DROP_TAGS = [
        'script', 'iframe', 'object', 'embed', 'style', 'svg', 'math', 'link', 'meta', 'base',
        'form', 'input', 'button', 'textarea', 'select', 'option', 'video', 'audio', 'source',
        'frame', 'frameset', 'applet', 'foreignobject',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'a' => ['href', 'title', 'rel'],
        'img' => ['src', 'alt', 'title', 'loading', 'width', 'height'],
    ];

    /**
     * @return array{ok: true, html: string}|array{ok: false, message: string}
     */
    public static function sanitize(string $html): array
    {
        if (!extension_loaded('dom')) {
            return ['ok' => false, 'message' => 'HTML sanitization unavailable (DOM extension missing).'];
        }
        $html = trim($html);
        if ($html === '') {
            return ['ok' => true, 'html' => ''];
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="utf-8" ?><div id="cms-root">' . $html . '</div>';
        if (!$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            return ['ok' => false, 'message' => 'Malformed HTML could not be parsed safely.'];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('cms-root');
        if ($root === null) {
            return ['ok' => false, 'message' => 'Malformed HTML could not be parsed safely.'];
        }

        self::sanitizeNodeTree($doc, $root);

        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return ['ok' => true, 'html' => $inner];
    }

    private static function sanitizeNodeTree(DOMDocument $doc, DOMNode $node): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }
        /** @var DOMElement $el */
        $el = $node;
        $tag = strtolower($el->tagName);
        if ($tag === 'cms-root' || $tag === 'div' && $el->getAttribute('id') === 'cms-root') {
            foreach (iterator_to_array($el->childNodes) as $child) {
                self::sanitizeNodeTree($doc, $child);
            }

            return;
        }

        if (in_array($tag, self::DROP_TAGS, true)) {
            if ($el->parentNode !== null) {
                $el->parentNode->removeChild($el);
            }

            return;
        }

        if ($tag === 'div' || $tag === 'section' || $tag === 'article') {
            // Pasted rich text (browsers, Word, ChatGPT) wraps paragraphs in <div>. Keep the
            // block boundary: a leaf div becomes <p>; a div holding blocks is unwrapped.
            if (self::containsBlockDescendant($el)) {
                self::unwrapElement($doc, $el);
            } else {
                $p = $doc->createElement('p');
                while ($el->firstChild !== null) {
                    $p->appendChild($el->firstChild);
                }
                $el->parentNode->replaceChild($p, $el);
                $el = $p;
                $tag = 'p';
            }
            if ($tag === 'p') {
                foreach (iterator_to_array($el->childNodes) as $child) {
                    self::sanitizeNodeTree($doc, $child);
                }

                return;
            }

            return;
        }

        if (!isset(self::ALLOWED[$tag])) {
            self::unwrapElement($doc, $el);

            return;
        }

        $allowedAttrs = self::ALLOWED[$tag];
        if ($el->hasAttributes()) {
            $toRemove = [];
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                if (!in_array($name, $allowedAttrs, true)) {
                    $toRemove[] = $name;
                }
            }
            foreach ($toRemove as $name) {
                $el->removeAttribute($name);
            }
        }

        if ($tag === 'a') {
            $href = trim($el->getAttribute('href'));
            if ($href === '' || !self::isSafeUrl($href)) {
                self::unwrapElement($doc, $el);

                return;
            }
            $el->setAttribute('href', $href);
            if (!$el->hasAttribute('rel')) {
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($tag === 'img') {
            $src = trim($el->getAttribute('src'));
            if ($src === '' || !self::isSafeImageSrc($src)) {
                if ($el->parentNode !== null) {
                    $el->parentNode->removeChild($el);
                }

                return;
            }
            $el->setAttribute('src', $src);
            $loading = strtolower($el->getAttribute('loading'));
            if ($loading !== '' && !in_array($loading, ['lazy', 'eager'], true)) {
                $el->removeAttribute('loading');
            }
        }

        foreach (iterator_to_array($el->childNodes) as $child) {
            self::sanitizeNodeTree($doc, $child);
        }
    }

    private static function containsBlockDescendant(DOMElement $el): bool
    {
        foreach (['p', 'div', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'table', 'section', 'article'] as $t) {
            if ($el->getElementsByTagName($t)->length > 0) {
                return true;
            }
        }

        return false;
    }

    private static function unwrapElement(DOMDocument $doc, DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        $moved = [];
        while ($el->firstChild !== null) {
            $moved[] = $el->firstChild;
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
        // Moved children are no longer in the caller's snapshot; they must still be sanitized.
        foreach ($moved as $child) {
            self::sanitizeNodeTree($doc, $child);
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        if (str_starts_with($url, '#')) {
            return preg_match('/^#[a-zA-Z0-9_\-:.]+$/', $url) === 1;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && strpos($url, ':') === false;
        }
        if (preg_match('#^uploads/[a-zA-Z0-9/_.\-]+$#', $url) === 1) {
            return true;
        }
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function isSafeImageSrc(string $src): bool
    {
        if (self::isSafeUrl($src)) {
            return true;
        }
        if (str_starts_with($src, '/')) {
            return !str_starts_with($src, '//');
        }

        return preg_match('#^uploads/cms/[a-zA-Z0-9/_.\-]+$#', $src) === 1
            || preg_match('#^uploads/[a-zA-Z0-9/_.\-]+$#', $src) === 1;
    }
}
