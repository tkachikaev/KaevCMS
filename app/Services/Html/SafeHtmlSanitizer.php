<?php

namespace App\Services\Html;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use LengthException;
use RuntimeException;

final class SafeHtmlSanitizer
{
    public const MAX_LENGTH = 200000;

    public const PROFILE_NEWS = 'news';

    public const PROFILE_PAGE = 'page';

    public const PROFILE_EMAIL = 'email';

    public const PROFILE_FORUM = 'forum';

    private const CONTENT_ALLOWED_ELEMENTS = [
        'p', 'br', 'strong', 'em', 'u', 's',
        'h2', 'h3', 'h4',
        'ul', 'ol', 'li',
        'blockquote', 'a', 'hr',
        'figure', 'img', 'figcaption', 'span',
        'pre', 'code',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    // Forum uses the same DOM-based sanitation engine with a deliberately small vocabulary.
    private const FORUM_ALLOWED_ELEMENTS = [
        'p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li',
        'blockquote', 'a', 'pre', 'code', 'span',
        // Images by https link only (never uploads); see sanitizeForumImageSource().
        'figure', 'img',
    ];

    /**
     * Image hosts forum posts may show pictures from. The CMS never fetches or stores
     * these files: readers' browsers load them directly, so nothing user-supplied is
     * parsed on the server. Exact host names only (no wildcard subdomains).
     */
    public const DEFAULT_FORUM_IMAGE_HOSTS = ['iili.io', 'i.imgur.com', 'i.postimg.cc', 'i.ibb.co'];

    /** @var (\Closure(): list<string>)|null Supplied by the forum module from its settings. */
    private static ?\Closure $forumImageHostResolver = null;

    /** @param  (\Closure(): list<string>)|null  $resolver */
    public static function resolveForumImageHostsUsing(?\Closure $resolver): void
    {
        self::$forumImageHostResolver = $resolver;
    }

    /** @return list<string> */
    public static function forumImageHosts(): array
    {
        $hosts = self::$forumImageHostResolver !== null ? (self::$forumImageHostResolver)() : self::DEFAULT_FORUM_IMAGE_HOSTS;

        return array_values(array_filter($hosts, 'is_string'));
    }

    /** Forum link images: width in percent of the column, 10-100 in steps of 5. */
    private const FORUM_IMAGE_WIDTHS = [
        '10', '15', '20', '25', '30', '35', '40', '45', '50', '55',
        '60', '65', '70', '75', '80', '85', '90', '95', '100',
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

    private const COLORS = [
        'default', 'gold', 'amber', 'yellow', 'red', 'rose', 'coral', 'orange',
        'brown', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue',
        'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'slate', 'gray', 'zinc',
        'muted',
        // Extra forum palette tones.
        'crimson', 'wine', 'maroon', 'magenta', 'plum', 'navy', 'azure', 'steel', 'mint', 'forest', 'olive', 'sand', 'tangerine', 'peach', 'silver', 'ink',
    ];

    private const HIGHLIGHTS = [
        'yellow', 'amber', 'orange', 'rose', 'red', 'lime',
        'green', 'teal', 'cyan', 'blue', 'purple', 'gray',
    ];

    private const TEXT_SIZES = ['small', 'large'];

    private const IMAGE_SIZES = ['narrow', 'medium', 'wide', 'full'];

    private const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    public function sanitize(string $html, string $profile): string
    {
        return match ($profile) {
            self::PROFILE_NEWS, self::PROFILE_PAGE, self::PROFILE_FORUM => $this->sanitizeContent($html, $profile),
            self::PROFILE_EMAIL => $this->sanitizeEmail($html),
            default => throw new InvalidArgumentException("Unknown HTML sanitizer profile [{$profile}]."),
        };
    }

    public function plainText(string $html, string $profile): string
    {
        return match ($profile) {
            self::PROFILE_NEWS, self::PROFILE_PAGE, self::PROFILE_FORUM => $this->contentPlainText($html, $profile),
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

        $rootId = match ($profile) {
            self::PROFILE_NEWS => 'kaevcms-news-root',
            self::PROFILE_PAGE => 'kaevcms-page-root',
            self::PROFILE_FORUM => 'kaevcms-forum-root',
            default => throw new InvalidArgumentException("Unknown content sanitizer profile [{$profile}]."),
        };
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

            $result = trim($result);
            $this->guardMaximumLength($result);

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function contentPlainText(string $html, string $profile): string
    {
        $safe = $this->sanitizeContent($html, $profile);
        $safe = preg_replace('/<br\s*\/?>/i', "\n", $safe) ?? $safe;
        $safe = preg_replace('/<\/(p|h2|h3|h4|li|blockquote|figcaption|pre|th|td|tr)>/i', "\n", $safe) ?? $safe;
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

            $pageBlock = $profile === self::PROFILE_PAGE && $tag === 'div'
                && in_array($child->getAttribute('data-content-block'), ['cards', 'card'], true);
            $allowedElements = $profile === self::PROFILE_FORUM
                ? self::FORUM_ALLOWED_ELEMENTS : self::CONTENT_ALLOWED_ELEMENTS;
            if (! $pageBlock && ! in_array($tag, $allowedElements, true)) {
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
            'div' => $profile === self::PROFILE_PAGE ? ['data-content-block'] : [],
            'img' => $profile === self::PROFILE_FORUM ? ['src', 'alt', 'referrerpolicy'] : ['src', 'alt'],
            'span' => ['data-color', 'data-highlight', 'data-text-size'],
            'figure' => $profile === self::PROFILE_FORUM ? ['data-align', 'data-width', 'data-media', 'data-media-id'] : ['data-align', 'data-size'],
            default => in_array($tag, self::ALIGNABLE_ELEMENTS, true) ? ['data-align'] : [],
        };
        if ($profile === self::PROFILE_PAGE && $tag === 'a') {
            $allowed[] = 'data-content-button';
        }

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
            $button = $profile === self::PROFILE_PAGE ? $element->getAttribute('data-content-button') : '';
            if (! in_array($button, ['primary', 'secondary'], true)) {
                $element->removeAttribute('data-content-button');
            } else {
                $parts = parse_url($href ?? '');
                if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
                    $href = null;
                }
                if ($href === null || $href === '#') {
                    $element->setAttribute('aria-disabled', 'true');
                    $element->setAttribute('tabindex', '-1');
                }
            }
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

        if ($tag === 'img' && $profile === self::PROFILE_FORUM) {
            $src = $this->sanitizeForumImageSource($element->getAttribute('src'));
            $figure = $element->parentNode;
            if ($src === null || ! $figure instanceof DOMElement || strtolower($figure->tagName) !== 'figure') {
                $element->parentNode?->removeChild($element);

                return;
            }

            $element->setAttribute('src', $src);
            $element->setAttribute('alt', $this->cleanTextAttribute($element->getAttribute('alt'), 255));
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('decoding', 'async');
            // Readers' browsers fetch the image from the third-party host: send no page address.
            $element->setAttribute('referrerpolicy', 'no-referrer');

            return;
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

            $highlight = strtolower($element->getAttribute('data-highlight'));
            if (! in_array($highlight, self::HIGHLIGHTS, true)) {
                $element->removeAttribute('data-highlight');
            } else {
                $element->setAttribute('data-highlight', $highlight);
            }

            $textSize = strtolower($element->getAttribute('data-text-size'));
            if (! in_array($textSize, self::TEXT_SIZES, true)) {
                $element->removeAttribute('data-text-size');
            } else {
                $element->setAttribute('data-text-size', $textSize);
            }
        }

        if (in_array($tag, self::ALIGNABLE_ELEMENTS, true)) {
            $alignment = strtolower($element->getAttribute('data-align'));
            if (! in_array($alignment, self::ALIGNMENTS, true)
                || $alignment === 'left'
                || ($tag === 'figure' && $alignment === 'justify')) {
                $element->removeAttribute('data-align');
            } else {
                $element->setAttribute('data-align', $alignment);
            }
        }

        if ($tag === 'figure' && $profile === self::PROFILE_FORUM && $element->hasAttribute('data-media')) {
            // Video embeds are stored as a provider + id token, never as player markup.
            // The forum renders the player itself from these two validated values.
            $provider = $element->getAttribute('data-media');
            $mediaId = $element->getAttribute('data-media-id');
            if (! self::validForumMedia($provider, $mediaId)) {
                $element->parentNode?->removeChild($element);

                return;
            }
            while ($element->firstChild !== null) {
                $element->removeChild($element->firstChild);
            }
            $element->removeAttribute('data-width');
            // Fixed attribute order keeps the forum player renderer simple and strict.
            $element->removeAttribute('data-media');
            $element->removeAttribute('data-media-id');
            $element->setAttribute('data-media', $provider);
            $element->setAttribute('data-media-id', $mediaId);

            return;
        }

        if ($tag === 'figure' && $profile === self::PROFILE_FORUM) {
            $element->removeAttribute('data-media-id');
            $width = $element->getAttribute('data-width');
            if (! in_array($width, self::FORUM_IMAGE_WIDTHS, true) || $width === '100') {
                $element->removeAttribute('data-width');
            }
            // A forum figure exists only to hold one link image.
            $images = $element->getElementsByTagName('img');
            if ($images->length === 0) {
                $this->unwrap($element);
            }

            return;
        }

        if ($tag === 'figure') {
            $size = strtolower($element->getAttribute('data-size'));
            if (! in_array($size, self::IMAGE_SIZES, true) || $size === 'full') {
                $element->removeAttribute('data-size');
            } else {
                $element->setAttribute('data-size', $size);
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

    /** Supported forum video providers and the exact id shapes they accept. */
    public static function validForumMedia(string $provider, string $mediaId): bool
    {
        return match ($provider) {
            'youtube' => preg_match('/\A[A-Za-z0-9_-]{11}\z/', $mediaId) === 1,
            'twitch' => preg_match('/\A(?:video:\d{1,12}|clip:[A-Za-z0-9_-]{1,100}|channel:[A-Za-z0-9_]{3,25})\z/', $mediaId) === 1,
            default => false,
        };
    }

    /** Forum images are hot-linked over https from the allowed image hosts only. */
    private function sanitizeForumImageSource(string $src): ?string
    {
        $src = html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $src = preg_replace('/[\x00-\x1F\x7F\s]+/u', '', $src) ?? '';
        if ($src === '' || strlen($src) > 2048 || str_contains($src, '\\')) {
            return null;
        }

        $parts = parse_url($src);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (! in_array($host, self::forumImageHosts(), true) || isset($parts['port'])) {
            return null;
        }

        return $src;
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

            $result = '<!doctype html>'.trim($document->saveHTML($root) ?: '');
            $this->guardMaximumLength($result);

            return $result;
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

    private function guardMaximumLength(string $html): void
    {
        if (strlen($html) > self::MAX_LENGTH) {
            throw new LengthException('Sanitized HTML exceeds the maximum allowed byte length.');
        }
    }

    private function requireDom(string $profile): void
    {
        if (class_exists(DOMDocument::class)) {
            return;
        }

        $label = match ($profile) {
            self::PROFILE_NEWS => 'news',
            self::PROFILE_PAGE => 'page',
            self::PROFILE_FORUM => 'forum',
            self::PROFILE_EMAIL => 'custom email',
            default => 'HTML',
        };

        throw new RuntimeException("The PHP DOM extension is required to sanitize {$label} HTML.");
    }
}
