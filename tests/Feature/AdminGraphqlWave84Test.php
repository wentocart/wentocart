<?php

namespace Tests\Feature;

use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave84Test extends TestCase
{
    use RefreshDatabase;

    public function test_tax_csv_exports_and_mass_deletes(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $rate = TaxRate::query()->create([
            'name' => 'Wave84 Rate',
            'rate' => 7.5,
            'is_enabled' => true,
        ]);
        $category = TaxClass::query()->create([
            'name' => 'Wave84 Category',
        ]);

        $ratesCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/tax-rates/export');
        $ratesCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $ratesCsv->headers->get('content-type'));
        $this->assertStringContainsString('Wave84 Rate', $ratesCsv->streamedContent());

        $rulesCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/tax-rules/export');
        $rulesCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $rulesCsv->headers->get('content-type'));

        $alias = $this->actingAs($admin, 'admin')->get('/api/v1/console/tax-categories/export');
        $alias->assertOk();

        $rateMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminSettingsTaxRate(ids: [$id]) {
                    ok updated_count deleted skipped
                }
            }',
            'variables' => ['id' => (string) $rate->id],
        ]);
        $rateMass->assertOk();
        $this->assertArrayNotHasKey('errors', $rateMass->json() ?? [], json_encode($rateMass->json()));
        $this->assertSame(1, (int) $rateMass->json('data.bulkDeleteAdminSettingsTaxRate.updated_count'));
        $this->assertNull(TaxRate::query()->find($rate->id));

        $catMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminSettingsTaxCategory(ids: [$id]) {
                    ok updated_count deleted
                }
            }',
            'variables' => ['id' => (string) $category->id],
        ]);
        $catMass->assertOk();
        $this->assertArrayNotHasKey('errors', $catMass->json() ?? [], json_encode($catMass->json()));
        $this->assertSame(1, (int) $catMass->json('data.bulkDeleteAdminSettingsTaxCategory.updated_count'));
        $this->assertNull(TaxClass::query()->find($category->id));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/tax-rates/export')->assertUnauthorized();
    }
}
