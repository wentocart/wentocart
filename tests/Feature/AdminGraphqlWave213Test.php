<?php

namespace Tests\Feature;

use App\Domain\Api\Models\AdminApiAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminGraphqlWave213Test extends TestCase
{
    use RefreshDatabase;

    public function test_prune_admin_api_audits_artisan_command(): void
    {
        $this->seed();

        AdminApiAudit::query()->create([
            'admin_id' => 1,
            'method' => 'POST',
            'path' => '/api/v1/console/integrations',
            'operation' => 'POST /api/v1/console/integrations',
            'status' => 201,
            'request_payload' => ['name' => 'old'],
            'response_summary' => ['ok' => true],
            'created_at' => now()->subDays(120),
        ]);
        AdminApiAudit::query()->create([
            'admin_id' => 1,
            'method' => 'POST',
            'path' => '/api/v1/console/integrations',
            'operation' => 'POST /api/v1/console/integrations',
            'status' => 201,
            'request_payload' => ['name' => 'fresh'],
            'response_summary' => ['ok' => true],
            'created_at' => now()->subDay(),
        ]);

        $this->assertSame(2, AdminApiAudit::query()->count());

        $exit = Artisan::call('wento:prune-admin-api-audits', ['--days' => 90]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pruned 1', Artisan::output());
        $this->assertSame(1, AdminApiAudit::query()->count());
        $this->assertSame('fresh', (string) (AdminApiAudit::query()->first()?->request_payload['name'] ?? ''));
    }
}
