<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list HTML sanitizer for admin-authored rich text (product descriptions, CMS pages).
 *
 * Why not strip_tags(): it removes tags but keeps attributes, so <a href="javascript:â€¦" onclick="â€¦"> survives.
 * This parses the markup into a DOM and REBUILDS it from scratch: only allow-listed elements survive, only
 * allow-listed attributes are copied, URLs must use a safe scheme, and dangerous elements are dropped WITH their
 * content. The result is serialised from the DOM, so text is always escaped.
 */
final class HtmlSanitizer
{
    /** element => allowed attributes */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /** removed together with everything inside them */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template',
        'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'option', 'link', 'meta', 'base', 'title', 'head', 'audio', 'video', 'source', 'canvas'];

    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];
    private const IMAGE_SCHEMES = ['http', 'https'];

    public static function clean(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        // The XML prolog forces UTF-8; LIBXML_NONET blocks network fetches; no DTD/entity loading.
        $doc->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return e(strip_tags($html));
        }

        $out = new DOMDocument('1.0', 'UTF-8');
        $root = $out->createElement('div');
        $out->appendChild($root);
        self::copyChildren($body, $root, $out);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $out->saveHTML($child);
        }

        return trim($result);
    }

    private static function copyChildren(DOMNode $from, DOMNode $to, DOMDocument $out): void
    {
        foreach ($from->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $to->appendChild($out->createTextNode($node->nodeValue));

                continue;
            }
            if (! $node instanceof DOMElement) {
                continue;                                              // comments, processing instructions: dropped
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }
            if (! isset(self::ALLOWED[$tag])) {
                self::copyChildren($node, $to, $out);                  // unknown wrapper (div, span, fontâ€¦): keep its text, lose the tag

                continue;
            }

            $el = $out->createElement($tag);
            foreach (self::ALLOWED[$tag] as $attr) {
                if (! $node->hasAttribute($attr)) {
                    continue;
                }
                $value = $node->getAttribute($attr);

                if ($attr === 'href' && ! self::safeUrl($value, self::LINK_SCHEMES, true)) {
                    continue;
                }
                if ($attr === 'src' && ! self::safeUrl($value, self::IMAGE_SCHEMES, true)) {
                    continue;
                }
                if (in_array($attr, ['width', 'height'], true) && ! ctype_digit($value)) {
                    continue;
                }
                $el->setAttribute($attr, $value);
            }

            if ($tag === 'img' && ! $el->hasAttribute('src')) {
                continue;                                              // an image with no safe source is meaningless
            }
            if ($tag === 'a' && $el->hasAttribute('href')) {
                $el->setAttribute('rel', 'noopener noreferrer nofollow');
            }

            $to->appendChild($el);
            if (! in_array($tag, ['br', 'hr', 'img'], true)) {
                self::copyChildren($node, $el, $out);
            }
        }
    }

    /**
     * Only relative paths, fragments and allow-listed schemes. The value is normalised the way a browser would
     * (control characters and whitespace are ignored inside a scheme), which defeats "java\tscript:" tricks.
     */
    private static function safeUrl(string $url, array $schemes, bool $allowRelative): bool
    {
        $normalised = preg_replace('/[\x00-\x20\x7F-\x9F\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]+/u', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
        if ($normalised === '') {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalised, $m)) {
            return in_array(strtolower($m[1]), $schemes, true);
        }
        if (str_starts_with($normalised, '//')) {
            return false;                                              // protocol-relative: scheme unknown
        }

        return $allowRelative;                                         // /path, ../path, #anchor, ?query
    }
}
