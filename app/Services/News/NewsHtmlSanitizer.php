<?php

namespace App\Services\News;

use App\Services\Html\SafeHtmlSanitizer;

final class NewsHtmlSanitizer
{
    public function __construct(private readonly SafeHtmlSanitizer $sanitizer) {}

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_NEWS);
    }

    public function plainText(string $html): string
    {
        return $this->sanitizer->plainText($html, SafeHtmlSanitizer::PROFILE_NEWS);
    }
}
