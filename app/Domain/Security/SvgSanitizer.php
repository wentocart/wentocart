<?php

namespace App\Domain\Security;

/**
 * Allowlisted inline SVG for extension icons. Scripts, event handlers, and external refs are stripped.
 */
final class SvgSanitizer
{
    /** @var list<string> */
    protected const TAGS = ['svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon'];

    /** @var list<string> */
    protected const ATTRS = [
        'viewbox', 'xmlns', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap',
        'stroke-linejoin', 'fill-rule', 'fill-opacity', 'stroke-opacity', 'd', 'cx', 'cy', 'r', 'rx', 'ry',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'points', 'transform', 'opacity', 'aria-hidden', 'role', 'focusable',
    ];

    public static function clean(?string $markup): string
    {
        $markup = trim((string) $markup);
        if ($markup === '' || mb_strlen($markup) > 8000) {
            return '';
        }
        if (! preg_match('/^<svg\b/i', $markup)) {
            return '';
        }
        if (! preg_match('/xmlns=/i', $markup)) {
            $markup = preg_replace('/^<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $markup, 1) ?? $markup;
        }
        if (preg_match('/<(script|foreignobject|use|image|style|iframe|link|animate|set)\b/i', $markup)) {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $ok = @$dom->loadXML($markup, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok || ! $dom->documentElement) {
            return '';
        }

        $clean = self::copy($dom, $dom->documentElement);
        if (! $clean instanceof \DOMElement || strtolower($clean->tagName) !== 'svg') {
            return '';
        }
        $clean->setAttribute('aria-hidden', 'true');
        $clean->setAttribute('focusable', 'false');

        return $dom->saveXML($clean) ?: '';
    }

    protected static function copy(\DOMDocument $dom, \DOMNode $node): ?\DOMNode
    {
        if ($node instanceof \DOMText) {
            return $dom->createTextNode($node->textContent);
        }
        if (! $node instanceof \DOMElement) {
            return null;
        }
        $tag = strtolower($node->tagName);
        if (! in_array($tag, self::TAGS, true)) {
            return null;
        }
        $el = $dom->createElement($tag);
        foreach (iterator_to_array($node->attributes ?? []) as $attr) {
            $name = strtolower($attr->name);
            if (! in_array($name, self::ATTRS, true)) {
                continue;
            }
            $value = trim((string) $attr->value);
            if ($value === '' || preg_match('/javascript|data:|url\s*\(/i', $value)) {
                continue;
            }
            if ($name === 'xmlns' && $value !== 'http://www.w3.org/2000/svg') {
                continue;
            }
            $el->setAttribute($name === 'viewbox' ? 'viewBox' : $attr->name, $value);
        }
        foreach (iterator_to_array($node->childNodes) as $child) {
            $copied = self::copy($dom, $child);
            if ($copied) {
                $el->appendChild($copied);
            }
        }

        return $el;
    }
}
