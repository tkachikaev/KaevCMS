<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContentEditorContractTest extends TestCase
{
    public function test_content_editor_dependencies_and_build_contract_are_locked(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            'esbuild resources/js/admin/content-editor.js --bundle --minify --banner:js="/*! Third-party notices: news-editor.LICENSE.txt */" --target=es2020 --format=iife --outfile=public/assets/admin/js/news-editor.js',
            $package['scripts']['build:editor'] ?? null,
        );

        foreach ([
            '@tiptap/core',
            '@tiptap/extension-character-count',
            '@tiptap/extension-placeholder',
            '@tiptap/extension-table',
            '@tiptap/extension-text-align',
            '@tiptap/starter-kit',
        ] as $dependency) {
            $this->assertSame('3.29.2', $package['devDependencies'][$dependency] ?? null);
        }

        $this->assertSame('0.28.1', $package['devDependencies']['esbuild'] ?? null);
    }

    public function test_news_and_pages_share_the_same_editor_component(): void
    {
        $news = (string) file_get_contents(resource_path('views/admin/news/_translation-editor.blade.php'));
        $pages = (string) file_get_contents(resource_path('views/admin/pages/_translation-editor.blade.php'));
        $component = (string) file_get_contents(resource_path('views/admin/content/_rich-editor.blade.php'));

        $this->assertStringContainsString("@include('admin.content._rich-editor'", $news);
        $this->assertStringContainsString("@include('admin.content._rich-editor'", $pages);
        $this->assertStringNotContainsString('document.execCommand', $component);
        $this->assertStringNotContainsString('<form data-editor-', $component);
        $this->assertStringContainsString('data-editor-link-apply', $component);
        $this->assertStringContainsString('data-editor-image-apply', $component);
    }

    public function test_compiled_editor_and_public_theme_contract_are_shipped(): void
    {
        $source = (string) file_get_contents(resource_path('js/admin/content-editor.js'));
        $bundle = (string) file_get_contents(public_path('assets/admin/js/news-editor.js'));
        $license = (string) file_get_contents(public_path('assets/admin/js/news-editor.LICENSE.txt'));

        $this->assertStringNotContainsString('document.execCommand', $source);
        $this->assertStringContainsString("import { Editor, Mark, Node, mergeAttributes } from '@tiptap/core';", $source);
        $this->assertStringContainsString("node.type.name === 'table'", $source);
        $this->assertStringContainsString('O.type.name==="table"', $bundle);
        $this->assertStringContainsString("'amber'", $source);
        $this->assertStringContainsString("'fuchsia'", $source);
        $this->assertStringContainsString('"amber"', $bundle);
        $this->assertStringContainsString('"fuchsia"', $bundle);
        $this->assertStringContainsString('Third-party notices: news-editor.LICENSE.txt', $bundle);
        $this->assertStringContainsString('Tiptap', $license);

        foreach ([
            public_path('themes/default/assets/css/app.css'),
            public_path('themes/kaev-aurelia/assets/css/app.css'),
        ] as $themeCss) {
            $css = (string) file_get_contents($themeCss);
            $this->assertStringContainsString('.news-prose,.cms-page-prose', $css);
            $this->assertStringContainsString('[data-color="fuchsia"]', $css);
            $this->assertStringContainsString('[data-highlight="rose"]', $css);
            $this->assertStringContainsString('[data-align="justify"]', $css);
            $this->assertStringContainsString('figure[data-size="medium"]', $css);
        }
    }
}
