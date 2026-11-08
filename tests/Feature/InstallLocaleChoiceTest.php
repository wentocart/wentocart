<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\ShopLocaleDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallLocaleChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_installer_language_and_currency_become_the_shop_default(): void
    {
        $this->seed();

        ShopLocaleDefaults::applyChosen('hi', 'INR');

        $this->assertSame('hi', Language::query()->where('is_default', true)->value('code'));
        $this->assertSame(1, Language::query()->where('is_default', true)->count());
        $this->assertSame('INR', Currency::query()->where('is_default', true)->value('code'));
        $this->assertSame(1, Currency::query()->where('is_default', true)->count());
        $this->assertSame('1.000000', (string) Currency::query()->where('code', 'INR')->value('exchange_rate'));
        $usdRate = (float) Currency::query()->where('code', 'USD')->value('exchange_rate');
        $this->assertGreaterThan(0, $usdRate);
        $this->assertLessThan(1, $usdRate);
        $this->assertEqualsWithDelta(29.99 * $usdRate, \App\Domain\Localization\Money::fromDefault(29.99, 'USD'), 0.02);
        $this->assertSame('hi', config('wento.default_language'));
        $this->assertSame('INR', config('wento.default_currency'));
    }

    public function test_installer_can_add_pound_when_that_currency_is_chosen(): void
    {
        $this->seed();

        ShopLocaleDefaults::applyChosen('en', 'GBP');

        $pound = Currency::query()->where('code', 'GBP')->first();
        $this->assertNotNull($pound);
        $this->assertTrue((bool) $pound->is_default);
        $this->assertSame('en', Language::query()->where('is_default', true)->value('code'));
    }
}
