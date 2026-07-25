<?php

namespace App\Services\Pages;

use App\Services\Html\SafeHtmlSanitizer;

final class PageHtmlSanitizer
{
    public function __construct(private readonly SafeHtmlSanitizer $sanitizer) {}

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_PAGE);
    }

    public function plainText(string $html): string
    {
        return $this->sanitizer->plainText($html, SafeHtmlSanitizer::PROFILE_PAGE);
    }
}
