<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\Menu;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\LocalizationPackService;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\Models\SmsLog;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityOptionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_payload_includes_optional_community_flags(): void
    {
        $this->seed();
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonMissingPath('features.visual_builder')
            ->assertJsonPath('features.meilisearch', false)
            ->assertJsonPath('features.sms_otp', false);
    }

    public function test_cms_block_translation_is_returned_for_locale(): void
    {
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 20],
        );

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/blocks', [
                'title' => 'Home hero',
                'code' => 'home_hero_opt',
                'location' => 'home',
                'content' => 'Hello EN',
                'is_enabled' => 1,
                'position' => 0,
                'translations' => [
                    'en' => ['language_code' => 'en', 'title' => 'Home hero', 'content' => 'Hello EN'],
                    'fr' => ['language_code' => 'fr', 'title' => 'Accueil', 'content' => 'Bonjour FR'],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('cms_block_translations', [
            'language_code' => 'fr',
            'title' => 'Accueil',
        ]);

        $this->getJson('/api/v1/cms/blocks?location=home&locale=fr')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Accueil', 'content' => 'Bonjour FR']);
    }

    public function test_menu_item_translation_is_returned_for_locale(): void
    {
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 20],
        );
        $menu = Menu::query()->where('code', 'main')->firstOrFail();
        $item = $menu->allItems()->first() ?: \App\Domain\Cms\Models\MenuItem::query()->create([
            'menu_id' => $menu->id,
            'label' => 'Catalog',
            'url' => '/catalog',
            'position' => 1,
        ]);
        $item->update(['label' => 'Catalog', 'url' => '/catalog']);
        $item->translations()->updateOrCreate(
            ['language_code' => 'en'],
            ['label' => 'Catalog'],
        );
        $item->translations()->updateOrCreate(
            ['language_code' => 'fr'],
            ['label' => 'Catalogue'],
        );

        $this->getJson('/api/v1/cms/menu/main?locale=fr')
            ->assertOk()
            ->assertJsonFragment(['label' => 'Catalogue']);
    }

    public function test_country_packs_include_gb_and_de(): void
    {
        $this->assertFileExists(base_path('database/localization/GB.json'));
        $this->assertFileExists(base_path('database/localization/DE.json'));
        $preview = app(LocalizationPackService::class)->preview('GB');
        $this->assertNotEmpty($preview);
    }

    public function test_catalog_search_works_when_meilisearch_is_off(): void
    {
        $this->seed();
        ShopSetting::put('search.meilisearch', false);
        $this->getJson('/api/v1/catalog/search?q=head')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_sms_otp_is_not_found_when_disabled(): void
    {
        $this->seed();
        ShopSetting::put('auth.sms_otp', false);
        $this->postJson('/api/v1/auth/sms/send', ['email' => 'demo@wentocart.test'])
            ->assertNotFound();
    }

    public function test_sms_otp_logs_and_completes_login_when_enabled(): void
    {
        $this->seed();
        ShopSetting::put('auth.sms_otp', true);
        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
            'phone' => '+15551212000',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('sms_required', true);

        $code = $login->json('sandbox_code');
        $this->assertNotEmpty($code);
        $this->assertTrue(SmsLog::query()->where('to_phone', '+15551212000')->exists());

        $this->postJson('/api/v1/auth/sms', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('customer.email', $customer->email);
    }

    public function test_admin_can_toggle_optional_community_features(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/store/community', ['key' => 'sms_otp', 'enabled' => 1])
            ->assertRedirect();
        $this->assertTrue(ShopSetting::bool('auth.sms_otp', false));

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/store/community', ['key' => 'meilisearch', 'enabled' => 1])
            ->assertRedirect();
        $this->assertTrue(ShopSetting::bool('search.meilisearch', false));

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/store/community', ['key' => 'visual_builder', 'enabled' => 0])
            ->assertSessionHasErrors('key');
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/store/community', ['key' => 'back_in_stock', 'enabled' => 0])
            ->assertSessionHasErrors('key');
    }
}
