<?php

namespace App\Services\Diagnostics;

final readonly class DiagnosticPackageFile
{
    public function __construct(
        public string $path,
        public string $name,
    ) {}
}
