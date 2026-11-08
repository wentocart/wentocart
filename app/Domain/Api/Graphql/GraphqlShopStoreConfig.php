<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Auth\SocialLogin;
use App\Domain\Localization\Money;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\ShopIdentity;
use App\Domain\Store\StoreContext;

/**
 * Shop REST `storeConfig` / GET /api/v1/store/store-config — public non-secret settings.
 */
final class GraphqlShopStoreConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $locale = app()->getLocale();
        $identity = ShopIdentity::forStorefront(app(StoreContext::class)->store(), $locale);
        $store = GraphqlShopStoreSettings::defaultStore();
        $pricing = PricingSettings::storefrontPayload();
        $reviews = ReviewSettings::storefrontPayload();
        $customers = CustomerSettings::storefrontPayload();
        $orders = OrderSettings::storefrontPayload();
        $cart = CartCheckoutSettings::storefrontPayload();
        $gdpr = GdprSettings::storefrontPayload();
        $social = SocialLogin::publicFlags();
        $enabledSocial = [];
        foreach ($social as $provider => $enabled) {
            if ($enabled) {
                $enabledSocial[] = (string) $provider;
            }
        }

        $minEnabled = OrderSettings::minimumOrderEnabled();
        $minAmount = $minEnabled ? (float) OrderSettings::minAmount() : 0.0;
        $minMessage = trim((string) ShopSetting::get('orders.minimum_order_description', ''));
        $offerInfo = trim((string) ShopSetting::get('checkout.mini_cart_offer_info', ''));
        $offerEnabled = ShopSetting::bool('checkout.mini_cart_shown', true) && $offerInfo !== '';

        return [
            'storeCode' => (string) ($store['code'] ?? 'default'),
            'locale' => $locale,
            'currency' => Money::currentCode(),
            'storeName' => (string) ($identity['name'] ?? ''),
            'storeEmail' => (string) ($identity['email'] ?? ''),
            'guestCheckout' => (bool) CustomerSettings::guestCheckout(),
            'registrationEnabled' => (bool) CustomerSettings::registrationEnabled(),
            'minimumOrderAmount' => $minAmount,
            'minimumOrderMessage' => $minMessage,
            'minimumOrderEnabled' => $minEnabled,
            'wishlistEnabled' => SearchCatalogSettings::wishlistEnabled(),
            'compareEnabled' => ProductSettings::compareEnabled(),
            'compareMax' => ProductSettings::compareMax(),
            'buyNowEnabled' => ShopSetting::bool('catalog.buy_now_enabled', true),
            'imageSearchEnabled' => ShopSetting::bool('catalog.image_search_enabled', false),
            'productsPerPage' => ProductSettings::storefrontPerPage(),
            'hideOutOfStock' => ProductSettings::hideOutOfStock(),
            'catalogMode' => ProductSettings::catalogMode(),
            'showPrice' => ProductSettings::showPrice(),
            'hidePricesGuests' => ProductSettings::hidePricesGuests(),
            'emailVerification' => (string) CustomerSettings::emailVerification(),
            'newsletterEnabled' => (bool) ($customers['newsletter_opt_in'] ?? true),
            'socialLoginProviders' => $enabledSocial,
            'taxDisplay' => (string) ($pricing['display'] ?? $pricing['tax_display'] ?? 'excluding_tax'),
            'reviewsEnabled' => (bool) ($reviews['enabled'] ?? true),
            'reviewWho' => (string) ($reviews['who'] ?? 'registered'),
            'reviewModeration' => (string) ($reviews['moderation'] ?? 'required'),
            'gdprEnabled' => (bool) ($gdpr['enabled'] ?? GdprSettings::enabled()),
            'euWithdrawalEnabled' => ShopSetting::bool('privacy.eu_withdrawal_enabled', true)
                || ShopSetting::bool('orders.eu_withdrawal_enabled', true),
            'miniCartOfferInfoEnabled' => $offerEnabled,
            'miniCartOfferInfo' => $offerEnabled ? $offerInfo : '',
            'couponsEnabled' => (bool) ($cart['coupons_enabled'] ?? true),
            'checkoutLayout' => (string) ($cart['layout'] ?? 'one_page'),
            'maxCartItems' => (int) ($cart['max_cart_items'] ?? 50),
            'customerNotesEnabled' => (bool) ($orders['customer_notes_enabled'] ?? false),
            // snake_case aliases for Wento clients
            'guest_checkout' => (bool) CustomerSettings::guestCheckout(),
            'minimum_order_amount' => $minAmount,
            'minimum_order_message' => $minMessage,
            'wishlist_enabled' => SearchCatalogSettings::wishlistEnabled(),
            'compare_enabled' => ProductSettings::compareEnabled(),
            'buy_now_enabled' => ShopSetting::bool('catalog.buy_now_enabled', true),
            'image_search_enabled' => ShopSetting::bool('catalog.image_search_enabled', false),
            'products_per_page' => ProductSettings::storefrontPerPage(),
            'email_verification' => (string) CustomerSettings::emailVerification(),
            'newsletter_enabled' => (bool) ($customers['newsletter_opt_in'] ?? true),
            'social_login_providers' => $enabledSocial,
            'tax_display' => (string) ($pricing['display'] ?? $pricing['tax_display'] ?? 'excluding_tax'),
            'gdpr_enabled' => (bool) ($gdpr['enabled'] ?? GdprSettings::enabled()),
            'eu_withdrawal_enabled' => ShopSetting::bool('privacy.eu_withdrawal_enabled', true)
                || ShopSetting::bool('orders.eu_withdrawal_enabled', true),
            'mini_cart_offer_info_enabled' => $offerEnabled,
            'mini_cart_offer_info' => $offerEnabled ? $offerInfo : '',
        ];
    }
}
