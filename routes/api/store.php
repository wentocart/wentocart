<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ShopBookingSlotsController;
use App\Http\Controllers\Api\V1\AdminMarketplaceFlagReasonsRestController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\EuWithdrawalController;
use App\Http\Controllers\Api\V1\ExtensionMarketplaceController;
use App\Http\Controllers\Api\V1\MarketplaceShopController;
use App\Http\Controllers\Api\V1\NewsletterController;
use App\Http\Controllers\Api\V1\ShopAccountRestController;
use App\Http\Controllers\Api\V1\ShopReferenceController;
use App\Http\Controllers\Api\V1\ShopAttributesController;
use App\Http\Controllers\Api\V1\ShopCategoriesController;
use App\Http\Controllers\Api\V1\ShopCartController;
use App\Http\Controllers\Api\V1\ShopCustomizableFilesController;
use App\Http\Controllers\Api\V1\ShopCmsPagesController;
use App\Http\Controllers\Api\V1\ShopCheckoutController;
use App\Http\Controllers\Api\V1\ShopCustomerAuthController;
use App\Http\Controllers\Api\V1\ShopReviewsController;
use App\Http\Controllers\Api\V1\ShopForgotPasswordController;
use App\Http\Controllers\Api\V1\ShopProductsController;
use App\Http\Controllers\Api\V1\ShopProductSubresourcesController;
use App\Http\Controllers\Api\V1\ShopBookingSlotConfigsController;
use App\Http\Controllers\Api\V1\ShopThemeController;
use App\Http\Controllers\Api\V1\ShopController;
    $storefrontKey = ['storefront.key'];
    Route::get('/store', [ShopController::class, 'show'])->middleware($storefrontKey);
    // The storefront loads this payload from /shop. Keep /store as the same response.
    Route::get('/shop', [ShopController::class, 'show'])->middleware($storefrontKey);
    // Shop REST store-config (public non-secret channel settings)
    Route::get('/store/store-config', [ShopController::class, 'storeConfig'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store-config', [ShopController::class, 'storeConfig'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/configs', [ShopController::class, 'storeConfig'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/configs', [ShopController::class, 'storeConfig'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    // Shop REST GDPR status (public)
    Route::get('/store/gdpr-status', [ShopAccountRestController::class, 'gdprStatus'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/gdpr-status', [ShopAccountRestController::class, 'gdprStatus'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    // Shop REST booking-slots
    Route::get('/store/booking-slots', ShopBookingSlotsController::class)
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/booking-slots', ShopBookingSlotsController::class)
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    Route::get('/store/booking_product_default_slots', [ShopBookingSlotConfigsController::class, 'defaultSlots'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/booking_product_default_slots/{id}', [ShopBookingSlotConfigsController::class, 'showDefaultSlot'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/booking_product_appointment_slots', [ShopBookingSlotConfigsController::class, 'appointmentSlots'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/booking_product_appointment_slots/{id}', [ShopBookingSlotConfigsController::class, 'showAppointmentSlot'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/booking_product_rental_slots', [ShopBookingSlotConfigsController::class, 'rentalSlots'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/booking_product_rental_slots/{id}', [ShopBookingSlotConfigsController::class, 'showRentalSlot'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/booking_product_table_slots', [ShopBookingSlotConfigsController::class, 'tableSlots'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/booking_product_table_slots/{id}', [ShopBookingSlotConfigsController::class, 'showTableSlot'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/booking_product_event_tickets', [ShopBookingSlotConfigsController::class, 'eventTickets'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/booking_product_event_tickets/{id}', [ShopBookingSlotConfigsController::class, 'showEventTicket'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/extensions/marketplace', ExtensionMarketplaceController::class)->middleware('throttle:60,1');
    Route::post('/newsletter', [NewsletterController::class, 'subscribe'])->middleware(['throttle:8,1,shop-newsletter', 'honeypot']);
    // Shop REST newsletters
    Route::post('/store/newsletters', [NewsletterController::class, 'subscribe'])->middleware(['throttle:8,1,shop-newsletters', 'honeypot']);
    Route::post('/newsletters', [NewsletterController::class, 'subscribe'])->middleware(['throttle:8,1,shop-newsletters-alias', 'honeypot']);
    Route::delete('/store/newsletters', [NewsletterController::class, 'unsubscribe'])
        ->middleware([...$storefrontKey, 'throttle:8,1,shop-newsletters-unsub']);
    Route::post('/store/unsubscribe-newsletters', [NewsletterController::class, 'unsubscribe'])
        ->middleware([...$storefrontKey, 'throttle:8,1,shop-newsletters-unsub-post', 'honeypot']);
    Route::post('/contact', [ContactController::class, 'store'])->middleware(['throttle:8,1', 'honeypot']);
    // Shop REST contact-us aliases
    Route::post('/store/contact-us', [ContactController::class, 'store'])->middleware(['throttle:8,1', 'honeypot']);
    Route::post('/contact-us', [ContactController::class, 'store'])->middleware(['throttle:8,1', 'honeypot']);
    // Shop REST channels / countries / locales
    Route::get('/store/stores', [ShopReferenceController::class, 'stores'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/stores/{id}', [ShopReferenceController::class, 'showStore'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');

    Route::get('/store/store_translations', [ShopReferenceController::class, 'storeTranslations'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/store_translations/{id}', [ShopReferenceController::class, 'showStoreTranslation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/countries', [ShopReferenceController::class, 'countries'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/countries/{id}/states', [ShopReferenceController::class, 'countryStates'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/countries/{id}', [ShopReferenceController::class, 'showCountry'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    // Shop country and state lists.
    Route::get('/store/core/countries', [ShopReferenceController::class, 'countries'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/core/states', [ShopReferenceController::class, 'states'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    Route::get('/store-settings', [ShopController::class, 'storeConfig'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/store-settings', [ShopController::class, 'storeConfig'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store-setting-fields', [ShopController::class, 'storeSettingFields'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store-setting-fields/{id}', [ShopController::class, 'showStoreSettingField'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/store-setting-fields', [ShopController::class, 'storeSettingFields'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/store-setting-fields/{id}', [ShopController::class, 'showStoreSettingField'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    Route::get('/home-modules', [ShopThemeController::class, 'sections'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/home-modules/{id}', [ShopThemeController::class, 'showSection'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/countries-states', [ShopReferenceController::class, 'countriesStates'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/countries/states/groups', [ShopReferenceController::class, 'countryStateGroups'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/countries-states', [ShopReferenceController::class, 'countriesStates'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/countries/states/groups', [ShopReferenceController::class, 'countryStateGroups'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    Route::post('/customer/login', [ShopCustomerAuthController::class, 'login'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);
    Route::post('/customer/register', [ShopCustomerAuthController::class, 'register'])
        ->middleware([...$storefrontKey, 'throttle:10,1']);
    Route::post('/customer/forgot-password', [ShopForgotPasswordController::class, 'forgot'])
        ->middleware([...$storefrontKey, 'throttle:10,1,customer-forgot']);
    Route::post('/customer/subscription', [NewsletterController::class, 'subscribe'])
        ->middleware([...$storefrontKey, 'throttle:8,1,customer-subscription', 'honeypot']);
    Route::get('/store/locales', [ShopReferenceController::class, 'locales'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/locales/{id}', [ShopReferenceController::class, 'showLocale'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    // Shop REST currencies
    Route::get('/store/currencies', [ShopReferenceController::class, 'currencies'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/currencies/{id}', [ShopReferenceController::class, 'showCurrency'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    // Shop REST EU withdrawal (public status + guest file)
    Route::get('/store/eu-withdrawal-status', [EuWithdrawalController::class, 'status'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/eu-withdrawal-status', [EuWithdrawalController::class, 'status'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::post('/store/eu-withdrawals/guest', [EuWithdrawalController::class, 'guestStore'])
        ->middleware([...$storefrontKey, 'throttle:8,1,shop-eu-guest']);
    Route::post('/eu-withdrawals/guest', [EuWithdrawalController::class, 'guestStore'])
        ->middleware([...$storefrontKey, 'throttle:8,1,shop-eu-guest-alias']);
    // Shop REST theme + sections
    Route::get('/store/theme', [ShopThemeController::class, 'theme'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/theme', [ShopThemeController::class, 'theme'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/sections', [ShopThemeController::class, 'sections'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/sections', [ShopThemeController::class, 'sections'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/sections/{id}', [ShopThemeController::class, 'showSection'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');

    Route::get('/store/home-modules', [ShopThemeController::class, 'sections'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/home-modules/{id}', [ShopThemeController::class, 'showSection'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    // Shop REST attributes
    Route::get('/store/attributes', [ShopAttributesController::class, 'index'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    Route::get('/store/product-templates', [ShopAttributesController::class, 'families'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product-templates/{id}', [ShopAttributesController::class, 'showFamily'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/attribute-options', [ShopAttributesController::class, 'options'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/attribute-options/{id}', [ShopAttributesController::class, 'showOption'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/attributes/{id}', [ShopAttributesController::class, 'show'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');

    Route::get('/store/attribute_translations', [ShopAttributesController::class, 'translations'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/attribute_translations/{id}', [ShopAttributesController::class, 'showTranslation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/attribute-translations', [ShopAttributesController::class, 'translations'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/attribute-translations/{id}', [ShopAttributesController::class, 'showTranslation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    // Shop REST categories + category-trees

    Route::get('/store/cms-pages', [ShopCmsPagesController::class, 'index'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::get('/store/cms-pages/{idOrKey}', [ShopCmsPagesController::class, 'show'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::get('/store/pages', [ShopCmsPagesController::class, 'index'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::get('/store/pages/{idOrKey}', [ShopCmsPagesController::class, 'show'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::get('/store/categories', [ShopCategoriesController::class, 'index'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    Route::get('/store/categories/max-price/{id?}', [ShopCategoriesController::class, 'maxPrice'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');

    Route::get('/store/categories/attributes', [ShopCategoriesController::class, 'filterableAttributes'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/categories/attributes/{attributeId}/options', [ShopCategoriesController::class, 'filterableAttributeOptions'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('attributeId');
    Route::get('/store/categories/{id}', [ShopCategoriesController::class, 'show'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/category-trees', [ShopCategoriesController::class, 'tree'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/category-trees', [ShopCategoriesController::class, 'tree'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    Route::get('/store/descendant-categories', [ShopCategoriesController::class, 'descendants'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    Route::get('/store/category_translations', [ShopCategoriesController::class, 'translations'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/category_translations/{id}', [ShopCategoriesController::class, 'showTranslation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    // Shop REST products
    Route::get('/store/products', [ShopProductsController::class, 'index'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);

    // Store catalog relations + extra-info / variant-config
    Route::get('/store/products/{id}/related', [ShopProductsController::class, 'related'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{id}/up-sell', [ShopProductsController::class, 'upSell'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{id}/up-sells', [ShopProductsController::class, 'upSell'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{id}/extra-info', [ShopProductsController::class, 'additionalInformation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{id}/variant-config', [ShopProductsController::class, 'configurableConfig'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{productId}/images', [ShopProductSubresourcesController::class, 'images'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/products/{productId}/videos', [ShopProductSubresourcesController::class, 'videos'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/products/{productId}/customer-group-prices', [ShopProductSubresourcesController::class, 'customerGroupPrices'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/products/{productId}/custom-options', [ShopProductSubresourcesController::class, 'customizableOptions'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/products/{productId}/attribute-values', [ShopProductSubresourcesController::class, 'attributeValues'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');

    Route::get('/store/products/{productId}/variants', [ShopProductSubresourcesController::class, 'variants'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/products/{productId}/bookings', [ShopProductSubresourcesController::class, 'bookingProducts'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/bookings/{id}', [ShopProductSubresourcesController::class, 'showBookingProduct'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{productId}/bundle-options', [ShopProductSubresourcesController::class, 'bundleOptions'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/product_bundle_options/{id}', [ShopProductSubresourcesController::class, 'showBundleOption'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product-bundle-option-products', [ShopProductSubresourcesController::class, 'bundleOptionProducts'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product-bundle-option-products/{id}', [ShopProductSubresourcesController::class, 'showBundleOptionProduct'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product_bundle_option_translations', [ShopProductSubresourcesController::class, 'bundleOptionTranslations'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product_bundle_option_translations/{id}', [ShopProductSubresourcesController::class, 'showBundleOptionTranslation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{productId}/grouped-products', [ShopProductSubresourcesController::class, 'groupedProducts'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/product_grouped_products/{id}', [ShopProductSubresourcesController::class, 'showGroupedProduct'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{productId}/downloadable-links', [ShopProductSubresourcesController::class, 'downloadableLinks'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/products/{productId}/downloadable-samples', [ShopProductSubresourcesController::class, 'downloadableSamples'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/product-downloadable-links', [ShopProductSubresourcesController::class, 'productDownloadableLinks'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product-downloadable-links/{id}', [ShopProductSubresourcesController::class, 'showDownloadableLink'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product-downloadable-samples', [ShopProductSubresourcesController::class, 'productDownloadableSamples'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product-downloadable-samples/{id}', [ShopProductSubresourcesController::class, 'showDownloadableSample'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product_downloadable_link_translations/{id}', [ShopProductSubresourcesController::class, 'showDownloadableLinkTranslation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product_downloadable_sample_translations/{id}', [ShopProductSubresourcesController::class, 'showDownloadableSampleTranslation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product-images', [ShopProductSubresourcesController::class, 'productImages'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product-images/{id}', [ShopProductSubresourcesController::class, 'showProductImage'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product-videos', [ShopProductSubresourcesController::class, 'productVideos'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product-videos/{id}', [ShopProductSubresourcesController::class, 'showProductVideo'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product_customizable_options/{id}', [ShopProductSubresourcesController::class, 'showCustomizableOption'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product_customizable_option_prices', [ShopProductSubresourcesController::class, 'optionPrices'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product_customizable_option_prices/{id}', [ShopProductSubresourcesController::class, 'showOptionPrice'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/product_customizable_option_translations', [ShopProductSubresourcesController::class, 'optionTranslations'])
        ->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/product_customizable_option_translations/{id}', [ShopProductSubresourcesController::class, 'showOptionTranslation'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    Route::get('/store/products/{id}', [ShopProductsController::class, 'show'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('id');
    // Shop REST cart (paths under /shop/* — Wento /cart stays unchanged)
    Route::post('/store/cart', [ShopCartController::class, 'getCart'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::post('/store/create-cart', [ShopCartController::class, 'createCart'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);

    Route::post('/store/cart-tokens', [ShopCartController::class, 'createCart'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::post('/store/add-product-in-cart', [ShopCartController::class, 'addProduct'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    // merge-carts requires customer auth — registered in auth:customer group below
    Route::put('/store/cart/items/{cartItemId}', [ShopCartController::class, 'updateItem'])
        ->middleware([...$storefrontKey, 'throttle:30,1'])->whereNumber('cartItemId');
    Route::delete('/store/cart/items/{cartItemId}', [ShopCartController::class, 'removeItem'])
        ->middleware([...$storefrontKey, 'throttle:30,1'])->whereNumber('cartItemId');

    Route::post('/store/update-cart-item', [ShopCartController::class, 'updateCartItem'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);

    Route::post('/store/remove-cart-item', [ShopCartController::class, 'removeCartItem'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::post('/store/remove-cart-items', [ShopCartController::class, 'removeCartItems'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);

    Route::post('/store/customizable-option-files', [ShopCustomizableFilesController::class, 'store'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::post('/store/cart/coupon', [ShopCartController::class, 'applyCoupon'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);
    Route::delete('/store/cart/coupon', [ShopCartController::class, 'removeCoupon'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);

    Route::post('/store/apply-coupon', [ShopCartController::class, 'applyCoupon'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);
    Route::delete('/store/remove-coupon', [ShopCartController::class, 'removeCoupon'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);

    Route::post('/store/estimate-shipping-methods', [ShopCartController::class, 'estimateShippingMethods'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::post('/store/checkout/cart/estimate-shipping-methods', [ShopCartController::class, 'estimateShippingMethods'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::get('/store/cart/cross-sell', [ShopCartController::class, 'crossSell'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::get('/store/checkout/cart/cross-sell', [ShopCartController::class, 'crossSell'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);

    Route::delete('/store/checkout/cart/selected', [ShopCartController::class, 'removeCartItems'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::delete('/store/cart/selected', [ShopCartController::class, 'removeCartItems'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    // Shop REST checkout sequence
    Route::get('/store/checkout/onepage/summary', [ShopCheckoutController::class, 'summary'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::get('/store/checkout-addresses', [ShopCheckoutController::class, 'getAddresses'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::post('/store/checkout-addresses', [ShopCheckoutController::class, 'setAddresses'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::get('/store/checkout-shipping-methods', [ShopCheckoutController::class, 'getShippingMethods'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::post('/store/checkout-shipping-methods', [ShopCheckoutController::class, 'setShippingMethod'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::get('/store/payment-methods', [ShopCheckoutController::class, 'getPaymentMethods'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::post('/store/checkout-payment-methods', [ShopCheckoutController::class, 'setPaymentMethod'])
        ->middleware([...$storefrontKey, 'throttle:30,1']);
    Route::post('/store/checkout-orders', [ShopCheckoutController::class, 'placeOrder'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);
    // Shop REST product reviews (public list + create)
    Route::get('/store/products/{productId}/reviews', [ShopReviewsController::class, 'forProduct'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/product/{productId}/reviews', [ShopReviewsController::class, 'forProduct'])
        ->middleware([...$storefrontKey, 'throttle:120,1'])->whereNumber('productId');
    Route::get('/store/products/{productId}/reviews/{reviewId}/translate', [ShopReviewsController::class, 'translate'])
        ->middleware([...$storefrontKey, 'throttle:30,1'])->whereNumber('productId')->whereNumber('reviewId');
    Route::get('/store/product/{productId}/reviews/{reviewId}/translate', [ShopReviewsController::class, 'translate'])
        ->middleware([...$storefrontKey, 'throttle:30,1'])->whereNumber('productId')->whereNumber('reviewId');
    Route::post('/store/products/{id}/reviews', [ShopReviewsController::class, 'storeForProduct'])
        ->middleware([...$storefrontKey, 'throttle:20,1'])->whereNumber('id');
    Route::post('/store/product/{id}/review', [ShopReviewsController::class, 'storeForProduct'])
        ->middleware([...$storefrontKey, 'throttle:20,1'])->whereNumber('id');
    Route::post('/store/reviews', [ShopReviewsController::class, 'store'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);

    Route::post('/store/forgot-passwords', [ShopForgotPasswordController::class, 'forgot'])
        ->middleware([...$storefrontKey, 'throttle:10,1,shop-forgot']);
    Route::post('/store/reset-passwords', [ShopForgotPasswordController::class, 'reset'])
        ->middleware([...$storefrontKey, 'throttle:10,1,shop-reset']);

    Route::post('/store/verify-tokens', [ShopCustomerAuthController::class, 'verifyToken'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::post('/verify-tokens', [ShopCustomerAuthController::class, 'verifyToken'])
        ->middleware([...$storefrontKey, 'throttle:60,1']);
    Route::post('/store/social-logins', [ShopCustomerAuthController::class, 'socialLogin'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);
    Route::post('/social-logins', [ShopCustomerAuthController::class, 'socialLogin'])
        ->middleware([...$storefrontKey, 'throttle:20,1']);
    // Shop REST customer auth (public)
    Route::post('/store/customer/login', [ShopCustomerAuthController::class, 'login'])
        ->middleware([...$storefrontKey, 'throttle:10,1,shop-login']);
    Route::post('/store/customers', [ShopCustomerAuthController::class, 'register'])
        ->middleware([...$storefrontKey, 'throttle:5,1,shop-register', 'honeypot']);
    Route::get('/catalog/home', [CatalogController::class, 'home'])->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/catalog/deal', [CatalogController::class, 'deal'])->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/catalog/open/{path}', [CatalogController::class, 'open'])->middleware([...$storefrontKey, 'throttle:120,1'])->where('path', '.*');
    Route::get('/catalog/categories', [CatalogController::class, 'categories'])->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/catalog/search', [CatalogController::class, 'search'])->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/catalog/category/{slug}', [CatalogController::class, 'category'])->middleware([...$storefrontKey, 'throttle:120,1'])->where('slug', '.*');
    Route::get('/catalog/product/{slug}/reviews', [CatalogController::class, 'productReviews'])->middleware([...$storefrontKey, 'throttle:60,1'])->where('slug', '.*');
    Route::post('/catalog/product/{product}/reviews', [AccountController::class, 'guestReview'])
        ->middleware('throttle:20,1,guest-product-reviews');
    Route::get('/catalog/product/{slug}', [CatalogController::class, 'product'])->middleware([...$storefrontKey, 'throttle:120,1'])->where('slug', '.*');
    // Named prefix: bare throttle:8,1 shares one IP bucket with newsletter/contact for guests.
    Route::post('/catalog/product/{product}/customer-stock-alert', [CatalogController::class, 'subscribeCustomerStockAlert'])
        ->middleware('throttle:20,1,customer-stock-alert');
    Route::get('/catalog/brand/{slug}', [CatalogController::class, 'brand'])->middleware([...$storefrontKey, 'throttle:120,1'])->where('slug', '.*');
    Route::get('/catalog/supplier/{slug}', [CatalogController::class, 'supplier'])->middleware([...$storefrontKey, 'throttle:120,1'])->where('slug', '.*');
    Route::get('/catalog/sellers/featured', [MarketplaceShopController::class, 'featured'])->middleware(['marketplace', ...$storefrontKey, 'throttle:120,1']);
    Route::get('/catalog/flag-reasons', [AdminMarketplaceFlagReasonsRestController::class, 'publicIndex'])
        ->middleware(['marketplace', ...$storefrontKey, 'throttle:120,1']);
