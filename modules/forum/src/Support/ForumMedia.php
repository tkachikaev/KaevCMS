<?php

namespace KaevCMS\Modules\Forum\Support;

use App\Services\Html\SafeHtmlSanitizer;

/**
 * Turns sanitized video tokens (<figure data-media data-media-id>) into players.
 * Only the two validated values reach the player URL; nothing from the post body
 * is copied into the iframe markup.
 */
final class ForumMedia
{
    public static function render(string $safeHtml, string $host): string
    {
        if (! str_contains($safeHtml, 'data-media=')) {
            return $safeHtml;
        }

        return preg_replace_callback(
            '~<figure\b([^>]*)\bdata-media="([a-z]+)"([^>]*)\bdata-media-id="([A-Za-z0-9_:-]+)"([^>]*)></figure>~',
            static function (array $match) use ($host): string {
                [$all, , $provider, , $mediaId] = $match;
                if (! SafeHtmlSanitizer::validForumMedia($provider, $mediaId)) {
                    return '';
                }
                preg_match('~data-align="(center|right)"~', $all, $align);
                $alignAttribute = isset($align[1]) ? ' data-align="'.$align[1].'"' : '';
                $src = self::playerUrl($provider, $mediaId, $host);
                $title = $provider === 'youtube' ? 'YouTube' : 'Twitch';

                return '<figure class="forum-media" data-media="'.$provider.'"'.$alignAttribute.'>'
                    .'<iframe src="'.e($src).'" title="'.$title.'" loading="lazy" '
                    .'allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen '
                    .'referrerpolicy="strict-origin-when-cross-origin"></iframe></figure>';
            },
            $safeHtml,
        ) ?? $safeHtml;
    }

    private static function playerUrl(string $provider, string $mediaId, string $host): string
    {
        if ($provider === 'youtube') {
            return 'https://www.youtube-nocookie.com/embed/'.$mediaId;
        }

        [$kind, $value] = explode(':', $mediaId, 2);
        $parent = rawurlencode($host);

        return match ($kind) {
            'video' => 'https://player.twitch.tv/?video=v'.$value.'&parent='.$parent.'&autoplay=false',
            'clip' => 'https://clips.twitch.tv/embed?clip='.rawurlencode($value).'&parent='.$parent.'&autoplay=false',
            default => 'https://player.twitch.tv/?channel='.rawurlencode($value).'&parent='.$parent.'&autoplay=false',
        };
    }
}
