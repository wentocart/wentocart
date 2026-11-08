<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Models\UiString;
use App\Domain\Localization\UiI18n;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminI18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_registers_catalog_and_layout_keys(): void
    {
        $this->seed();
        UiString::query()->create([
            'surface' => 'admin',
            'page' => 'page.key',
            'key' => 'page.key',
            'source' => 'English default',
        ]);
        UiI18n::sync();

        $this->assertTrue(UiString::query()->where('key', 'admin.nav.product')->exists());
        $this->assertTrue(UiString::query()->where('key', 'admin.login.title')->exists());
        $this->assertTrue(UiString::query()->where('key', 'storefront.cart.checkout')->exists());
        $this->assertFalse(UiString::query()->where('key', 'page.key')->exists());
        $this->assertFalse(UiString::query()->where('key', 'storefront.page.key')->exists());
        $this->assertSame('Product', UiI18n::messages('en', 'admin')['admin.nav.product']);
        $this->assertTrue(UiString::query()->where('key', 'admin.products.name')->exists());
        $this->assertSame('Name', UiI18n::messages('en', 'admin')['admin.products.name']);
        $names = collect(UiI18n::pageSummaries('en', 'admin'))->keyBy('id');
        $this->assertSame('Admin · Products', $names['admin.products']['name']);
        $this->assertGreaterThan(20, UiString::query()->where('page', 'admin.products')->count());
    }

    public function test_admin_can_save_a_page_translation(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        UiI18n::sync();

        $this->put('/admin/localization/translations', [
            'language' => 'hi',
            'page' => 'admin.nav',
            'strings' => [
                'admin.nav.product' => 'उत्पाद',
            ],
        ])->assertRedirect();

        $this->assertSame('उत्पाद', UiI18n::messages('hi', 'admin')['admin.nav.product']);
        $this->assertSame('Catalog', UiI18n::messages('hi', 'admin')['admin.nav.catalog']);
        $this->assertSame('hi', session('admin_locale'));
    }

    public function test_english_override_is_used_when_viewing_english(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        UiI18n::sync();

        $this->put('/admin/localization/translations', [
            'language' => 'en',
            'page' => 'admin.translations',
            'strings' => [
                'admin.translations.title' => 'Translationn',
            ],
        ])->assertRedirect();

        $this->assertSame('Translationn', UiI18n::messages('en', 'admin')['admin.translations.title']);
        $this->assertSame('en', session('admin_locale'));
    }

    public function test_translations_page_is_ok(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/localization/translations?language=hi&surface=admin')
            ->assertOk();
    }

    public function test_cannot_disable_default_language(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $english = Language::query()->where('code', 'en')->firstOrFail();
        $this->assertTrue($english->is_default);

        $this->post("/admin/localization/languages/{$english->id}/toggle")->assertRedirect();
        $this->assertTrue($english->fresh()->is_enabled);
    }

    public function test_guest_can_switch_admin_locale_on_login(): void
    {
        $this->seed();

        $this->from('/admin/login')
            ->post('/admin/locale', ['locale' => 'hi'])
            ->assertRedirect('/admin/login');

        $this->assertSame('hi', session('admin_locale'));
        $this->assertSame('en', Language::defaultCode());
        $this->assertTrue(Language::query()->where('code', 'en')->value('is_default'));
        $this->assertFalse(Language::query()->where('code', 'hi')->value('is_default'));
    }

    public function test_guest_login_shares_translated_messages_for_non_english_locale(): void
    {
        $this->seed();
        UiI18n::sync();
        UiI18n::savePage('hi', 'admin.login', [
            'admin.login.title' => 'साइन इन',
            'admin.login.submit' => 'प्रवेश',
        ]);

        $english = $this->get('/admin/login')->assertOk();
        $englishProps = $english->original->getData()['page']['props']['i18n'];
        $this->assertSame('en', $englishProps['locale']);
        $this->assertSame([], $englishProps['messages']);

        $hindi = $this->withSession(['admin_locale' => 'hi'])->get('/admin/login')->assertOk();
        $hindiProps = $hindi->original->getData()['page']['props']['i18n'];
        $this->assertSame('hi', $hindiProps['locale']);
        $this->assertSame('साइन इन', $hindiProps['messages']['admin.login.title'] ?? null);
        $this->assertSame('प्रवेश', $hindiProps['messages']['admin.login.submit'] ?? null);
    }

    public function test_empty_language_falls_back_to_english_then_source(): void
    {
        $this->seed();
        UiI18n::sync();
        UiI18n::savePage('en', 'admin.nav', ['admin.nav.product' => 'Product EN']);

        $this->assertSame('Product EN', UiI18n::messages('es', 'admin')['admin.nav.product']);
        $this->assertSame('Catalog', UiI18n::messages('es', 'admin')['admin.nav.catalog']);
        $this->assertSame('Product EN', UiI18n::messages('en', 'admin')['admin.nav.product']);
    }

    public function test_switching_current_language_does_not_change_default(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->from('/admin/products')
            ->post('/admin/locale', ['locale' => 'hi'])
            ->assertRedirect('/admin/products');

        $this->assertSame('hi', session('admin_locale'));
        $this->assertSame('en', Language::defaultCode());
    }

    public function test_storefront_i18n_api_returns_messages(): void
    {
        $this->seed();
        UiI18n::sync();
        UiI18n::savePage('hi', 'storefront.cart', ['storefront.cart.checkout' => 'चेकआउट']);

        $response = $this->get('/api/v1/i18n/hi')->assertOk();
        $this->assertSame('hi', $response->json('locale'));
        $this->assertSame('चेकआउट', $response->json('messages')['storefront.cart.checkout']);
    }

    public function test_catalog_manager_cannot_open_translations(): void
    {
        $this->seed();
        $staff = Admin::factory()->create(['email' => 'catalog-i18n@store.test']);
        $staff->roles()->sync([Role::query()->where('slug', 'catalog_manager')->value('id')]);

        $this->actingAs($staff, 'admin');
        $this->get('/admin/localization/translations')->assertForbidden();
        $this->get('/admin/localization/languages')->assertForbidden();
    }
}
