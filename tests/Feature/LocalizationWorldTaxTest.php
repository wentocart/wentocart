<?php

namespace Tests\Feature;

use App\Domain\Localization\LocalizationPackService;
use App\Domain\Localization\WorldCountries;
use App\Domain\Localization\WorldTaxCatalog;
use App\Domain\Tax\Models\TaxRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationWorldTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_australia_pack_installs_gst_and_gst_free(): void
    {
        $service = app(LocalizationPackService::class);
        $service->apply('AU', [
            'currency' => false,
            'language' => false,
            'regions' => true,
            'tax' => true,
            'units' => false,
        ]);

        $rows = TaxRule::query()
            ->with(['taxClass', 'taxRate'])
            ->get()
            ->map(fn (TaxRule $rule) => [
                'class' => $rule->taxClass?->name,
                'rate' => (float) $rule->taxRate?->rate,
            ])
            ->sortBy('rate')
            ->values()
            ->all();

        $this->assertCount(2, $rows);
        $this->assertSame('GST-free', $rows[0]['class']);
        $this->assertSame(0.0, $rows[0]['rate']);
        $this->assertSame('GST', $rows[1]['class']);
        $this->assertSame(10.0, $rows[1]['rate']);
    }

    public function test_italy_uses_world_catalog_vat_bands(): void
    {
        $taxes = WorldTaxCatalog::taxesFor('IT');
        $this->assertCount(3, $taxes);
        $this->assertSame(22, $taxes[0]['rate']);
        $this->assertSame(0, $taxes[1]['rate']);
        $this->assertSame(10, $taxes[2]['rate']);

        $preview = app(LocalizationPackService::class)->preview('IT');
        $tax = collect($preview['components'])->firstWhere('key', 'tax');
        $this->assertTrue($tax['available']);
        $this->assertStringContainsString('3 rates', $tax['hint']);
    }

    public function test_json_packs_win_over_world_catalog(): void
    {
        $data = (new \ReflectionClass(LocalizationPackService::class))
            ->getMethod('resolvePackData');
        $data->setAccessible(true);
        $resolved = $data->invoke(app(LocalizationPackService::class), 'IN');

        $this->assertNotEmpty($resolved['taxes']);
        $this->assertGreaterThanOrEqual(5, count($resolved['taxes']));
        $this->assertTrue(str_starts_with((string) ($resolved['taxes'][0]['class'] ?? ''), 'GST'));
    }

    public function test_major_markets_have_starter_tax(): void
    {
        $required = ['AU', 'AE', 'NZ', 'SG', 'GB', 'DE', 'FR', 'IT', 'ES', 'JP', 'IN', 'US', 'CA', 'MX', 'BR', 'ZA', 'SA'];
        $service = app(LocalizationPackService::class);

        foreach ($required as $code) {
            $preview = $service->preview($code);
            $tax = collect($preview['components'])->firstWhere('key', 'tax');
            $this->assertTrue($tax['available'] ?? false, "Expected starter tax for {$code}");
        }
    }

    public function test_world_catalog_covers_most_iso_countries_with_vat(): void
    {
        $world = WorldCountries::all();
        $withTax = 0;
        $without = [];

        foreach ($world as $row) {
            $code = $row['code'];
            $json = database_path('localization/'.$code.'.json');
            $hasJsonTax = false;
            if (is_file($json)) {
                $data = json_decode((string) file_get_contents($json), true) ?: [];
                $hasJsonTax = ! empty($data['taxes']) || ! empty($data['tax']);
            }

            if ($hasJsonTax || WorldTaxCatalog::hasTax($code)) {
                $withTax++;
            } else {
                $without[] = $code;
            }
        }

        // Starters are national VAT/GST markets — no-tax jurisdictions (HK, some islands) stay empty on purpose.
        $this->assertGreaterThanOrEqual(160, $withTax, 'Expected broad worldwide tax starter coverage');
        $this->assertLessThan(count($world), $withTax, 'Some no-VAT countries should remain without starter tax');
        $this->assertNotContains('AU', $without);
        $this->assertNotContains('IT', $without);
    }
}
