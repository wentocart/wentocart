<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave294Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_settings_mass_destroy_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        foreach ([
            '/api/v1/console/settings/locales/bulk-delete',
            '/api/v1/console/settings/currencies/bulk-delete',
            '/api/v1/console/settings/tax-rates/bulk-delete',
            '/api/v1/console/settings/tax-categories/bulk-delete',
        ] as $path) {
            $res = $this->actingAs($admin, 'admin')->postJson($path, ['ids' => []]);
            $this->assertContains($res->status(), [200, 422], $path.' => '.$res->status());
        }
    }
}
