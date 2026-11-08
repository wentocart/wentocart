<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\CustomerGroup;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave284Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_customer_groups_put_delete(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/groups', [
            'name' => 'Wave284 Group '.$suffix,
            'code' => 'w284_'.$suffix,
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/customers/groups/'.$id, [
            'name' => 'Wave284 Renamed '.$suffix,
            'discountPercent' => 5,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame(0, (int) $update->json('status'));
        $this->assertSame(5.0, (float) $update->json('discountPercent'));

        $destroy = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/customers/groups/'.$id);
        $destroy->assertOk();
        $this->assertNull(CustomerGroup::query()->find($id));
    }
}
