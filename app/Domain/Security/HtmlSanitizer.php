<?php

namespace App\Domain\Security;

/**
 * Allowlisted HTML for merchant/seller rich text (TipTap / admin Editor).
 * Scripts, event handlers, and unsafe URLs are stripped; legitimate RTE markup is kept.
 */
final class HtmlSanitizer
{
    /** @var list<string> */
    public const ALLOWED_TAGS = [
        'p', 'br', 'hr',
        'strong', 'em', 'b', 'i', 'u', 's', 'strike', 'del',
        'sub', 'sup', 'mark', 'code', 'pre',
        'ul', 'ol', 'li',
        'a',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'span', 'div',
        'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    ];

    /** Safe inline style properties emitted by the admin TipTap editor. */
    private const ALLOWED_STYLE_PROPS = [
        'color',
        'background-color',
        'background',
        'text-align',
        'font-size',
        'font-weight',
        'font-style',
        'text-decoration',
        'width',
        'height',
        'max-width',
        'border-collapse',
        'border',
        'border-color',
        'border-width',
        'border-style',
        'padding',
        'margin',
        'margin-left',
        'margin-right',
        'vertical-align',
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        if (mb_strlen($html) > 100_000) {
            $html = mb_substr($html, 0, 100_000);
        }

        $html = preg_replace('#<(script|iframe|object|embed|form|link|meta|svg|style|video|audio)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|iframe|object|embed|form|link|meta|svg|style)[^>]*>#is', '', $html) ?? $html;

        $allowed = '<'.implode('><', self::ALLOWED_TAGS).'>';
        $html = strip_tags($html, $allowed);

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div>'.$html.'</div>';
        $dom->loadHTML('<?xml encoding="UTF-8">'.$wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::scrub($dom->documentElement);

        $out = '';
        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root) {
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
        }

        return trim($out);
    }

    protected static function scrub(?\DOMNode $node): void
    {
        if (! $node) {
            return;
        }
        $remove = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    $remove[] = $child;
                    continue;
                }
                foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                    $name = strtolower($attr->name);
                    if (str_starts_with($name, 'on') || in_array($name, ['srcset', 'srcdoc', 'formaction', 'xlink:href'], true)) {
                        $child->removeAttribute($attr->name);
                        continue;
                    }
                    if ($name === 'style') {
                        $safe = self::safeInlineStyle($attr->value);
                        if ($safe === '') {
                            $child->removeAttribute('style');
                        } else {
                            $child->setAttribute('style', $safe);
                        }
                        continue;
                    }
                    if ($name === 'src') {
                        if ($tag !== 'img' || ! self::isSafeSrc($attr->value)) {
                            $child->removeAttribute('src');
                        }
                        continue;
                    }
                    if ($name === 'href') {
                        $href = trim($attr->value);
                        if (! preg_match('#^(https?:|mailto:|/[^/]|/)#i', $href) || preg_match('#^(javascript|data|vbscript):#i', $href)) {
                            $child->removeAttribute('href');
                        }
                        continue;
                    }
                    if ($name === 'target') {
                        $target = strtolower(trim($attr->value));
                        if (! in_array($target, ['_blank', '_self'], true)) {
                            $child->removeAttribute('target');
                        } elseif ($target === '_blank') {
                            $rel = strtolower((string) $child->getAttribute('rel'));
                            $parts = preg_split('/\s+/', $rel, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                            foreach (['noopener', 'noreferrer'] as $token) {
                                if (! in_array($token, $parts, true)) {
                                    $parts[] = $token;
                                }
                            }
                            $child->setAttribute('rel', implode(' ', $parts));
                        }
                        continue;
                    }
                    if (in_array($name, ['class', 'id', 'data-color'], true)) {
                        // TipTap highlight may use data-color; drop class/id to avoid CSS injection via arbitrary classes.
                        if ($name !== 'data-color') {
                            $child->removeAttribute($attr->name);
                        }
                        continue;
                    }
                    if (! in_array($name, ['alt', 'title', 'width', 'height', 'colspan', 'rowspan', 'scope', 'loading', 'rel', 'data-color'], true)) {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'img' && ! $child->hasAttribute('src')) {
                    $remove[] = $child;
                    continue;
                }
                self::scrub($child);
            }
        }
        foreach ($remove as $el) {
            $el->parentNode?->removeChild($el);
        }
    }

    protected static function isSafeSrc(string $src): bool
    {
        $src = trim($src);
        if ($src === '' || strlen($src) > 2000) {
            return false;
        }
        if (preg_match('#^(javascript|data|vbscript):#i', $src)) {
            return false;
        }
        // Absolute http(s), site-relative (/…), or protocol-relative (//cdn…)
        return (bool) preg_match('#^(https?:)?//|^/#i', $src);
    }

    /**
     * Keep only allowlisted CSS declarations from the admin RTE.
     */
    protected static function safeInlineStyle(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || ! str_contains($declaration, ':')) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $declaration, 2));
            $prop = strtolower($prop);
            if (! in_array($prop, self::ALLOWED_STYLE_PROPS, true)) {
                continue;
            }
            $value = self::safeCssValue($prop, $value);
            if ($value === null) {
                continue;
            }
            $kept[] = $prop.': '.$value;
        }

        return implode('; ', $kept);
    }

    protected static function safeCssValue(string $prop, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 120) {
            return null;
        }
        if (preg_match('/\\\\|expression\s*\(|url\s*\(|@import|behavior|binding|javascript:/i', $value)) {
            return null;
        }

        return match ($prop) {
            'color', 'background-color', 'background', 'border-color' => self::safeCssColorValue($value),
            'text-align' => in_array(strtolower($value), ['left', 'right', 'center', 'justify', 'start', 'end'], true) ? strtolower($value) : null,
            'font-style' => in_array(strtolower($value), ['normal', 'italic', 'oblique'], true) ? strtolower($value) : null,
            'font-weight' => preg_match('/^(?:normal|bold|bolder|lighter|[1-9]00)$/i', $value) ? $value : null,
            'text-decoration' => preg_match('/^(?:none|underline|line-through|overline)(?:\s+(?:none|underline|line-through|overline))*$/i', $value) ? $value : null,
            'font-size', 'width', 'height', 'max-width', 'padding', 'margin', 'margin-left', 'margin-right', 'border-width' => preg_match('/^\d+(?:\.\d+)?(?:px|em|rem|%|pt)?$/i', $value) ? $value : null,
            'border-collapse' => in_array(strtolower($value), ['collapse', 'separate'], true) ? strtolower($value) : null,
            'border-style' => preg_match('/^(?:none|solid|dashed|dotted|double|groove|ridge|inset|outset)$/i', $value) ? strtolower($value) : null,
            'border' => preg_match('/^[\d.a-z%\s#(),]+$/i', $value) && ! preg_match('/url\s*\(/i', $value) ? $value : null,
            'vertical-align' => in_array(strtolower($value), ['top', 'middle', 'bottom', 'baseline', 'text-top', 'text-bottom'], true) ? strtolower($value) : null,
            default => null,
        };
    }

    protected static function safeCssColorValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 80) {
            return null;
        }
        if (preg_match('/\\\\|expression\s*\(|url\s*\(|@import|behavior|binding|javascript:/i', $value)) {
            return null;
        }
        if (preg_match('/^(?:[a-z]{3,20}|#[0-9a-f]{3,8}|rgba?\(\s*[\d.%\s,]+\s*\)|hsla?\(\s*[\d.%\s,]+\s*\))$/i', $value)) {
            return $value;
        }

        return null;
    }
}
