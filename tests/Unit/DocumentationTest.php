<?php

namespace Tests\Unit;

use App\Support\Documentation;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_links_follow_the_language_and_the_guide_slug(): void
    {
        config()->set('cms.docs.base_url', 'https://docs.example.test/site/');

        $this->assertSame('https://docs.example.test/site/', Documentation::url(null, 'ru'));
        $this->assertSame('https://docs.example.test/site/en/', Documentation::url(null, 'en'));
        $this->assertSame('https://docs.example.test/site/guide/donations-freekassa', Documentation::url('donations-freekassa', 'ru'));
        $this->assertSame('https://docs.example.test/site/en/guide/donations-freekassa', Documentation::url('donations-freekassa', 'en'));
    }

    public function test_an_unsafe_topic_falls_back_to_the_documentation_home(): void
    {
        config()->set('cms.docs.base_url', 'https://docs.example.test');

        foreach (['../admin', 'a b', 'Guide', '-x', 'x/y', ''] as $topic) {
            $this->assertSame('https://docs.example.test/en/', Documentation::url($topic, 'en'));
        }
    }
}
