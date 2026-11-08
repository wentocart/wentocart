<?php

namespace App\Domain\Store;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\WorldCountries;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\Store;

/**
 * Shop contact / legal identity used on the storefront footer, invoices, and emails.
 *
 * Structured address lives in shop.identity settings. stores.address stays a composed
 * string so existing documents keep working.
 */
final class ShopIdentity
{
    public const SETTING = 'shop.identity';

    /** @var list<string> */
    public const FOOTER_KEYS = ['name', 'email', 'phone', 'address', 'vat', 'gst', 'business_number'];

    /**
     * @return array<string, mixed>
     */
    public static function get(?Store $store = null): array
    {
        $store ??= app(StoreContext::class)->store();
        $saved = ShopSetting::json(self::SETTING, []);
        $base = self::defaults();
        $identity = array_replace($base, is_array($saved) ? $saved : []);
        $identity['names'] = self::normalizeNames($identity['names'] ?? [], $store);
        $identity['email'] = trim((string) ($identity['email'] ?: ($store?->email ?: Setting::getValue('shop_email', ''))));
        $identity['phone'] = trim((string) ($identity['phone'] ?? ''));
        $identity['footer'] = self::normalizeFooter($identity['footer'] ?? []);

        foreach (['line1', 'line2', 'city', 'region', 'postal', 'country_code', 'vat', 'gst', 'business_number'] as $key) {
            $identity[$key] = trim((string) ($identity[$key] ?? ''));
        }

        if ($identity['business_number'] === '') {
            $identity['business_number'] = $identity['gst'] !== '' ? $identity['gst'] : $identity['vat'];
        }
        if (! ($identity['footer']['business_number'] ?? false)
            && (($identity['footer']['gst'] ?? false) || ($identity['footer']['vat'] ?? false))) {
            $identity['footer']['business_number'] = true;
        }

        return self::splitCombinedAddress(self::hydrateFromInstall($identity));
    }

    /**
     * Admin form payload plus composed address for the current shop.
     *
     * @return array<string, mixed>
     */
    public static function forAdmin(?Store $store = null): array
    {
        $identity = self::get($store);
        $identity['address'] = self::formattedAddress($identity);

        return $identity;
    }

    /**
     * Storefront JSON: localized name, composed address, footer flags.
     *
     * @return array<string, mixed>
     */
    public static function forStorefront(?Store $store = null, ?string $locale = null): array
    {
        $identity = self::get($store);
        $locale = $locale ?: app()->getLocale();
        $default = Language::defaultCode();
        $names = $identity['names'];
        $name = trim((string) ($names[$locale] ?? $names[$default] ?? $store?->name ?? config('app.name')));

        return [
            'name' => $name,
            'email' => $identity['email'],
            'phone' => $identity['phone'],
            'address' => self::formattedAddress($identity),
            'vat' => $identity['vat'],
            'gst' => $identity['gst'],
            'business_number' => $identity['business_number'],
            'footer' => $identity['footer'],
        ];
    }

    /**
     * Invoice / email shop block.
     *
     * @return array{name: string, email: string, address: string, phone: string, vat: string, gst: string, business_number: string}
     */
    public static function forDocuments(?Store $store = null): array
    {
        $public = self::forStorefront($store, Language::defaultCode());

        return [
            'name' => $public['name'],
            'email' => $public['email'],
            'address' => $public['address'],
            'phone' => $public['phone'],
            'vat' => $public['vat'] !== '' ? $public['vat'] : $public['business_number'],
            'gst' => $public['gst'],
            'business_number' => $public['business_number'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function save(Store $store, array $input): void
    {
        $current = self::get($store);
        $names = self::normalizeNames($input['names'] ?? $current['names'], $store);
        $default = Language::defaultCode();
        $name = trim((string) ($names[$default] ?? $input['shop_name'] ?? $store->name));
        if ($name === '') {
            $name = (string) ($store->name ?: config('app.name'));
        }
        $names[$default] = $name;

        $businessNumber = array_key_exists('business_number', $input)
            ? trim((string) $input['business_number'])
            : trim((string) ($current['business_number'] ?: ($current['gst'] ?: $current['vat'])));

        $identity = [
            'names' => $names,
            'email' => trim((string) ($input['email'] ?? $input['shop_email'] ?? $current['email'])),
            'phone' => trim((string) ($input['phone'] ?? $current['phone'])),
            'line1' => trim((string) ($input['line1'] ?? $current['line1'])),
            'line2' => trim((string) ($input['line2'] ?? $current['line2'])),
            'city' => trim((string) ($input['city'] ?? $current['city'])),
            'region' => trim((string) ($input['region'] ?? $current['region'])),
            'postal' => trim((string) ($input['postal'] ?? $current['postal'])),
            'country_code' => strtoupper(trim((string) ($input['country_code'] ?? $current['country_code']))),
            'vat' => '',
            'gst' => '',
            'business_number' => $businessNumber,
            'footer' => self::normalizeFooter($input['footer'] ?? $current['footer']),
        ];

        $composed = self::formattedAddress($identity);
        ShopSetting::put(self::SETTING, $identity);

        $store->fill([
            'name' => $name,
            'email' => $identity['email'] !== '' ? $identity['email'] : $store->email,
            'address' => $composed,
        ]);
        $store->save();

        if ($store->is_default) {
            Setting::setValue('shop_name', $store->name);
            Setting::setValue('shop_email', $store->email ?? '');
            Setting::setValue('shop_address', $composed);
            Setting::setValue('shop_phone', $identity['phone']);
            Setting::setValue('shop_line1', $identity['line1']);
            Setting::setValue('shop_line2', $identity['line2']);
            Setting::setValue('shop_city', $identity['city']);
            Setting::setValue('shop_region', $identity['region']);
            Setting::setValue('shop_postal', $identity['postal']);
            Setting::setValue('shop_country', $identity['country_code']);
        }
    }

    /**
     * Persist wizard / CLI store fields into identity so Configure → Store matches install.
     *
     * @param  array<string, mixed>  $input
     */
    public static function captureInstall(array $input, ?Store $store = null): void
    {
        $store ??= StoreBootstrap::ensure();
        $line1 = trim((string) ($input['store_line1'] ?? ''));
        $line2 = trim((string) ($input['store_line2'] ?? ''));
        $city = trim((string) ($input['store_city'] ?? ''));
        $region = trim((string) ($input['store_region'] ?? ''));
        $postal = trim((string) ($input['store_postal'] ?? ''));
        $country = strtoupper(trim((string) ($input['store_country'] ?? $input['store_country_code'] ?? '')));
        $phone = trim((string) ($input['store_phone'] ?? ''));

        $composedLegacy = trim((string) ($input['store_address'] ?? ''));
        if ($line1 === '' && $line2 === '' && $city === '' && $region === '' && $postal === '' && $country === '' && $composedLegacy !== '') {
            $line1 = $composedLegacy;
        }

        $shopName = trim((string) ($input['store_name'] ?? $store->name));
        $defaultLang = Language::defaultCode();

        self::save($store, [
            'names' => [$defaultLang => $shopName],
            'shop_name' => $shopName,
            'email' => $input['store_email'] ?? ($store->email ?: ''),
            'phone' => $phone,
            'line1' => $line1,
            'line2' => $line2,
            'city' => $city,
            'region' => $region,
            'postal' => $postal,
            'country_code' => $country,
            'business_number' => '',
            'footer' => self::defaults()['footer'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    public static function formattedAddress(array $identity): string
    {
        $countryName = self::countryName((string) ($identity['country_code'] ?? ''));
        $parts = array_values(array_filter([
            trim((string) ($identity['line1'] ?? '')),
            trim((string) ($identity['line2'] ?? '')),
            implode(', ', array_filter([
                trim((string) ($identity['city'] ?? '')),
                trim((string) ($identity['region'] ?? '')),
                trim((string) ($identity['postal'] ?? '')),
            ])),
            $countryName,
        ], fn ($part) => $part !== ''));

        return implode("\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'names' => [],
            'email' => '',
            'phone' => '',
            'line1' => '',
            'line2' => '',
            'city' => '',
            'region' => '',
            'postal' => '',
            'country_code' => '',
            'vat' => '',
            'gst' => '',
            'business_number' => '',
            'footer' => [
                'name' => true,
                'email' => true,
                'phone' => true,
                'address' => true,
                'vat' => false,
                'gst' => false,
                'business_number' => false,
            ],
        ];
    }

    /**
     * @param  mixed  $names
     * @return array<string, string>
     */
    protected static function normalizeNames(mixed $names, ?Store $store): array
    {
        $out = [];
        if (is_array($names)) {
            foreach ($names as $code => $value) {
                $code = strtolower(trim((string) $code));
                if ($code === '') {
                    continue;
                }
                $out[$code] = trim((string) $value);
            }
        }
        $default = Language::defaultCode();
        if (($out[$default] ?? '') === '') {
            $out[$default] = trim((string) ($store?->name ?: Setting::getValue('shop_name', config('app.name'))));
        }

        return $out;
    }

    /**
     * @param  mixed  $footer
     * @return array<string, bool>
     */
    protected static function normalizeFooter(mixed $footer): array
    {
        $defaults = self::defaults()['footer'];
        $raw = is_array($footer) ? $footer : [];
        foreach (self::FOOTER_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $defaults[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $defaults;
    }

    /**
     * Fill empty identity fields from installer settings (shop_line1, shop_country, …).
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    protected static function hydrateFromInstall(array $identity): array
    {
        $map = [
            'line1' => 'shop_line1',
            'line2' => 'shop_line2',
            'city' => 'shop_city',
            'region' => 'shop_region',
            'postal' => 'shop_postal',
            'country_code' => 'shop_country',
            'phone' => 'shop_phone',
        ];
        foreach ($map as $key => $setting) {
            if (trim((string) ($identity[$key] ?? '')) !== '') {
                continue;
            }
            $identity[$key] = trim((string) Setting::getValue($setting, ''));
        }

        return $identity;
    }

    /**
     * Installer used to join every address box into Address line 1.
     * When the other boxes are empty, put those lines back into their fields.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    protected static function splitCombinedAddress(array $identity): array
    {
        foreach (['line2', 'city', 'region', 'postal', 'country_code'] as $key) {
            if (trim((string) ($identity[$key] ?? '')) !== '') {
                return $identity;
            }
        }

        $lines = array_values(array_filter(array_map(
            'trim',
            preg_split("/\r\n|\n|\r/", (string) ($identity['line1'] ?? '')) ?: [],
        ), fn ($line) => $line !== ''));
        if (count($lines) < 2) {
            return $identity;
        }

        $last = (string) array_pop($lines);
        $country = self::countryCodeFromName($last);
        if ($country !== '') {
            $identity['country_code'] = $country;
        } else {
            $lines[] = $last;
        }

        $slots = ['line1', 'line2', 'city', 'region', 'postal'];
        foreach ($slots as $index => $slot) {
            $identity[$slot] = $lines[$index] ?? '';
        }
        if (count($lines) > count($slots)) {
            $identity['postal'] = implode(' ', array_slice($lines, count($slots) - 1));
        }

        return $identity;
    }

    protected static function countryCodeFromName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return '';
        }
        foreach (WorldCountries::all() as $row) {
            if (strtolower((string) ($row['name'] ?? '')) === $name) {
                return strtoupper((string) $row['code']);
            }
        }

        return '';
    }

    protected static function countryName(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }

        return (string) (Country::query()->where('code', $code)->value('name') ?: '');
    }
}
