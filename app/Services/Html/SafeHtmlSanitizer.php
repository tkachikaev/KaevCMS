<?php

namespace App\Services\Html;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;

final class SafeHtmlSanitizer
{
    public const MAX_LENGTH = 200000;

    public const PROFILE_NEWS = 'news';

    public const PROFILE_PAGE = 'page';

    public const PROFILE_EMAIL = 'email';

    private const CONTENT_ALLOWED_ELEMENTS = [
        'p', 'br', 'strong', 'em', 'u', 's',
        'h2', 'h3', 'h4',
        'ul', 'ol', 'li',
        'blockquote', 'a', 'hr',
        'figure', 'img', 'figcaption', 'span',
        'pre', 'code',
    ];

    private const CONTENT_DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'svg', 'math', 'form', 'input', 'button', 'textarea', 'select',
        'video', 'audio', 'source', 'track', 'canvas', 'template', 'noscript',
        'meta', 'link', 'base', 'head', 'title',
    ];

    private const EMAIL_DROP_WITH_CONTENT = [
        'script', 'iframe', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'textarea', 'select', 'option',
        'canvas', 'svg', 'math', 'video', 'audio', 'source', 'track',
    ];

    private const ALIGNABLE_ELEMENTS = ['p', 'h2', 'h3', 'h4', 'blockquote', 'figure'];

    private const COLORS = ['default', 'gold', 'red', 'green', 'blue', 'muted'];

    private const ALIGNMENTS = ['left', 'center', 'right'];

    public function sanitize(string $html, string $profile): string
    {
        return match ($profile) {
            self::PROFILE_NEWS, self::PROFILE_PAGE => $this->sanitizeContent($html, $profile),
            self::PROFILE_EMAIL => $this->sanitizeEmail($html),
            default => throw new InvalidArgumentException("Unknown HTML sanitizer profile [{$profile}]."),
        };
    }

    public function plainText(string $html, string $profile): string
    {
        return match ($profile) {
            self::PROFILE_NEWS, self::PROFILE_PAGE => $this->contentPlainText($html, $profile),
            self::PROFILE_EMAIL => $this->emailPlainText($html),
            default => throw new InvalidArgumentException("Unknown HTML sanitizer profile [{$profile}]."),
        };
    }

    /** @return array<int, string> */
    public function violations(string $html, string $profile): array
    {
        if ($profile !== self::PROFILE_EMAIL) {
            return [];
        }

        $checks = [
            'PHP code' => '/<\?(?:php|=)?/iu',
            'Blade directives' => '/(?:@php\b|@endphp\b|\{!!|!!\}|\{\{)/iu',
            'scripts' => '/<\s*script\b/iu',
            'embedded frames or objects' => '/<\s*(?:iframe|object|embed|applet)\b/iu',
            'forms and interactive controls' => '/<\s*(?:form|input|button|textarea|select|option)\b/iu',
            'JavaScript event attributes' => '/\son[a-z0-9_-]+\s*=/iu',
            'unsafe URL schemes' => '/(?:javascript|vbscript)\s*:/iu',
            'unsafe CSS expressions' => '/(?:expression\s*\(|behavior\s*:|-moz-binding\s*:)/iu',
            'document base override' => '/<\s*base\b/iu',
            'automatic redirect' => '/<\s*meta\b[^>]*http-equiv\s*=\s*["\']?refresh\b/iu',
        ];

        $found = [];
        foreach ($checks as $label => $pattern) {
            if (preg_match($pattern, $html) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }

    private function sanitizeContent(string $html, string $profile): string
    {
        $this->requireDom($profile);

        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (strlen($html) > self::MAX_LENGTH) {
            $html = substr($html, 0, self::MAX_LENGTH);
        }

        $rootId = $profile === self::PROFILE_NEWS ? 'kaevcms-news-root' : 'kaevcms-page-root';
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML(
                '<?xml encoding="UTF-8"><!doctype html><html><body><div id="'.$rootId.'">'.$html.'</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );

            $xpath = new DOMXPath($document);
            $root = $xpath->query('//*[@id="'.$rootId.'"]')->item(0);
            if (! $root instanceof DOMElement) {
                return '';
            }

            $this->sanitizeContentChildren($root, $profile);

            $result = '';
            foreach ($root->childNodes as $child) {
                $result .= $document->saveHTML($child) ?: '';
            }

            return trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function contentPlainText(string $html, string $profile): string
    {
        $safe = $this->sanitizeContent($html, $profile);
        $safe = preg_replace('/<br\s*\/?>/i', "\n", $safe) ?? $safe;
        $safe = preg_replace('/<\/(p|h2|h3|h4|li|blockquote|figcaption|pre)>/i', "\n", $safe) ?? $safe;
        $safe = html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safe = preg_replace('/[\t ]+/u', ' ', $safe) ?? $safe;
        $safe = preg_replace('/\n{3,}/u', "\n\n", $safe) ?? $safe;

        return trim($safe);
    }

    private function sanitizeContentChildren(DOMNode $parent, string $profile): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMComment) {
                $parent->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::CONTENT_DROP_WITH_CONTENT, true)) {
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeContentChildren($child, $profile);

            if (! in_array($tag, self::CONTENT_ALLOWED_ELEMENTS, true)) {
                $this->unwrap($child);

                continue;
            }

            $this->sanitizeContentAttributes($child, $tag, $profile);
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function sanitizeContentAttributes(DOMElement $element, string $tag, string $profile): void
    {
        $allowed = match ($tag) {
            'a' => ['href', 'title'],
            'img' => ['src', 'alt'],
            'span' => ['data-color'],
            default => in_array($tag, self::ALIGNABLE_ELEMENTS, true) ? ['data-align'] : [],
        };

        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->name;
        }

        foreach ($attributes as $name) {
            if (! in_array(strtolower($name), $allowed, true)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'a') {
            $href = $this->sanitizeContentLink($element->getAttribute('href'));
            if ($href === null) {
                $element->removeAttribute('href');
            } else {
                $element->setAttribute('href', $href);
            }

            if ($element->hasAttribute('title')) {
                $title = $this->cleanTextAttribute($element->getAttribute('title'), 255);
                $title === '' ? $element->removeAttribute('title') : $element->setAttribute('title', $title);
            }
        }

        if ($tag === 'img') {
            $src = $this->sanitizeContentImageSource($element->getAttribute('src'), $profile);
            if ($src === null) {
                $element->parentNode?->removeChild($element);

                return;
            }

            $element->setAttribute('src', $src);
            $element->setAttribute('alt', $this->cleanTextAttribute($element->getAttribute('alt'), 255));
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('decoding', 'async');
        }

        if ($tag === 'span') {
            $color = strtolower($element->getAttribute('data-color'));
            if (! in_array($color, self::COLORS, true) || $color === 'default') {
                $element->removeAttribute('data-color');
            } else {
                $element->setAttribute('data-color', $color);
            }
        }

        if (in_array($tag, self::ALIGNABLE_ELEMENTS, true)) {
            $alignment = strtolower($element->getAttribute('data-align'));
            if (! in_array($alignment, self::ALIGNMENTS, true) || $alignment === 'left') {
                $element->removeAttribute('data-align');
            } else {
                $element->setAttribute('data-align', $alignment);
            }
        }
    }

    private function sanitizeContentLink(string $href): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $href = preg_replace('/[\x00-\x1F\x7F]+/u', '', $href) ?? '';

        if ($href === '' || str_starts_with($href, '//') || str_contains($href, '\\')) {
            return null;
        }

        if (str_starts_with($href, '#') || str_starts_with($href, '/') || str_starts_with($href, '?')) {
            return $href;
        }

        if (! preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) {
            return $href;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true) ? $href : null;
    }

    private function sanitizeContentImageSource(string $src, string $profile): ?string
    {
        $src = html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $src = preg_replace('/[\x00-\x1F\x7F]+/u', '', $src) ?? '';
        $directory = $profile === self::PROFILE_NEWS ? 'news' : 'pages';
        $pattern = '~^/uploads/'.$directory.'/content/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp)$~i';

        return preg_match($pattern, $src) === 1 ? $src : null;
    }

    private function cleanTextAttribute(string $value, int $limit): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_substr(trim($value), 0, $limit);
    }

    private function sanitizeEmail(string $html): string
    {
        $this->requireDom(self::PROFILE_EMAIL);

        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (strlen($html) > self::MAX_LENGTH) {
            $html = substr($html, 0, self::MAX_LENGTH);
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );

            $xpath = new DOMXPath($document);
            $nodes = [];
            foreach ($xpath->query('//*') ?: [] as $node) {
                if ($node instanceof DOMElement) {
                    $nodes[] = $node;
                }
            }

            foreach ($nodes as $element) {
                if ($element->parentNode === null) {
                    continue;
                }

                $tag = strtolower($element->tagName);

                if (in_array($tag, self::EMAIL_DROP_WITH_CONTENT, true) || $tag === 'base') {
                    $element->parentNode->removeChild($element);

                    continue;
                }

                if ($tag === 'meta' && strtolower(trim($element->getAttribute('http-equiv'))) === 'refresh') {
                    $element->parentNode->removeChild($element);

                    continue;
                }

                if ($tag === 'style') {
                    $element->nodeValue = $this->sanitizeCss((string) $element->textContent);
                }

                $this->sanitizeEmailAttributes($element);
            }

            $root = $document->documentElement;
            if (! $root instanceof DOMElement) {
                return '';
            }

            $result = $document->saveHTML($root) ?: '';

            return '<!doctype html>'.trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function emailPlainText(string $html): string
    {
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', '', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(?:p|div|h[1-6]|li|tr|table|section|article)>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function sanitizeEmailAttributes(DOMElement $element): void
    {
        $names = [];
        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->name;
        }

        foreach ($names as $name) {
            $lower = strtolower($name);

            if (str_starts_with($lower, 'on') || in_array($lower, ['srcdoc', 'formaction', 'xlink:href'], true)) {
                $element->removeAttribute($name);

                continue;
            }

            if ($lower === 'style') {
                $css = $this->sanitizeCss($element->getAttribute($name));
                $css === '' ? $element->removeAttribute($name) : $element->setAttribute($name, $css);

                continue;
            }

            if (in_array($lower, ['href', 'src', 'background', 'poster'], true)) {
                $url = $this->sanitizeEmailUrl($element->getAttribute($name), $lower === 'src');
                $url === null ? $element->removeAttribute($name) : $element->setAttribute($name, $url);
            }
        }
    }

    private function sanitizeCss(string $css): string
    {
        $css = preg_replace('/expression\s*\([^)]*\)/iu', '', $css) ?? '';
        $css = preg_replace('/(?:behavior|-moz-binding)\s*:[^;}]*/iu', '', $css) ?? $css;
        $css = preg_replace('/url\s*\(\s*["\']?\s*(?:(?:javascript|vbscript)\s*:|data\s*:\s*text\/html)[^)]*\)/iu', '', $css) ?? $css;
        $css = preg_replace('/@import\s+[^;]+;/iu', '', $css) ?? $css;

        return trim($css);
    }

    private function sanitizeEmailUrl(string $url, bool $allowImageData): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/[\x00-\x1F\x7F]+/u', '', $url) ?? '';

        if ($url === '') {
            return null;
        }

        if ($allowImageData && preg_match('~^data:image/(?:png|jpe?g|gif|webp);base64,[a-z0-9+/=\r\n]+$~i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return $url;
        }

        if (! preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'cid'], true) ? $url : null;
    }

    private function requireDom(string $profile): void
    {
        if (class_exists(DOMDocument::class)) {
            return;
        }

        $label = match ($profile) {
            self::PROFILE_NEWS => 'news',
            self::PROFILE_PAGE => 'page',
            self::PROFILE_EMAIL => 'custom email',
            default => 'HTML',
        };

        throw new RuntimeException("The PHP DOM extension is required to sanitize {$label} HTML.");
    }
}
