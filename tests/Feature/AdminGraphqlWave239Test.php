<?php

namespace Tests\Feature;

use App\Domain\Api\Models\StorefrontApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminGraphqlWave239Test extends TestCase
{
    use RefreshDatabase;

    public function test_manage_storefront_keys_cli(): void
    {
        $this->seed();

        Artisan::call('wento:generate-storefront-key', [
            '--name' => 'Wave239 Key',
            '--rate-limit' => '100',
        ]);
        $row = StorefrontApiKey::query()->where('name', 'Wave239 Key')->firstOrFail();
        $oldId = (int) $row->id;

        $status = Artisan::call('wento:manage-storefront-keys', [
            'action' => 'status',
            '--key' => 'Wave239 Key',
        ]);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Active: Yes', Artisan::output());

        $rate = Artisan::call('wento:manage-storefront-keys', [
            'action' => 'rate-limit',
            '--key' => (string) $oldId,
            '--rate-limit' => '400',
        ]);
        $this->assertSame(0, $rate);
        $this->assertSame(400, (int) $row->fresh()->rate_limit);

        $rotate = Artisan::call('wento:manage-storefront-keys', [
            'action' => 'rotate',
            '--key' => 'Wave239 Key',
            '--force' => true,
        ]);
        $this->assertSame(0, $rotate);
        $out = Artisan::output();
        $this->assertStringContainsString('pk_storefront_', $out);
        $this->assertStringContainsString('disabled immediately', $out);

        $old = StorefrontApiKey::query()->find($oldId);
        $this->assertNotNull($old);
        $this->assertFalse((bool) $old->is_enabled);
        $this->assertStringContainsString('(rotated', (string) $old->name);

        $new = StorefrontApiKey::query()->where('name', 'Wave239 Key')->where('is_enabled', true)->first();
        $this->assertNotNull($new);
        $this->assertNotSame($oldId, (int) $new->id);

        Artisan::call('wento:manage-storefront-keys', [
            'action' => 'deactivate',
            '--key' => (string) $new->id,
            '--reason' => 'test',
            '--force' => true,
        ]);
        $this->assertFalse((bool) $new->fresh()->is_enabled);

        Artisan::call('wento:manage-storefront-keys', [
            'action' => 'activate',
            '--key' => (string) $new->id,
        ]);
        $this->assertTrue((bool) $new->fresh()->is_enabled);

        $summary = Artisan::call('wento:manage-storefront-keys', ['action' => 'summary']);
        $this->assertSame(0, $summary);
        $this->assertStringContainsString('Total Keys:', Artisan::output());

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'manage-storefront-keys')),
            'landing should highlight manage-storefront-keys'
        );
    }
}
