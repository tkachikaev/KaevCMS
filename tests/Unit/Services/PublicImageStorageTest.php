<?php

namespace Tests\Unit\Services;

use App\Services\Images\PublicImageStorage;
use PHPUnit\Framework\TestCase;

class PublicImageStorageTest extends TestCase
{
    public function test_raster_mime_mapping_is_centralized(): void
    {
        $storage = new PublicImageStorage;

        $this->assertSame('jpg', $storage->extensionForMime('image/jpeg'));
        $this->assertSame('png', $storage->extensionForMime('image/png'));
        $this->assertSame('webp', $storage->extensionForMime('image/webp'));
        $this->assertNull($storage->extensionForMime('image/svg+xml'));
    }

    public function test_normalize_path_rejects_traversal_and_unexpected_upload_paths(): void
    {
        $storage = new PublicImageStorage;
        $pattern = '~^news/content/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp)$~i';
        $valid = 'news/content/2026/08/123e4567-e89b-12d3-a456-426614174000.webp';

        $this->assertSame($valid, $storage->normalizePath('/'.$valid, $pattern));
        $this->assertNull($storage->normalizePath('../'.$valid, $pattern));
        $this->assertNull($storage->normalizePath('news/content/2026/08/../../secret.webp', $pattern));
        $this->assertNull($storage->normalizePath('pages/content/2026/08/example.webp', $pattern));
        $this->assertSame($valid, $storage->normalizePath('  '.$valid.'  ', $pattern, trim: true));
    }
}
