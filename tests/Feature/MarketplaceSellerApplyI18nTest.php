<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerTranslation;

class MarketplaceSellerApplyI18nTest extends MarketplaceTestCase
{
    /** @return array<string, mixed> */
    protected function applyPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Seller',
            'shop_name' => 'Ada Market',
            'shop_slug' => 'ada-i18n-market',
            'profile_name' => 'Ada Shop',
            'email' => 'ada.i18n@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada Seller',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '12 Market Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Handmade goods',
        ], $overrides);
    }

    protected function registerSellerCustomer(string $email = 'ada.i18n@example.com'): Customer
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Seller',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    protected function enableOnlyEnglish(): void
    {
        Language::query()->where('code', '!=', 'en')->update(['is_enabled' => false]);
        Language::query()->where('code', 'en')->update(['is_enabled' => true, 'is_default' => true]);
    }

    public function test_show_returns_languages_from_store_config(): void
    {
        $this->seed();
        $this->enableOnlyEnglish();
        $this->registerSellerCustomer();

        $this->getJson('/api/v1/account/seller')
            ->assertOk()
            ->assertJsonPath('default_locale', 'en')
            ->assertJsonCount(1, 'languages')
            ->assertJsonPath('languages.0.code', 'en');
    }

    public function test_show_lists_all_enabled_languages_when_multiple(): void
    {
        $this->seed();
        $this->registerSellerCustomer('ada.langs@example.com');

        $enabled = Language::enabledList()->count();
        $this->assertGreaterThanOrEqual(2, $enabled);

        $this->getJson('/api/v1/account/seller')
            ->assertOk()
            ->assertJsonCount($enabled, 'languages');
    }

    public function test_apply_stores_default_locale_translation_row(): void
    {
        $this->seed();
        $this->enableOnlyEnglish();
        $this->registerSellerCustomer();

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())
            ->assertCreated()
            ->assertJsonPath('seller.shop_name', 'Ada Market')
            ->assertJsonPath('seller.translations.0.language_code', 'en')
            ->assertJsonPath('seller.translations.0.shop_name', 'Ada Market');

        $seller = Seller::query()->where('shop_slug', 'ada-i18n-market')->firstOrFail();
        $this->assertDatabaseHas('seller_translations', [
            'seller_id' => $seller->id,
            'language_code' => 'en',
            'shop_name' => 'Ada Market',
        ]);
    }

    public function test_apply_accepts_multiple_locale_shop_fields(): void
    {
        $this->seed();
        Language::query()->whereIn('code', ['en', 'hi'])->update(['is_enabled' => true]);
        Language::query()->whereNotIn('code', ['en', 'hi'])->update(['is_enabled' => false]);
        $this->registerSellerCustomer('ada.multi@example.com');

        $payload = $this->applyPayload([
            'email' => 'ada.multi@example.com',
            'shop_slug' => 'ada-multi-shop',
            'shop_name' => 'Ada Market EN',
            'description' => 'English description',
            'translations' => [
                [
                    'language_code' => 'en',
                    'shop_name' => 'Ada Market EN',
                    'description' => 'English description',
                ],
                [
                    'language_code' => 'hi',
                    'shop_name' => 'Ada Market HI',
                    'description' => 'Hindi description',
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/account/seller/apply', $payload)
            ->assertCreated()
            ->assertJsonPath('seller.shop_name', 'Ada Market EN');

        $translations = collect($response->json('seller.translations'));
        $this->assertCount(2, $translations);
        $this->assertTrue($translations->contains(fn ($row) => $row['language_code'] === 'hi' && $row['shop_name'] === 'Ada Market HI'));

        $seller = Seller::query()->where('shop_slug', 'ada-multi-shop')->firstOrFail();
        $this->assertSame(2, SellerTranslation::query()->where('seller_id', $seller->id)->count());
        $this->assertDatabaseHas('seller_translations', [
            'seller_id' => $seller->id,
            'language_code' => 'hi',
            'shop_name' => 'Ada Market HI',
        ]);

        $this->getJson('/api/v1/account/seller')
            ->assertOk()
            ->assertJsonCount(2, 'languages');
    }

    public function test_disabled_multivendor_blocks_apply_with_translations(): void
    {
        $this->seed();
        MarketplaceSettings::setEnabled(false);
        $this->registerSellerCustomer('ada.off@example.com');

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'ada.off@example.com',
            'shop_slug' => 'ada-off-shop',
            'translations' => [
                ['language_code' => 'en', 'shop_name' => 'Ada Off', 'description' => null],
            ],
        ]))->assertForbidden();
    }

    public function test_present_uses_current_locale_shop_name(): void
    {
        $this->seed();
        Language::query()->whereIn('code', ['en', 'hi'])->update(['is_enabled' => true]);
        Language::query()->whereNotIn('code', ['en', 'hi'])->update(['is_enabled' => false]);
        $customer = $this->registerSellerCustomer('ada.locale@example.com');

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'ada.locale@example.com',
            'shop_slug' => 'ada-locale-shop',
            'shop_name' => 'English Shop',
            'translations' => [
                ['language_code' => 'en', 'shop_name' => 'English Shop', 'description' => 'EN'],
                ['language_code' => 'hi', 'shop_name' => 'Hindi Shop', 'description' => 'HI'],
            ],
        ]))->assertCreated();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/seller?locale=hi')
            ->assertOk()
            ->assertJsonPath('seller.shop_name', 'Hindi Shop')
            ->assertJsonPath('seller.description', 'HI');

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/seller?locale=en')
            ->assertOk()
            ->assertJsonPath('seller.shop_name', 'English Shop')
            ->assertJsonPath('seller.description', 'EN');
    }

    public function test_show_returns_countries_with_regions(): void
    {
        $this->seed();
        $this->enableOnlyEnglish();
        $this->registerSellerCustomer('ada.regions@example.com');

        $response = $this->getJson('/api/v1/account/seller')->assertOk();
        $countries = collect($response->json('countries'));
        $this->assertNotEmpty($countries);
        $us = $countries->firstWhere('code', 'US');
        $this->assertNotNull($us);
        $this->assertArrayHasKey('regions', $us);
        $this->assertArrayHasKey('has_regions', $us);
    }

    public function test_apply_stores_state_as_region_code(): void
    {
        $this->seed();
        $this->enableOnlyEnglish();
        $this->registerSellerCustomer('ada.statecode@example.com');

        $countries = \App\Domain\Localization\Models\Country::optionsForAddresses();
        $withRegions = collect($countries)->first(fn ($row) => ! empty($row['regions']));
        if (! $withRegions) {
            $this->markTestSkipped('No country packs with regions installed in seed.');
        }

        $region = $withRegions['regions'][0];

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'ada.statecode@example.com',
            'shop_slug' => 'ada-state-code',
            'country_code' => $withRegions['code'],
            'state' => $region['code'],
        ]))
            ->assertCreated()
            ->assertJsonPath('seller.state', $region['code'])
            ->assertJsonPath('seller.country_code', $withRegions['code']);

        $this->assertDatabaseHas('sellers', [
            'shop_slug' => 'ada-state-code',
            'state' => $region['code'],
            'country_code' => $withRegions['code'],
        ]);
    }

    public function test_apply_validation_returns_field_errors(): void
    {
        $this->seed();
        $this->enableOnlyEnglish();
        $this->registerSellerCustomer('ada.invalid@example.com');

        $this->postJson('/api/v1/account/seller/apply', [
            'first_name' => '',
            'last_name' => '',
            'shop_name' => '',
            'shop_slug' => '',
            'profile_name' => '',
            'email' => 'not-an-email',
            'phone' => '',
            'contact_name' => '',
            'contact_phone' => '',
            'address_line1' => '',
            'city' => '',
            'postal_code' => '',
            'country_code' => '',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'shop_name',
                'shop_slug',
                'profile_name',
                'email',
                'phone',
                'contact_name',
                'contact_phone',
                'address_line1',
                'city',
                'postal_code',
                'country_code',
            ]);
    }
}
