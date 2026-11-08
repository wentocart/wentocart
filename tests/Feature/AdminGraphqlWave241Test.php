<?php

namespace Tests\Feature;

use App\Domain\Api\Models\StorefrontApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminGraphqlWave241Test extends TestCase
{
    use RefreshDatabase;

    public function test_maintain_storefront_keys_and_schedule(): void
    {
        $this->seed();

        $active = StorefrontApiKey::issue('Wave241 Active', null, 100)['model'];
        $inactive = StorefrontApiKey::issue('Wave241 Inactive', null, 100)['model'];
        $inactive->is_enabled = false;
        $inactive->last_used_at = now()->subDays(120);
        $inactive->save();

        $rotatedName = StorefrontApiKey::issue('Keep Name (rotated 2020-01-01)', null, 50)['model'];
        // Safety-net case: rotated suffix but still enabled
        $this->assertTrue((bool) $rotatedName->is_enabled);

        $code = Artisan::call('wento:maintain-storefront-keys', ['--all' => true, '--unused' => 90]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('maintenance complete', strtolower($out));
        $this->assertStringContainsString('Deleted', $out);

        $this->assertNull(StorefrontApiKey::query()->find($inactive->id));
        $this->assertNotNull(StorefrontApiKey::query()->find($active->id));
        $this->assertFalse((bool) $rotatedName->fresh()->is_enabled);

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertTrue(
            $events->contains(fn ($e) => str_contains((string) $e->command, 'wento:maintain-storefront-keys')),
            'schedule should include maintain-storefront-keys'
        );

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'maintain-storefront-keys')),
            'landing should highlight key maintenance'
        );
    }
}
