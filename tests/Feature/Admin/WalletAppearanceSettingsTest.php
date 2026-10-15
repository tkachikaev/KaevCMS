<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CmsSetting;
use App\Services\Wallet\WalletAppearanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WalletAppearanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadRoot = storage_path('framework/testing/wallet-icons');
        File::deleteDirectory($this->uploadRoot);
        config()->set('cms.settings.uploads_path', $this->uploadRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploadRoot);
        parent::tearDown();
    }

    public function test_default_names_and_pluralization_are_localized_without_database_writes(): void
    {
        $settings = app(WalletAppearanceSettings::class);
        $this->assertSame('1 монета', $settings->label(1, 'ru'));
        $this->assertSame('2 монеты', $settings->label(2, 'ru'));
        $this->assertSame('5 монет', $settings->label(5, 'ru'));
        $this->assertSame('11 монет', $settings->label(11, 'ru'));
        $this->assertSame('21 монета', $settings->label(21, 'ru'));
        $this->assertSame('1 coin', $settings->label(1, 'en'));
        $this->assertSame('100 coins', $settings->label(100, 'en'));
        $this->assertNull($settings->values()['icon_url']);
        $this->assertDatabaseCount('cms_settings', 0);
    }

    public function test_guest_and_editor_cannot_change_wallet_appearance(): void
    {
        $this->get('/admin/settings/wallet-appearance')->assertRedirect(route('admin.login'));
        $this->put('/admin/settings/wallet-appearance', $this->validFields())
            ->assertRedirect(route('admin.login'));

        $editor = $this->admin('editor');
        $this->actingAs($editor, 'admin')->get('/admin/settings/wallet-appearance')->assertForbidden();
        $this->actingAs($editor, 'admin')->put('/admin/settings/wallet-appearance', $this->validFields())->assertForbidden();
        $this->assertDatabaseMissing('cms_settings', ['key' => 'wallet.appearance.symbol']);
    }

    public function test_auditor_can_view_but_cannot_edit(): void
    {
        $auditor = $this->admin('auditor');
        $this->actingAs($auditor, 'admin')->get('/admin/settings/wallet-appearance')->assertOk();
        $this->actingAs($auditor, 'admin')->put('/admin/settings/wallet-appearance', $this->validFields())->assertForbidden();
        $this->assertDatabaseMissing('cms_settings', ['key' => 'wallet.appearance.symbol']);
    }

    public function test_owner_updates_ru_en_forms_and_icon_without_touching_balances(): void
    {
        $this->actingAs($this->admin('owner'), 'admin')
            ->put('/admin/settings/wallet-appearance', array_merge($this->validFields(), [
                'icon' => $this->png('icon.png'),
            ]))
            ->assertRedirect(route('admin.settings.wallet-appearance'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', ['key' => 'wallet.appearance.ru.one', 'value' => 'дублон']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'wallet.appearance.en.many', 'value' => 'doubloons']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'wallet.appearance.symbol', 'value' => 'DBL']);
        $icon = (string) CmsSetting::query()->where('key', 'wallet.appearance.icon')->value('value');
        $this->assertMatchesRegularExpression('~^settings/wallet-coin/[a-f0-9-]+\.png$~', $icon);
        $this->assertFileExists($this->uploadRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $icon));
        $this->assertSame('5 дублонов', app(WalletAppearanceSettings::class)->label(5, 'ru'));
        $this->assertSame('2 doubloons', app(WalletAppearanceSettings::class)->label(2, 'en'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.wallet_appearance_updated']);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_replacement_and_removal_clean_only_own_wallet_icons(): void
    {
        $this->actingAs($this->admin('owner'), 'admin');
        $this->put('/admin/settings/wallet-appearance', array_merge($this->validFields(), [
            'icon' => $this->png('first.png'),
        ]))->assertRedirect();
        $first = (string) CmsSetting::query()->where('key', 'wallet.appearance.icon')->value('value');

        $this->put('/admin/settings/wallet-appearance', array_merge($this->validFields(), [
            'icon' => $this->png('second.png'),
        ]))->assertRedirect();
        $second = (string) CmsSetting::query()->where('key', 'wallet.appearance.icon')->value('value');
        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($this->uploadRoot.'/'.$first);
        $this->assertFileExists($this->uploadRoot.'/'.$second);

        $this->put('/admin/settings/wallet-appearance', array_merge($this->validFields(), ['remove_icon' => '1']))->assertRedirect();
        $this->assertFileDoesNotExist($this->uploadRoot.'/'.$second);
        $this->assertSame('', (string) CmsSetting::query()->where('key', 'wallet.appearance.icon')->value('value'));
    }

    public function test_svg_and_fake_png_are_rejected_and_do_not_overwrite_existing_settings(): void
    {
        $this->actingAs($this->admin('owner'), 'admin');
        foreach ([
            UploadedFile::fake()->createWithContent('coin.svg', '<svg onload="alert(1)"></svg>'),
            UploadedFile::fake()->createWithContent('coin.png', 'not actually a png'),
            $this->png('coin.jpg'),
            UploadedFile::fake()->create('huge.webp', 1500, 'image/webp'),
        ] as $file) {
            $this->put('/admin/settings/wallet-appearance', array_merge($this->validFields(), ['icon' => $file]))
                ->assertSessionHasErrors('icon');
        }
        $this->assertDatabaseMissing('cms_settings', ['key' => 'wallet.appearance.symbol']);
    }

    public function test_missing_grammatical_form_is_rejected_without_partial_saving(): void
    {
        $this->actingAs($this->admin('owner'), 'admin');
        $fields = $this->validFields();
        $fields['translations']['ru']['many'] = '';
        $this->put('/admin/settings/wallet-appearance', $fields)
            ->assertSessionHasErrors('translations.ru.many');
        $this->assertDatabaseMissing('cms_settings', ['key' => 'wallet.appearance.symbol']);
    }

    public function test_stored_path_traversal_and_unsafe_filenames_cannot_be_rendered(): void
    {
        CmsSetting::query()->updateOrCreate(['key' => 'wallet.appearance.icon'], ['value' => 'settings/wallet-coin/../../unsafe.php']);
        $this->assertNull(app(WalletAppearanceSettings::class)->values()['icon_url']);
    }

    /** @return array<string,mixed> */
    private function validFields(): array
    {
        return [
            'symbol' => 'DBL',
            'translations' => [
                'ru' => ['one' => 'дублон', 'few' => 'дублона', 'many' => 'дублонов'],
                'en' => ['one' => 'doubloon', 'few' => 'doubloons', 'many' => 'doubloons'],
            ],
        ];
    }

    private function admin(string $role): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role),
            'email' => $role.'-wallet@example.test',
            'password' => Hash::make('Safe-Test-Password-123'),
            'is_active' => true,
            'role' => $role,
        ]);
    }

    private function png(string $filename): UploadedFile
    {
        $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2V1sAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($filename, $data ?: '');
    }
}
