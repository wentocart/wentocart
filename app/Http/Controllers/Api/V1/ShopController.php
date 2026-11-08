<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Money;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\PrivacySettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ReturnSettings;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Store\ShopIdentity;
use App\Domain\Store\StoreContext;
use App\Domain\Theme\ThemeResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ShopController extends Controller
{
    /**
     */
    public function storeConfig(): JsonResponse
    {
        return response()->json(\App\Domain\Api\Graphql\GraphqlShopStoreConfig::payload());
    }

    /**
     */
    public function storeSettingFields(): JsonResponse
    {
        $payload = \App\Domain\Api\Graphql\GraphqlShopStoreConfig::payload();
        $rows = [];
        $i = 1;
        foreach ($payload as $code => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $rows[] = [
                'id' => $i++,
                'code' => (string) $code,
                'value' => $value,
                'storeCode' => (string) ($payload['storeCode'] ?? 'default'),
                'localeCode' => (string) ($payload['locale'] ?? app()->getLocale()),
            ];
        }

        return response()->json($rows);
    }

    public function showStoreSettingField(string $id): JsonResponse
    {
        $rows = $this->storeSettingFields()->getData(true);
        if (! is_array($rows)) {
            return response()->json(['message' => 'Config field not found.', 'success' => false], 404);
        }
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') === (string) $id || (string) ($row['code'] ?? '') === (string) $id) {
                return response()->json($row);
            }
        }

        return response()->json(['message' => 'Config field not found.', 'success' => false], 404);
    }

    public function show(Request $request, ThemeResolver $themes, CatalogPresenter $present): JsonResponse
    {
        $locale = app()->getLocale();
        $store = app(StoreContext::class)->store();
        $announcement = null;
        try {
            $block = CmsBlock::query()
                ->where('is_enabled', true)
                ->where('location', 'header')
                ->with('translations')
                ->orderBy('position')
                ->first();
            $announcement = $block?->localizedContent();
        } catch (Throwable) {
            $announcement = null;
        }
        $theme = $themes->bootPayload($store);
        $settings = $theme['settings'] ?? [];
        $notice = $announcement ?: ($settings['announcement'] ?? null);
        $hooked = wento_hook('storefront.header.notice', ['notice' => $notice]);
        $announcement = is_string($hooked['notice'] ?? null) ? $hooked['notice'] : $notice;

        $headerIds = array_values(array_filter(array_map('intval', $settings['home']['header']['category_ids'] ?? [])));
        $allEnabledCategories = Category::query()
            ->where('is_enabled', true)
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $categories = collect($present->categoryForest(
            $allEnabledCategories,
            $locale,
            $headerIds !== [] ? $headerIds : null,
        ));
        if ($headerIds === []) {
            $categories = $categories->take(16)->values();
        }

        $identity = ShopIdentity::forStorefront($store, $locale);

        return response()->json([
            'shop' => [
                'name' => $identity['name'],
                'email' => $identity['email'] ?: $store?->email,
                'phone' => $identity['phone'],
                'address' => $identity['address'],
                'vat' => $identity['vat'],
                'gst' => $identity['gst'],
                'business_number' => $identity['business_number'],
                'footer' => $identity['footer'],
                'theme' => $theme,
                'announcement' => $announcement,
                'announcement_url' => $settings['announcement_url'] ?? '/catalog',
                'install_id' => (string) (\App\Domain\Settings\Models\Setting::getValue('install_id') ?: ''),
            ],
            'locale' => $locale,
            'currency' => Money::currentCode(),
            'currencies' => Currency::query()->where('is_enabled', true)->orderBy('position')->get(['code', 'name', 'symbol', 'is_default']),
            'languages' => Language::enabledList()->map(fn (Language $language) => [
                'code' => $language->code,
                'name' => $language->name,
                'is_default' => $language->is_default,
            ])->values(),
            'countries' => Country::optionsForAddresses(),
            'categories' => $categories->values(),
            'menu' => null,
            'modules' => [
                'header_top' => ModuleBuses::collectSlots('storefront.header.top'),
                'footer' => ModuleBuses::collectSlots('storefront.footer'),
                'home_body' => ModuleBuses::collectSlots('storefront.home.body'),
                'account_menu' => ModuleBuses::collectSlots('storefront.account.menu'),
            ],
            'ai' => \App\Domain\Ai\StorefrontAssistantSettings::forStorefront(),
            'features' => array_merge([
                'multivendor' => \App\Domain\Marketplace\MarketplaceSettings::enabled(),
            ], \App\Domain\Settings\CommunityFeatures::payload()),
            'products' => [
                'compare_enabled' => ProductSettings::compareEnabled(),
                'compare_max' => ProductSettings::compareMax(),
                'list_highlight' => ProductSettings::listPayload(),
                'per_page' => ProductSettings::storefrontPerPage(),
                'hide_out_of_stock' => ProductSettings::hideOutOfStock(),
                'out_of_stock_behavior' => ProductSettings::outOfStockBehavior(),
                'catalog_mode' => ProductSettings::catalogMode(),
                'show_price' => ProductSettings::showPrice(),
                'hide_prices_guests' => ProductSettings::hidePricesGuests(),
                'out_of_stock_notification' => ProductSettings::outOfStockNotification(),
            ],
            'orders' => OrderSettings::storefrontPayload(),
            'customers' => CustomerSettings::storefrontPayload(),
            'commerce' => [
                'cart' => CartCheckoutSettings::storefrontPayload(),
                'inventory' => InventorySettings::storefrontPayload(),
                'pricing' => PricingSettings::storefrontPayload(),
                'returns' => array_merge(ReturnSettings::storefrontPayload(), [
                    'reasons' => \App\Domain\Order\Models\ReturnReason::catalog(),
                ]),
                'search' => SearchCatalogSettings::storefrontPayload(),
                'reviews' => ReviewSettings::storefrontPayload(),
                'documents' => DocumentCommerceSettings::storefrontPayload(),
                'privacy' => PrivacySettings::storefrontPayload(),
                'gdpr' => \App\Domain\Settings\GdprSettings::storefrontPayload(),
                'loyalty' => \App\Domain\Settings\LoyaltySettings::storefrontPayload(),
            ],
        ]);
    }

    public function sharedWishlist(string $token, CatalogPresenter $present): JsonResponse
    {
        $share = \App\Domain\Customer\Models\WishlistShare::query()
            ->where('token', $token)
            ->where('is_enabled', true)
            ->firstOrFail();
        $locale = app()->getLocale();
        $ids = $share->customer?->wishlist()->pluck('product_id') ?? collect();
        $products = $present->withRatings(\App\Domain\Catalog\Models\Product::query()->whereIn('id', $ids))->get()
            ->map(fn (\App\Domain\Catalog\Models\Product $product) => $present->product($product, $locale))
            ->values();

        return response()->json(['wishlist' => $products]);
    }
}
