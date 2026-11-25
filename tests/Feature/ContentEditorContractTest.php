<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContentEditorContractTest extends TestCase
{
    public function test_compiled_editor_and_license_are_shipped(): void
    {
        $bundlePath = public_path('assets/admin/js/news-editor.js');
        $licensePath = public_path('assets/admin/js/news-editor.LICENSE.txt');

        $this->assertFileExists($bundlePath);
        $this->assertFileExists($licensePath);
        $bundle = (string) file_get_contents($bundlePath);
        $license = (string) file_get_contents($licensePath);

        $this->assertNotEmpty(trim($bundle));
        $this->assertStringContainsString('Third-party notices: news-editor.LICENSE.txt', $bundle);
        $this->assertStringContainsString('Tiptap', $license);
    }
}
