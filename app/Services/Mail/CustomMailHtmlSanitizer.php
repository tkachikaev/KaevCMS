<?php

namespace App\Services\Mail;

use App\Services\Html\SafeHtmlSanitizer;

final class CustomMailHtmlSanitizer
{
    public const MAX_LENGTH = SafeHtmlSanitizer::MAX_LENGTH;

    public function __construct(private readonly SafeHtmlSanitizer $sanitizer) {}

    /** @return array<int, string> */
    public function violations(string $html): array
    {
        return $this->sanitizer->violations($html, SafeHtmlSanitizer::PROFILE_EMAIL);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_EMAIL);
    }

    public function plainText(string $html): string
    {
        return $this->sanitizer->plainText($html, SafeHtmlSanitizer::PROFILE_EMAIL);
    }
}
