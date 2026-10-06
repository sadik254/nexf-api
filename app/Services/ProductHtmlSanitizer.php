<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

class ProductHtmlSanitizer
{
    private const TAGS = ['span', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'hr', 'ul', 'ol', 'li', 'blockquote', 'a', 'img', 'video', 'iframe', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'];

    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') return $html;
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementsByTagName('div')->item(0);
        if (!$root) return '';
        foreach (iterator_to_array($root->childNodes) as $child) $this->cleanNode($child);
        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return $out;
    }

    private function cleanNode(DOMNode $node): void
    {
        if (!$node instanceof DOMElement) return;
        $tag = strtolower($node->tagName);
        // contentEditable's colour command emits legacy font elements.
        // Convert them to a span with a narrowly validated colour style.
        if ($tag === 'font') {
            $span = $node->ownerDocument->createElement('span');
            if ($node->hasAttribute('color')) $span->setAttribute('style', 'color:'.$node->getAttribute('color'));
            while ($node->firstChild) $span->appendChild($node->firstChild);
            $node->parentNode?->replaceChild($span, $node);
            $node = $span;
            $tag = 'span';
        }
        if (in_array($tag, ['script', 'style', 'object', 'embed', 'svg', 'math'], true)) {
            $node->parentNode?->removeChild($node);
            return;
        }
        foreach (iterator_to_array($node->childNodes) as $child) $this->cleanNode($child);
        if (!in_array($tag, self::TAGS, true)) {
            while ($node->firstChild) $node->parentNode?->insertBefore($node->firstChild, $node);
            $node->parentNode?->removeChild($node);
            return;
        }
        foreach (iterator_to_array($node->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);
            if ($name === 'style') {
                $safe = $this->safeStyle($value);
                if ($safe !== '') { $node->setAttribute('style', $safe); continue; }
            }
            if ($tag === 'a' && $name === 'target' && $value === '_blank') continue;
            if ($tag === 'a' && $name === 'title') continue;
            if ($tag === 'a' && $name === 'href' && preg_match('#^https?://#i', $value)) continue;
            if (in_array($tag, ['img', 'video'], true) && $name === 'src' && preg_match('#^https://#i', $value)) continue;
            if ($tag === 'iframe' && $name === 'src' && $this->safeEmbedUrl($value)) continue;
            if ($tag === 'iframe' && in_array($name, ['title', 'allowfullscreen'], true)) continue;
            if ($tag === 'img' && $name === 'alt') continue;
            if ($tag === 'video' && $name === 'controls') continue;
            if (in_array($tag, ['td', 'th'], true) && in_array($name, ['rowspan', 'colspan'], true) && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 20) continue;
            $node->removeAttribute($attribute->name);
        }
        if (in_array($tag, ['img', 'video', 'iframe'], true) && !$node->hasAttribute('src')) {
            $node->parentNode?->removeChild($node);
            return;
        }
        if ($tag === 'a') $node->setAttribute('rel', 'nofollow noopener noreferrer');
    }

    private function safeStyle(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) continue;
            [$property, $value] = array_map('trim', $parts);
            $property = strtolower($property);
            if (in_array($property, ['color', 'background-color'], true) &&
                preg_match('/^(#[a-f0-9]{3,8}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)|[a-z]{1,20})$/i', $value)) $safe[] = $property.':'.$value;
            if ($property === 'text-align' && in_array($value, ['left', 'right', 'center', 'justify'], true)) $safe[] = $property.':'.$value;
        }
        return implode(';', $safe);
    }

    private function safeEmbedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https') return false;
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        return ($host === 'www.youtube-nocookie.com' && (bool) preg_match('#^/embed/[A-Za-z0-9_-]{6,}$#', $path))
            || ($host === 'player.vimeo.com' && (bool) preg_match('#^/video/[0-9]+$#', $path));
    }
}
