<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\EuWithdrawalController;
use App\Http\Controllers\Api\V1\GdprController;
use App\Http\Controllers\Api\V1\MarketplaceShopController;
use App\Http\Controllers\Api\V1\ShopAccountRestController;
use App\Http\Controllers\Api\V1\ShopCartController;
use App\Http\Controllers\Api\V1\ShopReturnsController;
use App\Http\Controllers\Api\V1\ShopCheckoutController;
use App\Http\Controllers\Api\V1\ShopCustomerOrdersController;
use App\Http\Controllers\Api\V1\ShopCustomerAddressesController;
use App\Http\Controllers\Api\V1\ShopCustomerInvoicesController;
use App\Http\Controllers\Api\V1\ShopCustomerShipmentsController;
use App\Http\Controllers\Api\V1\ShopCustomerTransactionsController;
use App\Http\Controllers\Api\V1\ShopCustomerAuthController;
use App\Http\Controllers\Api\V1\ShopCustomerOrderActionsController;
use App\Http\Controllers\Api\V1\ShopCustomerReviewsController;
use App\Http\Controllers\Api\V1\ShopReviewsController;
use App\Http\Controllers\Api\V1\ShopCustomerDownloadablesController;
use App\Http\Controllers\Api\V1\SellerApplicationController;
use App\Http\Controllers\Api\V1\SellerPortalController;
use App\Http\Controllers\Api\V1\SellerBarcodesRestController;
use App\Http\Controllers\Api\V1\SellerDiscountJoinRestController;
use App\Http\Controllers\Api\V1\SellerCheckoutDealsRestController;
use App\Http\Controllers\Api\V1\SellerWarehousesRestController;
use App\Http\Controllers\Api\V1\SellerBookingsRestController;
use App\Http\Controllers\Api\V1\SellerMagicAiController;
use App\Http\Controllers\Api\V1\SellerPlansRestController;
use App\Http\Controllers\Api\V1\SellerTeamRestController;
    Route::middleware(['auth:customer', 'customer.mfa'])->group(function () {
        Route::post('/auth/mfa/enroll', [AuthController::class, 'enrollMfa']);
        Route::post('/auth/mfa/confirm', [AuthController::class, 'confirmMfa']);
        Route::post('/auth/mfa/disable', [AuthController::class, 'disableMfa']);
        Route::get('/auth/passkey/register-options', [AuthController::class, 'passkeyRegisterOptions']);
        Route::post('/auth/passkey/register', [AuthController::class, 'passkeyRegister']);
        Route::get('/account', [AccountController::class, 'dashboard']);
        Route::patch('/account', [AccountController::class, 'updateProfile']);
        Route::delete('/account', [AccountController::class, 'destroyAccount']);
        Route::get('/account/export', [AccountController::class, 'export']);
        Route::get('/account/gdpr', [GdprController::class, 'index']);
        Route::post('/account/gdpr', [GdprController::class, 'store']);
        Route::post('/account/gdpr/{gdprRequest}/revoke', [GdprController::class, 'revoke']);
        Route::get('/account/gdpr/export.html', [GdprController::class, 'html']);
        Route::get('/account/gdpr/export.pdf', [GdprController::class, 'pdf']);
        Route::put('/account/gdpr/cookies', [GdprController::class, 'saveCookies']);
        Route::get('/account/gift-cards', [AccountController::class, 'giftCards']);
        Route::post('/account/stop-impersonation', [AccountController::class, 'stopImpersonation']);
        Route::get('/account/subscriptions', [AccountController::class, 'subscriptions']);
        Route::get('/account/subscriptions/{id}', [AccountController::class, 'subscription']);
        Route::post('/account/subscriptions/{id}/cancel', [AccountController::class, 'cancelSubscription']);
        Route::post('/account/subscriptions/{id}/cancel-at-period-end', [AccountController::class, 'cancelSubscription']);
        Route::post('/account/subscriptions/{id}/resume-period-end', [AccountController::class, 'resumeCancelAtPeriodEnd']);
        Route::post('/account/subscriptions/{id}/pause', [AccountController::class, 'pauseSubscription']);
        Route::post('/account/subscriptions/{id}/resume', [AccountController::class, 'resumeSubscription']);
        Route::post('/account/subscriptions/{id}/skip', [AccountController::class, 'skipSubscription']);
        Route::post('/account/subscriptions/{id}/reactivate', [AccountController::class, 'reactivateSubscription']);
        Route::post('/account/subscriptions/{id}/change-plan', [AccountController::class, 'changeSubscriptionPlan']);
        Route::get('/account/orders', [AccountController::class, 'orders']);
        Route::get('/account/preorders', [AccountController::class, 'preorders']);
        Route::post('/account/preorders/{id}/pay', [AccountController::class, 'payPreorder'])
            ->middleware('throttle:20,1,account-preorder-pay');
        Route::post('/catalog/product/{product}/preorder-notify', [CatalogController::class, 'subscribePreorderNotify'])
            ->middleware('throttle:20,1');
        Route::delete('/catalog/product/{product}/preorder-notify', [CatalogController::class, 'unsubscribePreorderNotify'])
            ->middleware('throttle:20,1');
        Route::get('/account/orders/{number}', [AccountController::class, 'order']);
        Route::post('/account/orders/{number}/cancel', [AccountController::class, 'cancelOrder'])->middleware('throttle:10,1');
        Route::post('/account/orders/{number}/reorder', [AccountController::class, 'reorder'])->middleware('throttle:10,1');
        Route::get('/account/orders/{number}/invoice', [AccountController::class, 'invoice']);
        Route::post('/account/orders/{number}/returns', [AccountController::class, 'requestReturn']);
        Route::get('/account/withdrawals', [EuWithdrawalController::class, 'accountIndex']);
        Route::post('/account/orders/{number}/withdrawal', [EuWithdrawalController::class, 'createForOrder']);
        // Shop REST EU withdrawals (authenticated)
        Route::get('/store/eu-withdrawals', [EuWithdrawalController::class, 'index']);
        Route::post('/store/eu-withdrawals', [EuWithdrawalController::class, 'store']);
        Route::get('/store/eu-withdrawals/{id}', [EuWithdrawalController::class, 'show'])
            ->whereNumber('id');
        Route::get('/account/addresses', [AccountController::class, 'addresses']);
        Route::post('/account/addresses', [AccountController::class, 'storeAddress']);
        Route::patch('/account/addresses/{address}', [AccountController::class, 'updateAddress']);
        Route::delete('/account/addresses/{address}', [AccountController::class, 'destroyAddress']);
        Route::get('/account/wishlist', [AccountController::class, 'wishlist']);
        Route::post('/account/wishlist', [AccountController::class, 'toggleWishlist']);
        Route::post('/account/wishlist/share', [AccountController::class, 'shareWishlist']);

        Route::post('/store/merge-carts', [ShopCartController::class, 'mergeCarts'])
            ->middleware('throttle:30,1');

        Route::post('/store/move-to-wishlist', [ShopCartController::class, 'moveToWishlist'])
            ->middleware('throttle:30,1');
        Route::post('/store/checkout/cart/move-to-wishlist', [ShopCartController::class, 'moveToWishlist'])
            ->middleware('throttle:30,1');
        // Shop REST RMA / returns
        Route::get('/store/returns', [ShopReturnsController::class, 'index']);
        Route::post('/store/returns', [ShopReturnsController::class, 'store'])
            ->middleware('throttle:20,1');
        Route::get('/store/returns/{id}', [ShopReturnsController::class, 'show'])
            ->whereNumber('id');
        Route::post('/store/returns/{id}/cancel', [ShopReturnsController::class, 'cancel'])
            ->whereNumber('id')->middleware('throttle:20,1');
        Route::post('/store/returns/{id}/reopen', [ShopReturnsController::class, 'reopen'])
            ->whereNumber('id')->middleware('throttle:20,1');
        Route::post('/store/returns/{id}/close', [ShopReturnsController::class, 'close'])
            ->whereNumber('id')->middleware('throttle:20,1');
        Route::get('/store/returnable-orders', [ShopReturnsController::class, 'returnableOrders']);
        Route::get('/store/returnable-items', [ShopReturnsController::class, 'returnableItems']);
        Route::get('/store/return-reasons', [ShopReturnsController::class, 'reasons']);
        Route::get('/store/return-custom-fields', [ShopReturnsController::class, 'customFields']);
        Route::get('/store/return-messages', [ShopReturnsController::class, 'messages']);
        Route::post('/store/return-messages', [ShopReturnsController::class, 'sendMessage'])
            ->middleware('throttle:30,1');
        // Shop REST customer auth (authenticated)
        Route::post('/store/customer/logout', [ShopCustomerAuthController::class, 'logout']);
        Route::get('/store/customer-profile', [ShopCustomerAuthController::class, 'profile']);

        Route::put('/store/customer-profile-updates/{id}', [ShopCustomerAuthController::class, 'updateProfile'])
            ->whereNumber('id');
        Route::put('/customer-profile-updates/{id}', [ShopCustomerAuthController::class, 'updateProfile'])
            ->whereNumber('id');

        Route::post('/store/customer-profile-deletes/{id}', [ShopCustomerAuthController::class, 'deleteProfile'])
            ->whereNumber('id');
        Route::post('/customer-profile-deletes/{id}', [ShopCustomerAuthController::class, 'deleteProfile'])
            ->whereNumber('id');

        Route::get('/store/customer-reviews', [ShopCustomerReviewsController::class, 'index']);
        Route::get('/store/customer-reviews/{id}', [ShopCustomerReviewsController::class, 'show'])
            ->whereNumber('id');

        Route::patch('/store/reviews/{reviewId}', [ShopReviewsController::class, 'update'])
            ->whereNumber('reviewId');
        Route::delete('/store/reviews/{reviewId}', [ShopReviewsController::class, 'destroy'])
            ->whereNumber('reviewId');
        // Shop REST customer-orders
        Route::get('/store/customer-orders', [ShopCustomerOrdersController::class, 'index']);
        Route::get('/store/customer-orders/{id}', [ShopCustomerOrdersController::class, 'show'])
            ->whereNumber('id');
        Route::post('/store/customer-orders/{id}/cancel', [ShopCustomerOrderActionsController::class, 'cancel'])
            ->whereNumber('id')->middleware('throttle:10,1');
        Route::post('/store/customer-orders/{id}/reorder', [ShopCustomerOrderActionsController::class, 'reorder'])
            ->whereNumber('id')->middleware('throttle:10,1');
        // Shop REST customer-addresses
        Route::get('/store/customer-addresses', [ShopCustomerAddressesController::class, 'index']);
        Route::post('/store/customer-addresses', [ShopCustomerAddressesController::class, 'store']);
        Route::put('/store/customer-addresses/{addressId}', [ShopCustomerAddressesController::class, 'update'])
            ->whereNumber('addressId');
        Route::delete('/store/customer-addresses/{addressId}', [ShopCustomerAddressesController::class, 'destroy'])
            ->whereNumber('addressId');
        Route::get('/store/customer-addresses/{addressId}', [ShopCustomerAddressesController::class, 'show'])
            ->whereNumber('addressId');
        Route::patch('/store/customer-addresses/make-default/{addressId}', [ShopCustomerAddressesController::class, 'makeDefault'])
            ->whereNumber('addressId');

        Route::get('/customer/get', [ShopCustomerAuthController::class, 'profile']);
        Route::put('/customer/profile', [ShopCustomerAuthController::class, 'updateProfileSelf']);
        Route::post('/customer/logout', [ShopCustomerAuthController::class, 'logout']);
        Route::get('/customer/addresses', [ShopCustomerAddressesController::class, 'index']);
        Route::get('/customer/addresses/{addressId}', [ShopCustomerAddressesController::class, 'show'])
            ->whereNumber('addressId');
        Route::post('/customer/addresses', [ShopCustomerAddressesController::class, 'store']);
        Route::put('/customer/addresses/{addressId}', [ShopCustomerAddressesController::class, 'update'])
            ->whereNumber('addressId');
        Route::patch('/customer/addresses/make-default/{addressId}', [ShopCustomerAddressesController::class, 'makeDefault'])
            ->whereNumber('addressId');
        Route::delete('/customer/addresses/{addressId}', [ShopCustomerAddressesController::class, 'destroy'])
            ->whereNumber('addressId');
        Route::get('/customer/orders', [ShopCustomerOrdersController::class, 'index']);
        Route::get('/customer/orders/reorder/{id}', [ShopCustomerOrderActionsController::class, 'reorder'])
            ->whereNumber('id')->middleware('throttle:10,1');
        Route::get('/customer/orders/{id}', [ShopCustomerOrdersController::class, 'show'])
            ->whereNumber('id');
        Route::post('/customer/orders/{id}/cancel', [ShopCustomerOrderActionsController::class, 'cancel'])
            ->whereNumber('id')->middleware('throttle:10,1');
        Route::get('/customer/invoices', [ShopCustomerInvoicesController::class, 'index']);
        Route::get('/customer/invoices/{id}', [ShopCustomerInvoicesController::class, 'show'])
            ->whereNumber('id');
        Route::get('/customer/shipments', [ShopCustomerShipmentsController::class, 'index']);
        Route::get('/customer/shipments/{id}', [ShopCustomerShipmentsController::class, 'show'])
            ->whereNumber('id');
        Route::get('/customer/transactions', [ShopCustomerTransactionsController::class, 'index']);
        Route::get('/customer/transactions/{id}', [ShopCustomerTransactionsController::class, 'show'])
            ->whereNumber('id');
        Route::get('/customer/wishlist', [ShopAccountRestController::class, 'wishlists']);
        Route::post('/customer/wishlist/{id}', [ShopAccountRestController::class, 'toggleWishlistByProduct'])
            ->whereNumber('id');
        Route::post('/customer/wishlist/{id}/move-to-cart', [ShopAccountRestController::class, 'moveWishlistProductToCart'])
            ->whereNumber('id');
        Route::delete('/customer/wishlist/all', [ShopAccountRestController::class, 'deleteAllWishlists']);
        Route::get('/customer/cart', [ShopCartController::class, 'getCart']);
        Route::post('/customer/cart/add/{productId}', [ShopCartController::class, 'addProductById'])
            ->whereNumber('productId');
        Route::put('/customer/cart/update', [ShopCartController::class, 'updateCartItem']);
        Route::delete('/customer/cart/remove/{cartItemId}', [ShopCartController::class, 'removeItem'])
            ->whereNumber('cartItemId');
        Route::delete('/customer/cart/remove', [ShopCartController::class, 'removeCartItems']);
        Route::post('/customer/cart/move-to-wishlist/{cartItemId}', [ShopCartController::class, 'moveToWishlistByItemId'])
            ->whereNumber('cartItemId');
        Route::post('/customer/cart/coupon', [ShopCartController::class, 'applyCoupon']);
        Route::delete('/customer/cart/coupon', [ShopCartController::class, 'removeCoupon']);
        Route::post('/customer/checkout/save-address', [ShopCheckoutController::class, 'setAddresses']);
        Route::post('/customer/checkout/save-shipping', [ShopCheckoutController::class, 'setShippingMethod']);
        Route::post('/customer/checkout/save-payment', [ShopCheckoutController::class, 'setPaymentMethod']);
        Route::post('/customer/checkout/check-minimum-order', [ShopCheckoutController::class, 'checkMinimumOrder']);
        Route::post('/customer/checkout/save-order', [ShopCheckoutController::class, 'placeOrder']);
        Route::get('/customer/gdpr', [ShopAccountRestController::class, 'gdprRequests']);
        Route::get('/customer/gdpr/{id}', [ShopAccountRestController::class, 'showGdprRequest'])
            ->whereNumber('id');
        Route::post('/customer/gdpr', [ShopAccountRestController::class, 'createGdprRequest']);
        Route::put('/customer/gdpr/revoke/{id}', [ShopAccountRestController::class, 'revokeGdprRequest'])
            ->whereNumber('id');
        // Shop REST customer-invoices
        Route::get('/store/customer-invoices', [ShopCustomerInvoicesController::class, 'index']);
        Route::get('/store/customer-invoices/{id}', [ShopCustomerInvoicesController::class, 'show'])
            ->whereNumber('id');
        Route::get('/store/customer-invoices/{id}/pdf', [ShopCustomerInvoicesController::class, 'pdf'])
            ->whereNumber('id');
        // Shop REST customer-shipments
        Route::get('/store/customer-shipments', [ShopCustomerShipmentsController::class, 'index']);
        Route::get('/store/customer-shipments/{id}', [ShopCustomerShipmentsController::class, 'show'])
            ->whereNumber('id');
        // Shop REST wishlists / compare-items / gdpr-requests
        Route::get('/store/wishlists', [ShopAccountRestController::class, 'wishlists']);
        Route::post('/store/wishlists', [ShopAccountRestController::class, 'createWishlist']);
        Route::post('/store/wishlists/toggle', [ShopAccountRestController::class, 'toggleWishlist']);
        Route::delete('/store/wishlists', [ShopAccountRestController::class, 'deleteAllWishlists']);
        Route::get('/store/wishlists/{id}', [ShopAccountRestController::class, 'showWishlist'])
            ->whereNumber('id');
        Route::post('/store/wishlists/{id}/move-to-cart', [ShopAccountRestController::class, 'moveWishlistToCart'])
            ->whereNumber('id');
        Route::delete('/store/wishlists/{id}', [ShopAccountRestController::class, 'deleteWishlist'])
            ->whereNumber('id');
        // Wishlist and compare aliases.
        Route::get('/store/wishlist', [ShopAccountRestController::class, 'wishlists']);
        Route::post('/store/wishlist', [ShopAccountRestController::class, 'createWishlist']);
        Route::delete('/store/wishlist/all', [ShopAccountRestController::class, 'deleteAllWishlists']);
        Route::post('/store/wishlist/{id}/move-to-cart', [ShopAccountRestController::class, 'moveWishlistToCart'])
            ->whereNumber('id');
        Route::delete('/store/wishlist/{id}', [ShopAccountRestController::class, 'deleteWishlist'])
            ->whereNumber('id');
        Route::get('/store/compare-items', [ShopAccountRestController::class, 'compareItems']);
        Route::post('/store/compare-items', [ShopAccountRestController::class, 'createCompareItem']);
        Route::delete('/store/compare-items', [ShopAccountRestController::class, 'deleteAllCompareItems']);
        Route::get('/store/compare-items/{id}', [ShopAccountRestController::class, 'showCompareItem'])
            ->whereNumber('id');
        Route::delete('/store/compare-items/{id}', [ShopAccountRestController::class, 'deleteCompareItem'])
            ->whereNumber('id');
        Route::get('/store/compare-items', [ShopAccountRestController::class, 'compareItems']);
        Route::post('/store/compare-items', [ShopAccountRestController::class, 'createCompareItem']);
        Route::delete('/store/compare-items', [ShopAccountRestController::class, 'deleteCompareItemByBody']);
        Route::delete('/store/compare-items/all', [ShopAccountRestController::class, 'deleteAllCompareItems']);
        Route::delete('/store/compare-items/{id}', [ShopAccountRestController::class, 'deleteCompareItem'])
            ->whereNumber('id');
        Route::get('/store/gdpr-requests', [ShopAccountRestController::class, 'gdprRequests']);
        Route::post('/store/gdpr-requests', [ShopAccountRestController::class, 'createGdprRequest']);
        Route::get('/store/gdpr-requests/{id}', [ShopAccountRestController::class, 'showGdprRequest'])
            ->whereNumber('id');
        Route::post('/store/gdpr-requests/{id}/revoke', [ShopAccountRestController::class, 'revokeGdprRequest'])
            ->whereNumber('id');
        Route::delete('/store/gdpr-requests/{id}', [ShopAccountRestController::class, 'deleteGdprRequest'])
            ->whereNumber('id');
        Route::get('/account/downloads', [AccountController::class, 'downloads']);
        Route::get('/account/downloads/{token}/preview', [AccountController::class, 'previewDownloadFile']);
        Route::get('/account/downloads/{token}', [AccountController::class, 'downloadFile']);
        // Shop REST REST downloadable purchases list + download
        Route::get('/store/customer-downloadable-products', [ShopCustomerDownloadablesController::class, 'index']);
        Route::get('/store/customer-downloadable-products/{id}', [ShopCustomerDownloadablesController::class, 'show'])
            ->whereNumber('id');
        Route::get('/store/customer-downloadable-products/{purchaseId}/download', [AccountController::class, 'downloadFileById'])
            ->whereNumber('purchaseId');
        Route::get('/customer-downloadable-products/{purchaseId}/download', [AccountController::class, 'downloadFileById'])
            ->whereNumber('purchaseId');
        Route::get('/account/quotes', [AccountController::class, 'quotes']);
        Route::post('/account/quotes', [AccountController::class, 'storeQuote']);
        Route::post('/account/quotes/{quote}/checkout', [AccountController::class, 'checkoutQuote']);
        Route::get('/account/store-credit', [AccountController::class, 'storeCredit']);
        Route::get('/account/price-alerts', [AccountController::class, 'priceAlerts']);
        Route::post('/account/price-alerts', [AccountController::class, 'togglePriceAlert']);
        Route::get('/account/stock-alerts', [AccountController::class, 'stockAlerts']);
        Route::delete('/account/stock-alerts/{alert}', [AccountController::class, 'destroyStockAlert'])
            ->middleware('throttle:30,1');
        Route::delete('/catalog/product/{product}/customer-stock-alert', [CatalogController::class, 'unsubscribeCustomerStockAlert'])
            ->middleware('throttle:20,1');
        Route::get('/account/reviews', [AccountController::class, 'reviews']);
        Route::get('/account/reviews/eligibility', [AccountController::class, 'reviewEligibility']);
        Route::get('/account/vouchers', [AccountController::class, 'vouchers']);
        Route::post('/account/reviews', [AccountController::class, 'review'])
            // Prefix keeps this bucket separate from other throttle:10,1 routes (cart/account).
            ->middleware('throttle:20,1,product-reviews');
        Route::post('/account/reviews/{review}/vote', [AccountController::class, 'voteReview'])
            ->middleware('throttle:30,1,review-votes');
        Route::post('/account/questions', [AccountController::class, 'askQuestion']);
        Route::post('/account/seller-reviews', [MarketplaceShopController::class, 'review'])->middleware('marketplace');

        Route::middleware('marketplace')->group(function () {
            Route::get('/account/seller', [SellerApplicationController::class, 'show']);
            Route::post('/account/seller/apply', [SellerApplicationController::class, 'apply'])
                ->middleware('throttle:5,1');

            Route::middleware('seller')->prefix('account/seller')->group(function () {
                Route::post('/editor/upload', [SellerPortalController::class, 'uploadEditorImage'])
                    ->middleware('throttle:30,1');
                Route::get('/dashboard', [SellerPortalController::class, 'dashboard'])
                    ->middleware('seller:dashboard');
                Route::get('/options', [SellerPortalController::class, 'options']);
                Route::patch('/profile', [SellerPortalController::class, 'updateProfile']);
                Route::patch('/shop', [SellerPortalController::class, 'updateShop']);
                Route::get('/exports/products', [SellerPortalController::class, 'exportProducts']);
                Route::get('/exports/orders', [SellerPortalController::class, 'exportOrders']);
                Route::get('/messages', [SellerPortalController::class, 'messages']);
                Route::patch('/messages/{message}', [SellerPortalController::class, 'replyMessage']);
                Route::get('/payouts', [SellerPortalController::class, 'payouts']);
                Route::post('/payouts', [SellerPortalController::class, 'storePayout'])
                    ->middleware('throttle:10,1');
                Route::get('/categories', [SellerPortalController::class, 'categories']);
                Route::post('/categories', [SellerPortalController::class, 'storeCategory']);
                Route::post('/categories/reorder', [SellerPortalController::class, 'reorderCategories']);
                Route::post('/categories/bulk-status', [SellerPortalController::class, 'bulkCategoryStatus']);
                Route::post('/categories/bulk-delete', [SellerPortalController::class, 'bulkDestroyCategories']);
                Route::get('/categories/{id}', [SellerPortalController::class, 'showCategory']);
                Route::patch('/categories/{id}', [SellerPortalController::class, 'updateCategory']);
                Route::post('/categories/{id}/toggle', [SellerPortalController::class, 'toggleCategory']);
                Route::delete('/categories/{id}', [SellerPortalController::class, 'destroyCategory']);
                Route::get('/feature-values', [SellerPortalController::class, 'featureValues']);
                Route::post('/feature-values', [SellerPortalController::class, 'storeFeatureValue']);
                Route::post('/feature-values/reorder', [SellerPortalController::class, 'reorderFeatureValues']);
                Route::get('/feature-values/{id}', [SellerPortalController::class, 'showFeatureValue']);
                Route::patch('/feature-values/{id}', [SellerPortalController::class, 'updateFeatureValue']);
                Route::post('/feature-values/{id}/toggle', [SellerPortalController::class, 'toggleFeatureValue']);
                Route::delete('/feature-values/{id}', [SellerPortalController::class, 'destroyFeatureValue']);
                Route::get('/features', [SellerPortalController::class, 'features']);
                Route::post('/features', [SellerPortalController::class, 'storeFeature']);
                Route::post('/features/reorder', [SellerPortalController::class, 'reorderFeatures']);
                Route::post('/features/bulk-status', [SellerPortalController::class, 'bulkFeatureStatus']);
                Route::post('/features/bulk-delete', [SellerPortalController::class, 'bulkDestroyFeatures']);
                Route::get('/features/{id}', [SellerPortalController::class, 'showFeature']);
                Route::patch('/features/{id}', [SellerPortalController::class, 'updateFeature']);
                Route::post('/features/{id}/toggle', [SellerPortalController::class, 'toggleFeature']);
                Route::delete('/features/{id}', [SellerPortalController::class, 'destroyFeature']);
                Route::get('/attribute-values', [SellerPortalController::class, 'attributeValues']);
                Route::post('/attribute-values', [SellerPortalController::class, 'storeAttributeValue']);
                Route::post('/attribute-values/reorder', [SellerPortalController::class, 'reorderAttributeValues']);
                Route::get('/attribute-values/{id}', [SellerPortalController::class, 'showAttributeValue']);
                Route::patch('/attribute-values/{id}', [SellerPortalController::class, 'updateAttributeValue']);
                Route::post('/attribute-values/{id}/toggle', [SellerPortalController::class, 'toggleAttributeValue']);
                Route::delete('/attribute-values/{id}', [SellerPortalController::class, 'destroyAttributeValue']);
                Route::get('/attributes', [SellerPortalController::class, 'attributes']);
                Route::post('/attributes', [SellerPortalController::class, 'storeAttribute']);
                Route::post('/attributes/reorder', [SellerPortalController::class, 'reorderAttributes']);
                Route::post('/attributes/bulk-status', [SellerPortalController::class, 'bulkAttributeStatus']);
                Route::post('/attributes/bulk-delete', [SellerPortalController::class, 'bulkDestroyAttributes']);
                Route::get('/attributes/{id}', [SellerPortalController::class, 'showAttribute']);
                Route::patch('/attributes/{id}', [SellerPortalController::class, 'updateAttribute']);
                Route::post('/attributes/{id}/toggle', [SellerPortalController::class, 'toggleAttribute']);
                Route::delete('/attributes/{id}', [SellerPortalController::class, 'destroyAttribute']);
                Route::get('/brands', [SellerPortalController::class, 'brands']);
                Route::post('/brands', [SellerPortalController::class, 'storeBrand']);
                Route::post('/brands/reorder', [SellerPortalController::class, 'reorderBrands']);
                Route::post('/brands/bulk-status', [SellerPortalController::class, 'bulkBrandStatus']);
                Route::post('/brands/bulk-delete', [SellerPortalController::class, 'bulkDestroyBrands']);
                Route::get('/brands/{id}', [SellerPortalController::class, 'showBrand']);
                Route::patch('/brands/{id}', [SellerPortalController::class, 'updateBrand']);
                Route::post('/brands/{id}/toggle', [SellerPortalController::class, 'toggleBrand']);
                Route::delete('/brands/{id}', [SellerPortalController::class, 'destroyBrand']);
                Route::get('/suppliers', [SellerPortalController::class, 'suppliers']);
                Route::post('/suppliers', [SellerPortalController::class, 'storeSupplier']);
                Route::post('/suppliers/reorder', [SellerPortalController::class, 'reorderSuppliers']);
                Route::post('/suppliers/bulk-status', [SellerPortalController::class, 'bulkSupplierStatus']);
                Route::post('/suppliers/bulk-delete', [SellerPortalController::class, 'bulkDestroySuppliers']);
                Route::get('/suppliers/{id}', [SellerPortalController::class, 'showSupplier']);
                Route::patch('/suppliers/{id}', [SellerPortalController::class, 'updateSupplier']);
                Route::post('/suppliers/{id}/toggle', [SellerPortalController::class, 'toggleSupplier']);
                Route::delete('/suppliers/{id}', [SellerPortalController::class, 'destroySupplier']);
                Route::get('/products', [SellerPortalController::class, 'products']);
                Route::post('/products/import', [SellerPortalController::class, 'importProducts']);
                Route::get('/products/import/sample', [SellerPortalController::class, 'importProductsSample']);
                Route::post('/products/bulk-status', [SellerPortalController::class, 'bulkProductStatus']);
                Route::post('/products/bulk-delete', [SellerPortalController::class, 'bulkDestroyProducts']);
                Route::post('/products', [SellerPortalController::class, 'storeProduct']);
                Route::get('/products/{product}', [SellerPortalController::class, 'showProduct']);
                Route::patch('/products/{product}', [SellerPortalController::class, 'updateProduct']);
                Route::delete('/products/{product}', [SellerPortalController::class, 'destroyProduct']);
                Route::post('/products/{product}/images', [SellerPortalController::class, 'uploadProductImage']);
                Route::patch('/products/{product}/images/order', [SellerPortalController::class, 'reorderProductImages']);
                Route::delete('/products/{product}/images/{image}', [SellerPortalController::class, 'deleteProductImage']);
                Route::get('/inventory', [SellerPortalController::class, 'inventory']);
                Route::post('/inventory/bulk-adjust', [SellerPortalController::class, 'bulkAdjustInventory']);
                Route::get('/products/{product}/inventory', [SellerPortalController::class, 'productInventory']);
                Route::post('/products/{product}/inventory/adjust', [SellerPortalController::class, 'adjustProductInventory']);
                Route::get('/orders', [SellerPortalController::class, 'orders']);
                Route::get('/orders/{order}', [SellerPortalController::class, 'showOrder']);
                Route::patch('/orders/{order}', [SellerPortalController::class, 'updateOrder']);
                Route::post('/orders/{order}/cancel-items', [SellerPortalController::class, 'cancelOrderItems']);
                Route::get('/orders/{order}/invoice', [SellerPortalController::class, 'showOrderInvoice']);
                Route::get('/orders/{order}/invoice/download', [SellerPortalController::class, 'downloadOrderInvoice']);
                Route::get('/orders/{order}/delivery-slip', [SellerPortalController::class, 'showOrderDeliverySlip']);
                Route::get('/orders/{order}/delivery-slip/download', [SellerPortalController::class, 'downloadOrderDeliverySlip']);
                Route::post('/orders/{order}/shipments', [SellerPortalController::class, 'createShipment']);
                Route::get('/fulfilment-carriers', [SellerPortalController::class, 'fulfilmentCarriers']);
                Route::post('/orders/{order}/schedule-pickup', [SellerPortalController::class, 'schedulePickup']);

                Route::get('/warehouses', [SellerWarehousesRestController::class, 'index']);
                Route::post('/warehouses', [SellerWarehousesRestController::class, 'store'])
                    ->middleware('throttle:30,1');
                Route::put('/warehouses/{id}', [SellerWarehousesRestController::class, 'update'])
                    ->whereNumber('id');
                Route::delete('/warehouses/{id}', [SellerWarehousesRestController::class, 'destroy'])
                    ->whereNumber('id');
                Route::post('/orders/{order}/request-payment', [SellerPortalController::class, 'requestPayment']);
                Route::get('/shipments', [SellerPortalController::class, 'shipments']);
                Route::patch('/shipments/{shipment}', [SellerPortalController::class, 'updateShipment']);
                Route::post('/shipments/{shipment}/ready', [SellerPortalController::class, 'readyShipment']);
                Route::post('/shipments/{shipment}/ship', [SellerPortalController::class, 'shipShipment']);
                Route::post('/shipments/{shipment}/deliver', [SellerPortalController::class, 'deliverShipment']);
                Route::get('/returns', [SellerPortalController::class, 'returns']);
                Route::post('/returns/{return}/approve', [SellerPortalController::class, 'approveReturn']);
                Route::post('/returns/{return}/reject', [SellerPortalController::class, 'rejectReturn']);
                Route::post('/returns/{return}/receive', [SellerPortalController::class, 'receiveReturn']);
                Route::post('/returns/{return}/refund', [SellerPortalController::class, 'refundReturn']);
                Route::get('/reviews', [SellerPortalController::class, 'reviews']);
                Route::get('/commission', [SellerPortalController::class, 'commission']);
                Route::get('/earnings', [SellerPortalController::class, 'earnings']);
                Route::get('/ledger', [SellerPortalController::class, 'ledger']);
                Route::get('/transactions', [SellerPortalController::class, 'transactions']);
                Route::get('/orders/{order}/financials', [SellerPortalController::class, 'orderFinancials']);
                Route::get('/revenue', [SellerPortalController::class, 'revenue']);
                Route::get('/shipping', [SellerPortalController::class, 'shipping']);
                Route::post('/shipping', [SellerPortalController::class, 'storeShipping']);
                Route::patch('/shipping/{method}', [SellerPortalController::class, 'updateShipping']);
                Route::delete('/shipping/{method}', [SellerPortalController::class, 'destroyShipping']);
                Route::get('/payments', [SellerPortalController::class, 'payments']);
                Route::post('/payments', [SellerPortalController::class, 'storePayment']);
                Route::patch('/payments/{method}', [SellerPortalController::class, 'updatePayment']);
                Route::delete('/payments/{method}', [SellerPortalController::class, 'destroyPayment']);
                Route::get('/discounts', [SellerPortalController::class, 'discounts']);
                Route::post('/discounts', [SellerPortalController::class, 'storeDiscount']);
                Route::patch('/discounts/{discount}', [SellerPortalController::class, 'updateDiscount']);
                Route::delete('/discounts/{discount}', [SellerPortalController::class, 'destroyDiscount']);

                Route::get('/join-discounts', [SellerDiscountJoinRestController::class, 'index']);
                Route::post('/join-discounts/{id}/enroll', [SellerDiscountJoinRestController::class, 'enroll'])
                    ->whereNumber('id')->middleware('throttle:30,1');
                Route::post('/join-discounts/{id}/withdraw', [SellerDiscountJoinRestController::class, 'withdraw'])
                    ->whereNumber('id')->middleware('throttle:30,1');

                Route::get('/checkout-deals', [SellerCheckoutDealsRestController::class, 'index']);
                Route::post('/checkout-deals', [SellerCheckoutDealsRestController::class, 'store'])
                    ->middleware('throttle:30,1');
                Route::put('/checkout-deals/{id}', [SellerCheckoutDealsRestController::class, 'update'])
                    ->whereNumber('id');
                Route::post('/checkout-deals/{id}/copy', [SellerCheckoutDealsRestController::class, 'copy'])
                    ->whereNumber('id')->middleware('throttle:20,1');
                Route::delete('/checkout-deals/{id}', [SellerCheckoutDealsRestController::class, 'destroy'])
                    ->whereNumber('id');

                Route::get('/roles', [SellerTeamRestController::class, 'rolesIndex']);
                Route::post('/roles', [SellerTeamRestController::class, 'rolesStore']);
                Route::put('/roles/{id}', [SellerTeamRestController::class, 'rolesUpdate'])->whereNumber('id');
                Route::delete('/roles/{id}', [SellerTeamRestController::class, 'rolesDestroy'])->whereNumber('id');
                Route::get('/users', [SellerTeamRestController::class, 'usersIndex']);
                Route::post('/users', [SellerTeamRestController::class, 'usersStore']);
                Route::put('/users/{id}', [SellerTeamRestController::class, 'usersUpdate'])->whereNumber('id');
                Route::delete('/users/{id}', [SellerTeamRestController::class, 'usersDestroy'])->whereNumber('id');

                Route::get('/plans', [SellerPlansRestController::class, 'available']);
                Route::get('/plans/history', [SellerPlansRestController::class, 'history']);
                Route::post('/plans/purchase', [SellerPlansRestController::class, 'purchase'])
                    ->middleware('throttle:20,1');
                Route::get('/plans/paypal/return', [SellerPlansRestController::class, 'paypalConfirm'])
                    ->middleware('throttle:30,1');
                Route::post('/plans/paypal/confirm', [SellerPlansRestController::class, 'paypalConfirm'])
                    ->middleware('throttle:30,1');
                Route::post('/plans/stripe/confirm', [SellerPlansRestController::class, 'stripeConfirm'])
                    ->middleware('throttle:30,1');
                Route::post('/plans/razorpay/confirm', [SellerPlansRestController::class, 'razorpayConfirm'])
                    ->middleware('throttle:30,1');
                Route::post('/plans/payu/confirm', [SellerPlansRestController::class, 'payuConfirm'])
                    ->middleware('throttle:30,1');

                Route::get('/ai/status', [SellerMagicAiController::class, 'status']);
                Route::post('/ai/generate-product-content', [SellerMagicAiController::class, 'generateProductContent'])
                    ->middleware('throttle:10,1');

                Route::get('/bookings', [SellerBookingsRestController::class, 'index']);

                Route::put('/products/{productId}/barcode', [SellerBarcodesRestController::class, 'update'])
                    ->whereNumber('productId');
                Route::post('/products/barcodes/generate', [SellerBarcodesRestController::class, 'generate'])
                    ->middleware('throttle:20,1');
                Route::post('/products/barcodes/print', [SellerBarcodesRestController::class, 'print'])
                    ->middleware('throttle:20,1');

                Route::get('/catalog-products/search', [SellerPortalController::class, 'searchCatalogProducts'])
                    ->middleware('throttle:60,1');
                Route::post('/catalog-offers', [SellerPortalController::class, 'sellAsYours'])
                    ->middleware('throttle:30,1');
                Route::post('/catalog-offers/bulk', [SellerPortalController::class, 'bulkCatalogOffers'])
                    ->middleware('throttle:10,1');
                Route::delete('/catalog-offers/{productId}', [SellerPortalController::class, 'destroyCatalogOffer'])
                    ->whereNumber('productId')
                    ->middleware('throttle:30,1');
            });
        });
    });
