<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Services\Html\SafeHtmlSanitizer;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use KaevCMS\Modules\Forum\Models\ForumPost;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Support\ForumMedia;
use Tests\TestCase;

final class ForumEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_f5_adds_nullable_formatted_body_without_converting_old_posts(): void
    {
        $this->assertTrue(Schema::hasColumn('module_forum_posts', 'body_html'));
        $this->assertSame(json_decode((string) file_get_contents(base_path('modules/forum/module.json')), true)['version'], app(ModuleManager::class)->inspect('forum')['version']);

        $user = User::factory()->create(['display_name' => 'LegacyPlayer']);
        $this->actingAs($user, 'web')->withSession(['forum.author_mode' => 'user']);
        $section = $this->section('Общее общение');

        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'Old plain text',
            'body' => "<b>literal</b>\nSecond line",
            'submission_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $post = ForumPost::query()->firstOrFail();
        $this->assertNull($post->body_html);
        $this->get('/forum/topics/'.$post->topic_id)
            ->assertOk()
            ->assertSee('&lt;b&gt;literal&lt;/b&gt;', false)
            ->assertDontSee('<b>literal</b>', false);
    }

    public function test_topic_and_reply_forms_load_the_rich_editor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withSession(['forum.author_mode' => 'user']);
        $section = $this->section('Общее общение');

        $this->get('/forum/sections/'.$section->id.'/topics/new')
            ->assertOk()
            ->assertSee('assets/admin/js/news-editor.js', false)
            ->assertSee('data-rich-editor', false)
            ->assertSee('data-editor-link', false);

        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'Example',
            'body_html' => '<p>Initial message</p>',
            'submission_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $topic = ForumTopic::query()->firstOrFail();
        $this->get('/forum/topics/'.$topic->id)->assertSee('data-rich-editor', false);
    }

    public function test_plain_fallback_ignores_stale_rich_html_after_validation_failure(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withSession(['forum.author_mode' => 'user']);
        $section = $this->section('Общее общение');
        $key = (string) Str::uuid();

        $this->withSession(['_old_input' => [
            'title' => 'A topic to correct',
            'body' => '',
            'body_html' => '<p>Previous <strong>formatted</strong> message</p>',
            'submission_key' => $key,
        ]])->get('/forum/sections/'.$section->id.'/topics/new')
            ->assertOk()
            ->assertSee('name="body_html" maxlength="20000" disabled hidden', false)
            ->assertSee('Previous formatted message</textarea>', false);

        // With no rich editor initialized the disabled HTML field is not sent.
        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'A corrected topic',
            'body' => 'Corrected without JavaScript',
            'submission_key' => $key,
        ])->assertRedirect();

        $post = ForumPost::query()->firstOrFail();
        $this->assertSame('Corrected without JavaScript', $post->body_text);
        $this->assertNull($post->body_html);
    }

    public function test_formatted_text_is_sanitized_and_rendered_without_executable_markup(): void
    {
        $user = User::factory()->create(['display_name' => 'SafePoster']);
        $this->actingAs($user, 'web')->withSession(['forum.author_mode' => 'user']);
        $section = $this->section('Общее общение');

        $html = '<p onclick="alert(1)">This is <strong>important</strong> and <em>italic</em>.</p>'.
            '<blockquote>Quoted <u>line</u></blockquote>'.
            '<ul><li>one</li><li>two</li></ul>'.
            '<pre><code>echo 1;</code></pre>'.
            '<a href="javascript:alert(1)" onmouseover="boom()">Unsafe link</a>'.
            '<script>alert("xss")</script><iframe src="https://malicious.example"></iframe>'.
            '<img src="https://malicious.example/pixel.png" onerror="alert(1)">';

        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'Formatting test',
            'body_html' => $html,
            'submission_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $post = ForumPost::query()->firstOrFail();
        $this->assertNotNull($post->body_html);
        $this->assertStringContainsString('<strong>important</strong>', $post->body_html);
        $this->assertStringContainsString('<blockquote>', $post->body_html);
        $this->assertStringNotContainsString('<script', $post->body_html);
        $this->assertStringNotContainsString('<iframe', $post->body_html);
        $this->assertStringNotContainsString('<img', $post->body_html);
        $this->assertStringNotContainsString('javascript:', $post->body_html);
        $this->assertStringNotContainsString('onmouseover', $post->body_html);
        $this->assertStringContainsString('important', $post->body_text);
        $this->assertStringNotContainsString('alert("xss")', $post->body_text);

        $response = $this->get('/forum/topics/'.$post->topic_id)->assertOk();
        $document = new \DOMDocument;
        $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new \DOMXPath($document);
        $content = $xpath->query('//*[@id="post-'.$post->id.'"]//*[contains(concat(" ", normalize-space(@class), " "), " forum-post-body ")]')->item(0);
        $this->assertNotNull($content);
        $this->assertSame('important', $xpath->evaluate('string(.//strong)', $content));
        $this->assertSame(0.0, $xpath->evaluate('count(.//*[self::script or self::img or self::iframe or @onclick or @onmouseover])', $content));
    }

    public function test_link_delay_checks_href_when_visible_label_has_no_url(): void
    {
        $player = User::factory()->create();
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        DB::table('module_forum_settings')->where('id', 1)->update(['links_after_days' => 7]);
        $section = $this->section('Общее общение');

        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'Ordinary headline',
            'body_html' => '<p>Read <a href="https://external.example/report">here</a>.</p>',
            'submission_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('body');

        $this->assertDatabaseCount('module_forum_topics', 0);

        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'Ordinary headline',
            'body_html' => '<p>Just <strong>bold</strong> text.</p>',
            'submission_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $topic = ForumTopic::query()->firstOrFail();
        $this->post('/forum/topics/'.$topic->id.'/replies', [
            'body_html' => '<p><a href="https://another.example">Click</a></p>',
            'submission_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('body');

        $this->assertSame(0, $topic->fresh()->reply_count);
    }

    public function test_rich_html_with_only_images_or_scripts_is_rejected_not_saved_as_empty_post(): void
    {
        $player = User::factory()->create();
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        $section = $this->section('Общее общение');
        $key = (string) Str::uuid();

        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'Invalid message',
            'body' => 'Forged fallback',
            'body_html' => '<script>alert(1)</script><img src="https://example.test/test.png">',
            'submission_key' => $key,
        ])->assertSessionHasErrors('body');

        $this->assertDatabaseCount('module_forum_topics', 0);
        $this->assertDatabaseCount('module_forum_posts', 0);
    }

    public function test_rich_reply_preserves_format_and_duplicate_submit_is_idempotent(): void
    {
        $player = User::factory()->create();
        $this->actingAs($player, 'web')->withSession(['forum.author_mode' => 'user']);
        $section = $this->section('Общее общение');

        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'Topic',
            'body' => 'Initial post',
            'submission_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $topic = ForumTopic::query()->firstOrFail();
        $key = (string) Str::uuid();
        $data = [
            'body_html' => '<p>A <strong>formatted</strong> reply</p>',
            'submission_key' => $key,
        ];
        $this->post('/forum/topics/'.$topic->id.'/replies', $data)->assertRedirect();
        $this->post('/forum/topics/'.$topic->id.'/replies', $data)->assertRedirect();

        $this->assertDatabaseCount('module_forum_posts', 2);
        $this->assertSame(1, $topic->fresh()->reply_count);
        $post = ForumPost::query()->where('submission_key', $key)->firstOrFail();
        $this->assertSame('A formatted reply', $post->body_text);
        $this->get('/forum/topics/'.$topic->id)
            ->assertSee('<strong>formatted</strong>', false);
    }

    public function test_forum_sanitizer_profile_keeps_news_profile_untouched(): void
    {
        $sanitizer = app(SafeHtmlSanitizer::class);
        $html = '<h2>Heading</h2><p>Plain</p>'.
            '<a href="https://example.com" target="_blank" onclick="alert(1)">Link</a>'.
            '<table><tbody><tr><td>Cell</td></tr></tbody></table>';

        $forum = $sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_FORUM);
        $news = $sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_NEWS);

        $this->assertStringNotContainsString('<h2>', $forum);
        $this->assertStringNotContainsString('<table>', $forum);
        $this->assertStringContainsString('<h2>', $news);
        $this->assertStringContainsString('<table>', $news);
        $this->assertStringContainsString('href="https://example.com"', $forum);
        $this->assertStringNotContainsString('onclick=', $forum);
        $plain = $sanitizer->plainText($html, SafeHtmlSanitizer::PROFILE_FORUM);
        foreach (['Heading', 'Plain', 'Link', 'Cell'] as $word) {
            $this->assertStringContainsString($word, $plain);
        }
    }

    public function test_forum_profile_keeps_palette_colors_and_alignment_only(): void
    {
        $sanitizer = app(SafeHtmlSanitizer::class);
        $html = '<p data-align="center"><span data-color="crimson">Red</span> '.
            '<span data-color="url(evil)" style="color:#f00" onclick="x()">Bad</span></p>'.
            '<p data-align="sideways">Plain</p><blockquote data-align="right">Quote</blockquote>';

        $forum = $sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_FORUM);

        $this->assertStringContainsString('<p data-align="center"><span data-color="crimson">Red</span>', $forum);
        $this->assertStringContainsString('<blockquote data-align="right">', $forum);
        $this->assertStringNotContainsString('url(evil)', $forum);
        $this->assertStringNotContainsString('style=', $forum);
        $this->assertStringNotContainsString('onclick', $forum);
        $this->assertStringNotContainsString('sideways', $forum);
    }

    public function test_forum_profile_keeps_images_from_allowed_hosts_only(): void
    {
        $sanitizer = app(SafeHtmlSanitizer::class);
        $html = '<figure data-align="center" data-width="40"><img src="https://iili.io/abc123.png" alt="Ok" onerror="x()"></figure>'.
            '<figure data-width="33"><img src="https://i.imgur.com/b.png"></figure>'.
            '<figure><img src="https://img.example.com/any.png"></figure>'.
            '<figure><img src="https://evil.iili.io/sub.png"></figure>'.
            '<figure><img src="https://iili.io.evil.example/fake.png"></figure>'.
            '<figure><img src="http://iili.io/plain.png"></figure>'.
            '<figure><img src="https://iili.io:8443/port.png"></figure>'.
            '<figure><img src="https://user:pass@i.imgur.com/c.png"></figure>'.
            '<figure><img src="javascript:alert(1)"></figure>'.
            '<p><img src="https://iili.io/loose.png">Text</p>'.
            '<p>Before <img data-inline-image="1" src="https://iili.io/icon.png" alt="Item" style="width:900px"> after</p>'.
            '<p><img data-inline-image="1" src="https://img.example.com/icon.png"></p>';

        $forum = $sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_FORUM);

        $this->assertStringContainsString('<figure data-align="center" data-width="40"><img src="https://iili.io/abc123.png" alt="Ok"', $forum);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $forum);
        $this->assertStringContainsString('<figure><img src="https://i.imgur.com/b.png"', $forum);
        foreach (['onerror', 'img.example.com', 'evil', 'http://iili', '8443', 'user:pass', 'javascript:', 'loose.png', 'data-width="33"'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $forum);
        }
        $this->assertMatchesRegularExpression('~<p>Before <img data-inline-image="1" src="https://iili\.io/icon\.png"[^>]*> after</p>~', $forum);
        $this->assertStringNotContainsString('style=', $forum);
        $this->assertSame(3, substr_count($forum, '<img'));

        // News keeps accepting only its own uploads.
        $this->assertStringNotContainsString('<img', $sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_NEWS));
    }

    public function test_forum_video_tokens_render_only_supported_players(): void
    {
        $sanitizer = app(SafeHtmlSanitizer::class);
        $html = '<figure data-media-id="dQw4w9WgXcQ" data-media="youtube" data-align="center"><iframe src="https://evil.example"></iframe></figure>'.
            '<figure data-media="twitch" data-media-id="clip:FunnyClip-1"></figure>'.
            '<figure data-media="youtube" data-media-id="bad id"></figure>'.
            '<figure data-media="vimeo" data-media-id="123"></figure>'.
            '<figure data-media="twitch" data-media-id="channel:a&quot;onload=x"></figure>';

        $safe = $sanitizer->sanitize($html, SafeHtmlSanitizer::PROFILE_FORUM);
        $this->assertStringNotContainsString('iframe', $safe);
        $this->assertStringNotContainsString('evil', $safe);
        $this->assertSame(2, substr_count($safe, '<figure'));

        $rendered = ForumMedia::render($safe, 'forum.example.com');
        $this->assertStringContainsString('<figure class="forum-media" data-media="youtube" data-align="center"><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $rendered);
        $this->assertStringContainsString('src="https://clips.twitch.tv/embed?clip=FunnyClip-1&amp;parent=forum.example.com&amp;autoplay=false"', $rendered);
        $this->assertSame(2, substr_count($rendered, '<iframe'));
        $this->assertStringNotContainsString('onload', $rendered);
        $this->assertStringNotContainsString('vimeo', $rendered);
    }

    public function test_video_frames_are_allowed_on_forum_pages_only(): void
    {
        $forum = (string) $this->get('/forum')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-src https://www.youtube-nocookie.com https://player.twitch.tv https://clips.twitch.tv', $forum);

        $home = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('frame-src', $home);
    }

    private function section(string $name): ForumSection
    {
        return ForumSection::query()->where('name_ru', $name)->firstOrFail();
    }
}
