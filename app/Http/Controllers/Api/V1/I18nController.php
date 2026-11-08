<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Localization\Models\Language;
use App\Domain\Localization\ShopLocaleDefaults;
use App\Domain\Localization\UiI18n;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class I18nController extends Controller
{
    public function show(string $locale): JsonResponse
    {
        $enabled = Language::query()->enabled()->pluck('code')->all();
        if (! in_array($locale, $enabled, true)) {
            $locale = Language::defaultCode();
        }

        return response()->json([
            'locale' => $locale,
            'languages' => Language::enabledList()->map(fn (Language $language) => [
                'code' => $language->code,
                'name' => $language->name,
                'is_default' => $language->is_default,
            ])->values(),
            'messages' => UiI18n::messages($locale, 'storefront'),
        ]);
    }

    public function switchLocale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
        ]);

        $currency = ShopLocaleDefaults::currencyFor($data['locale']);

        return response()->json(['locale' => $data['locale'], 'currency' => $currency])
            ->cookie('wento_locale', $data['locale'], 60 * 24 * 365, '/', null, false, false)
            ->cookie('wento_currency', $currency, 60 * 24 * 365, '/', null, false, false);
    }

    public function switchCurrency(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', Rule::exists('currencies', 'code')->where('is_enabled', true)],
        ]);
        $code = strtoupper($data['currency']);

        return response()->json(['currency' => $code])
            ->cookie('wento_currency', $code, 60 * 24 * 365, '/', null, false, false);
    }
}
