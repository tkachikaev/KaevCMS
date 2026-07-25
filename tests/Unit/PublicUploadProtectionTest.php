<?php

namespace Tests\Unit;

use App\Services\Infrastructure\PublicUploadProtection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicUploadProtectionTest extends TestCase
{
    public function test_missing_upload_protection_files_are_created_without_overwriting_existing_files(): void
    {
        $root = storage_path('framework/testing/public-uploads-'.Str::uuid());
        File::deleteDirectory($root);

        try {
            $protection = app(PublicUploadProtection::class);
            $protection->ensure($root);

            $this->assertFileExists($root.'/.gitignore');
            $this->assertFileExists($root.'/.htaccess');
            $this->assertStringContainsString('Require all denied', File::get($root.'/.htaccess'));

            File::put($root.'/.htaccess', "# custom protection\n");
            $protection->ensure($root);

            $this->assertSame("# custom protection\n", File::get($root.'/.htaccess'));
        } finally {
            File::deleteDirectory($root);
        }
    }
}
