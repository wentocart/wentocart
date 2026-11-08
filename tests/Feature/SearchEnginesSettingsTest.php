<?php

namespace Tests\Feature;

use App\Domain\Search\SearchEngineManager;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchEnginesSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_preferred_engine_and_elasticsearch(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $this->actingAs($admin, 'admin')->put('/admin/search-settings', [
            'default_sort' => 'popularity',
            'per_page' => 24,
            'suggestions' => 1,
            'typo_tolerance' => 0,
            'recently_viewed' => 1,
            'wishlist_enabled' => 1,
            'engine' => 'elasticsearch',
            'elasticsearch' => 1,
            'elasticsearch_host' => 'http://127.0.0.1:9200',
            'elasticsearch_user' => 'elastic',
            'elasticsearch_pass' => 'secret',
            'elasticsearch_index' => 'wento_products',
            'meilisearch' => 0,
        ])->assertRedirect();

        $this->assertSame('elasticsearch', ShopSetting::get('search.engine'));
        $this->assertTrue(ShopSetting::bool('search.elasticsearch', false));
        $this->assertSame('http://127.0.0.1:9200', ShopSetting::get('search.elasticsearch_host'));
        $this->assertSame('wento_products', ShopSetting::get('search.elasticsearch_index'));

        $payload = SearchCatalogSettings::payload();
        $this->assertSame('elasticsearch', $payload['engine']);
        $this->assertTrue($payload['elasticsearch']);
        $this->assertContains('elasticsearch', array_column(app(SearchEngineManager::class)->describe(), 'name'));
    }
}
