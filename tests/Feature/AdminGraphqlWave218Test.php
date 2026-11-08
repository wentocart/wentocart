<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave218Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_channels_countries_locales_newsletters(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $channels = $this->getJson('/api/v1/store/stores?per_page=20');
        $stores->assertOk();
        $this->assertIsArray($stores->json());
        $this->assertNotEmpty($stores->json());
        $this->assertNotEmpty((string) $stores->headers->get('X-Total-Count'));
        $first = $stores->json('0');
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('hostname', $first);
        $this->assertArrayHasKey('locales', $first);

        $storeId = (int) Store::query()->where('is_enabled', true)->value('id');
        $this->getJson('/api/v1/store/stores/'.$storeId)->assertOk()
            ->assertJsonPath('id', $storeId);

        $locales = $this->getJson('/api/v1/store/locales?per_page=50');
        $locales->assertOk();
        $this->assertNotEmpty($locales->json());
        $this->assertContains($locales->json('0.direction'), ['ltr', 'rtl']);
        $langId = (int) Language::query()->where('is_enabled', true)->value('id');
        $this->getJson('/api/v1/store/locales/'.$langId)->assertOk()
            ->assertJsonPath('id', $langId);

        $countries = $this->getJson('/api/v1/store/countries?per_page=5');
        $countries->assertOk();
        $this->assertNotEmpty($countries->json());
        $countryId = (int) ($countries->json('0.id') ?? Country::query()->where('is_enabled', true)->value('id'));
        $this->getJson('/api/v1/store/countries/'.$countryId)->assertOk()
            ->assertJsonPath('id', $countryId);
        $states = $this->getJson('/api/v1/store/countries/'.$countryId.'/states');
        $states->assertOk();
        $this->assertIsArray($states->json());

        $sub = $this->postJson('/api/v1/store/newsletters', [
            'customerEmail' => 'wave218@example.test',
        ]);
        $sub->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'wave218@example.test']);

        $dup = $this->postJson('/api/v1/newsletters', [
            'customerEmail' => 'wave218@example.test',
        ]);
        $dup->assertStatus(400)->assertJsonPath('success', false);

        $bad = $this->postJson('/api/v1/store/newsletters', []);
        $bad->assertStatus(400);

        $this->assertSame(1, NewsletterSubscriber::query()->where('email', 'wave218@example.test')->count());
    }
}
