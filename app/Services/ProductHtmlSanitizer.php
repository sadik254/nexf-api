<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

class ProductHtmlSanitizer
{
    private const TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];

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
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
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
            if ($tag === 'a' && $name === 'href' && preg_match('#^https?://#i', $value)) continue;
            $node->removeAttribute($attribute->name);
        }
        if ($tag === 'a') $node->setAttribute('rel', 'nofollow noopener noreferrer');
    }
}
