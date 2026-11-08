<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave283Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_catalog_categories_full_crud(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/categories', [
            'name' => 'Wave283 Cat '.$suffix,
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $id);

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/categories/'.$id);
        $show->assertOk();
        $this->assertSame($id, (int) $show->json('data.id'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/categories/'.$id, [
            'name' => 'Wave283 Renamed '.$suffix,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame(0, (int) $update->json('data.status'));

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/categories/bulk-update-status', [
            'ids' => [$id],
            'value' => 1,
        ]);
        $mass->assertOk();
        $this->assertTrue((bool) Category::query()->find($id)?->is_enabled);

        $destroy = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/categories/'.$id);
        $destroy->assertOk();
        $this->assertNull(Category::query()->find($id));
    }
}
