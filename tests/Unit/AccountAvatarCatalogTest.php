<?php

namespace Tests\Unit;

use App\Services\Account\AccountAvatarCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountAvatarCatalogTest extends TestCase
{
    public function test_catalog_discovers_only_safe_supported_files_in_natural_order(): void
    {
        $root = storage_path('framework/testing/account-avatars-'.Str::uuid());
        config()->set('cms.account_avatars.uploads_path', $root);

        try {
            File::ensureDirectoryExists($root);
            File::put($root.'/010-mage.png', 'image');
            File::put($root.'/002-warrior.webp', 'image');
            File::put($root.'/notes.txt', 'not-image');
            File::put($root.'/bad name.jpg', 'image');
            File::put($root.'/.hidden.jpeg', 'image');
            File::ensureDirectoryExists($root.'/nested');
            File::put($root.'/nested/020-hidden.webp', 'image');

            $catalog = new AccountAvatarCatalog;
            $avatars = $catalog->all();

            $this->assertSame(['002-warrior.webp', '010-mage.png'], array_column($avatars, 'filename'));
            $this->assertStringContainsString('/uploads/account-avatars/002-warrior.webp?v=', $avatars[0]['url']);
            $this->assertTrue($catalog->contains('010-mage.png'));
            $this->assertFalse($catalog->contains('../010-mage.png'));
            $this->assertNull($catalog->url('notes.txt'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_catalog_returns_an_empty_list_when_the_directory_does_not_exist(): void
    {
        $root = storage_path('framework/testing/missing-account-avatars-'.Str::uuid());
        config()->set('cms.account_avatars.uploads_path', $root);

        $catalog = new AccountAvatarCatalog;

        $this->assertSame([], $catalog->all());
        $this->assertNull($catalog->url('missing.webp'));
    }
}
