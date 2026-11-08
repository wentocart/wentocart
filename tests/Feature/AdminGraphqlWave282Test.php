<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductTemplate;
use App\Domain\Catalog\Models\Feature;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave282Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_catalog_attributes_product_templates_and_home_modules(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        // Ensure a default template exists so the disposable one is not promoted.
        if (! ProductTemplate::query()->where('is_default', true)->exists()) {
            ProductTemplate::query()->create([
                'code' => 'default_'.$suffix,
                'name' => 'Default '.$suffix,
                'is_default' => true,
                'template_groups' => [],
            ]);
        }

        $attrs = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/attributes');
        $attrs->assertOk();
        $this->assertIsArray($attrs->json('data'));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/attributes', [
            'code' => 'w282_'.$suffix,
            'name' => 'Wave282 Attr '.$suffix,
            'type' => 'select',
            'status' => 1,
        ]);
        $create->assertCreated();
        $attrId = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $attrId);

        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/attributes/'.$attrId)->assertOk();

        $family = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/product-templates', [
            'code' => 'tpl_'.$suffix,
            'name' => 'Wave282 Template '.$suffix,
            'is_default' => false,
        ]);
        $family->assertCreated();
        $familyId = (int) $family->json('data.id');
        $this->assertGreaterThan(0, $familyId);
        $this->assertFalse((bool) $family->json('data.is_default'));

        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/product-templates')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/product-templates/'.$familyId)->assertOk();

        $themes = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/home-modules');
        $themes->assertOk();

        $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/attributes/'.$attrId)->assertOk();
        $this->assertNull(Feature::query()->find($attrId));

        $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/product-templates/'.$familyId)->assertOk();
        $this->assertNull(ProductTemplate::query()->find($familyId));
    }
}
