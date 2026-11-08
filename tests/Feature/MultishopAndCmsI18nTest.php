<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\Models\Setting;
use App\Domain\Store\StoreService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultishopAndCmsI18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_enable_multishop_stamps_existing_customers(): void
    {
        $this->seed();

        $orphan = Customer::createTrusted([
            'email' => 'orphan@example.com',
            'password' => Hash::make('password'),
            'name' => 'Orphan User',
            'is_enabled' => true,
            'store_id' => null,
        ]);

        app(StoreService::class)->enable();

        $this->assertSame('1', (string) Setting::getValue('multishop_enabled', '0'));
        $this->assertNotNull($orphan->fresh()->store_id);
    }

    public function test_register_stamps_store_id(): void
    {
        $this->seed();

        $this->postJson('/api/v1/auth/register', [
            'email' => 'shopper@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'name' => 'Shop Per',
        ])->assertOk();

        $customer = Customer::query()->where('email', 'shopper@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertNotNull($customer->store_id);
    }

    public function test_unmatched_host_returns_404_when_multishop_enabled(): void
    {
        $this->seed();
        app(StoreService::class)->enable();
        ShopUrl::query()->update(['domain' => 'brand-a.test']);

        $this->get('/')->assertNotFound();
    }

    public function test_cms_page_saves_per_language(): void
    {
        $this->seed();
        Language::query()->updateOrCreate(
            ['code' => 'hi'],
            ['name' => 'Hindi', 'is_enabled' => true, 'is_default' => false]
        );
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/cms', [
            'status' => 'published',
            'is_visible' => true,
            'is_enabled' => true,
            'template' => 'blank',
            'robots' => 'index,follow',
            'canonical_url' => '',
            'cms_category_id' => null,
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'title' => 'About us',
                    'slug' => 'about-us',
                    'body' => '<p>English</p>',
                    'short_description' => '',
                    'meta_title' => 'About us',
                    'meta_description' => '',
                    'meta_keywords' => '',
                ],
                'hi' => [
                    'language_code' => 'hi',
                    'title' => 'हमारे बारे में',
                    'slug' => 'hamare-bare-mein',
                    'body' => '<p>Hindi</p>',
                    'short_description' => '',
                    'meta_title' => 'हमारे बारे में',
                    'meta_description' => '',
                    'meta_keywords' => '',
                ],
            ],
        ])->assertRedirect();

        $page = CmsPage::query()->whereHas('translations', fn ($q) => $q->where('slug', 'about-us'))->first();
        $this->assertNotNull($page);
        $this->assertSame('About us', $page->translations()->where('language_code', 'en')->value('title'));
        $this->assertSame('हमारे बारे में', $page->translations()->where('language_code', 'hi')->value('title'));
        $this->assertSame('hamare-bare-mein', $page->translations()->where('language_code', 'hi')->value('slug'));
    }
}
