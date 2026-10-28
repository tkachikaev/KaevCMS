<?php

namespace App\Support;

/** Builds links to the public documentation site; a topic is the page slug under /guide/. */
final class Documentation
{
    public static function url(?string $topic = null, ?string $locale = null): string
    {
        $base = rtrim((string) config('cms.docs.base_url'), '/');
        $prefix = ($locale ?? app()->getLocale()) === 'ru' ? '/' : '/en/';

        if ($topic === null || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $topic) !== 1) {
            return $base.$prefix;
        }

        return $base.$prefix.'guide/'.$topic;
    }
}
