<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductTemplate;
use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogFamilyEdgeLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribute_family_edges_and_configurable_type_alias_round_trip(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $default = ProductTemplate::query()->where('is_default', true)->first();
        if (! $default) {
            $default = ProductTemplate::query()->create([
                'code' => 'default_'.$suffix,
                'name' => 'Default '.$suffix,
                'is_default' => true,
                'template_groups' => [],
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->putJson('/api/v1/console/product-templates/'.$default->id, [
                'is_default' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation');

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/product-templates', [
            'code' => 'bad_'.$suffix,
            'name' => 'Bad groups '.$suffix,
            'is_default' => false,
            'template_groups' => ['not-an-object'],
        ])->assertStatus(422);

        $family = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/product-templates', [
            'code' => 'good_'.$suffix,
            'name' => 'Good groups '.$suffix,
            'is_default' => false,
            'template_groups' => [
                ['name' => 'General', 'attributes' => []],
            ],
        ]);
        $family->assertCreated();
        $familyId = (int) $family->json('data.id');
        $showFamily = $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/product-templates/'.$familyId);
        $showFamily->assertOk();
        $this->assertIsArray($showFamily->json('data.templateGroups'));
        $this->assertSame('General', (string) ($showFamily->json('data.templateGroups.0.name') ?? ''));

        // Type alias: API "configurable" → Wento combination (required variant_axes).
        $alias = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'CFG-'.$suffix,
            'type' => 'configurable',
            'name' => 'Configurable '.$suffix,
            'price' => 10,
            'status' => 1,
        ]);
        $alias->assertStatus(422);
        $this->assertStringContainsString('configurable', strtolower((string) $alias->json('message')));

        $simple = Product::query()->where('type', 'simple')->where('is_enabled', true)->first();
        $this->assertNotNull($simple);
        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$simple->id);
        $show->assertOk();
        $this->assertSame('simple', (string) ($show->json('data.type') ?? ''));
    }
}
