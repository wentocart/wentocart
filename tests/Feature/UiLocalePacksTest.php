<?php

namespace Tests\Feature;

use App\Domain\Localization\ShopLocaleDefaults;
use Tests\TestCase;

class UiLocalePacksTest extends TestCase
{
    public function test_bundled_locale_packs_cover_every_ui_key(): void
    {
        $enSourcePath = database_path('i18n/ui/en-source.json');
        $this->assertFileExists($enSourcePath);

        $enSource = json_decode((string) file_get_contents($enSourcePath), true);
        $this->assertIsArray($enSource);
        $this->assertNotEmpty($enSource);

        foreach (ShopLocaleDefaults::bundledUiLocales() as $locale) {
            $path = database_path('i18n/ui/'.$locale.'.json');
            $this->assertFileExists($path, "Missing locale pack for {$locale}");
            $pack = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($pack);
            $this->assertCount(count($enSource), $pack, "Locale {$locale} key count mismatch");

            foreach (array_keys($enSource) as $key) {
                $this->assertArrayHasKey($key, $pack, "Locale {$locale} missing key {$key}");
                $this->assertNotSame('', trim((string) $pack[$key]), "Locale {$locale} empty for {$key}");
            }
        }
    }

    public function test_merged_locale_pack_includes_bundled_languages(): void
    {
        $merged = ShopLocaleDefaults::mergedLocalePack();
        $this->assertNotEmpty($merged);

        $sampleKey = array_key_first($merged);
        $this->assertIsString($sampleKey);
        foreach (ShopLocaleDefaults::bundledUiLocales() as $locale) {
            $this->assertArrayHasKey($locale, $merged[$sampleKey]);
            $this->assertNotSame('', trim((string) $merged[$sampleKey][$locale]));
        }
    }
}
