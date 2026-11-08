<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AdminGraphqlController;
use App\Http\Controllers\Api\V1\AdminAttributesExportController;
use App\Http\Controllers\Api\V1\AdminBookingsController;
use App\Http\Controllers\Api\V1\AdminBookingsExportController;
use App\Http\Controllers\Api\V1\AdminCatalogProductsExportController;
use App\Http\Controllers\Api\V1\AdminExchangeRatesExportController;
use App\Http\Controllers\Api\V1\AdminStoresExportController;
use App\Http\Controllers\Api\V1\AdminCmsPagesController;
use App\Http\Controllers\Api\V1\AdminCmsPagesExportController;
use App\Http\Controllers\Api\V1\AdminConfigurationController;
use App\Http\Controllers\Api\V1\AdminSettingsStoresController;
use App\Http\Controllers\Api\V1\AdminSettingsCurrenciesController;
use App\Http\Controllers\Api\V1\AdminSettingsExchangeRatesController;
use App\Http\Controllers\Api\V1\AdminSettingsLocalesController;
use App\Http\Controllers\Api\V1\AdminSettingsWarehousesController;
use App\Http\Controllers\Api\V1\AdminSettingsRolesController;
use App\Http\Controllers\Api\V1\AdminAppearanceSectionsController;
use App\Http\Controllers\Api\V1\AdminAppearanceThemesController;
use App\Http\Controllers\Api\V1\AdminRmaCustomFieldsController;
use App\Http\Controllers\Api\V1\AdminRmaReasonsController;
use App\Http\Controllers\Api\V1\AdminRmaRequestsController;
use App\Http\Controllers\Api\V1\AdminRmaRulesController;
use App\Http\Controllers\Api\V1\AdminRmaStatusesController;
use App\Http\Controllers\Api\V1\AdminSettingsDataTransferImportsController;
use App\Http\Controllers\Api\V1\AdminSettingsThemesController;
use App\Http\Controllers\Api\V1\AdminSettingsUsersController;
use App\Http\Controllers\Api\V1\AdminSettingsTaxCategoriesController;
use App\Http\Controllers\Api\V1\AdminSettingsTaxRatesController;
use App\Http\Controllers\Api\V1\AdminCountriesExportController;
use App\Http\Controllers\Api\V1\AdminCurrenciesExportController;
use App\Http\Controllers\Api\V1\AdminCustomerGroupsExportController;
use App\Http\Controllers\Api\V1\AdminCustomersExportController;
use App\Http\Controllers\Api\V1\AdminDataTransferImportsExportController;
use App\Http\Controllers\Api\V1\AdminOrderComposerProductsController;
use App\Http\Controllers\Api\V1\AdminCustomerDeskController;
use App\Http\Controllers\Api\V1\AdminCustomerNotesController;
use App\Http\Controllers\Api\V1\AdminCustomerReviewsController;
use App\Http\Controllers\Api\V1\AdminCustomersRestController;
use App\Http\Controllers\Api\V1\AdminSalesRestController;
use App\Http\Controllers\Api\V1\AdminCatalogRestController;
use App\Http\Controllers\Api\V1\AdminAuthRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceSellersRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceFinanceRestController;
use App\Http\Controllers\Api\V1\AdminMarketplacePaymentRequestsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceCatalogRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceCommunicationsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceAccountHealthRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceCommissionRulesRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceDiscountJoinsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceCheckoutDealsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceLandingRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceFulfilmentCarriersRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceWarehousesRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceFlagsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceFeaturedSellersRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceFlagReasonsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceProductFlagsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceReturnsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceSettingsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceSubscriptionPlansRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceReviewsRestController;
use App\Http\Controllers\Api\V1\AdminMarketplaceStatsRestController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\AdminOrderComposerDraftRestController;
use App\Http\Controllers\Api\V1\AdminGdprDownloadController;
use App\Http\Controllers\Api\V1\AdminGdprRequestsController;
use App\Http\Controllers\Api\V1\AdminImpersonateController;
use App\Http\Controllers\Api\V1\AdminMarketingCampaignsController;
use App\Http\Controllers\Api\V1\AdminMarketingCartDiscountRuleCouponsController;
use App\Http\Controllers\Api\V1\AdminMarketingCartDiscountRulesController;
use App\Http\Controllers\Api\V1\AdminMarketingCatalogDiscountRulesController;
use App\Http\Controllers\Api\V1\AdminMarketingEventsController;
use App\Http\Controllers\Api\V1\AdminMarketingSubscribersController;
use App\Http\Controllers\Api\V1\AdminMarketingTemplatesController;
use App\Http\Controllers\Api\V1\AdminMarketingSearchSynonymsController;
use App\Http\Controllers\Api\V1\AdminMarketingSearchTermsController;
use App\Http\Controllers\Api\V1\AdminMarketingSitemapsController;
use App\Http\Controllers\Api\V1\AdminMarketingUrlRewritesController;
use App\Http\Controllers\Api\V1\AdminNavigationController;
use App\Http\Controllers\Api\V1\AdminNewsletterSubscribersExportController;
use App\Http\Controllers\Api\V1\AdminPromotionsExportController;
use App\Http\Controllers\Api\V1\AdminLocalesExportController;
use App\Http\Controllers\Api\V1\AdminRolesExportController;
use App\Http\Controllers\Api\V1\AdminStatesExportController;
use App\Http\Controllers\Api\V1\AdminUsersExportController;
use App\Http\Controllers\Api\V1\AdminInvoicesExportController;
use App\Http\Controllers\Api\V1\AdminOrdersExportController;
use App\Http\Controllers\Api\V1\AdminRefundsExportController;
use App\Http\Controllers\Api\V1\AdminApiAuditsController;
use App\Http\Controllers\Api\V1\AdminIntegrationModuleController;
use App\Http\Controllers\Api\V1\AdminIntegrationsController;
use App\Http\Controllers\Api\V1\AdminReportingController;
use App\Http\Controllers\Api\V1\AdminReportingExportController;
use App\Http\Controllers\Api\V1\AdminStorefrontKeysController;
use App\Http\Controllers\Api\V1\AdminShipmentsExportController;
use App\Http\Controllers\Api\V1\AdminTaxRatesExportController;
use App\Http\Controllers\Api\V1\AdminTaxRulesExportController;
use App\Http\Controllers\Api\V1\AdminTransactionsExportController;
use App\Http\Controllers\Api\V1\AdminWarehousesExportController;
    $adminIntegration = ['admin.integration', 'admin.integration.ratelimit', 'admin.api.audit'];

    Route::post('/console/login', [AdminAuthRestController::class, 'login'])
        ->middleware('throttle:10,1');
    Route::post('/console/forgot-password', [AdminAuthRestController::class, 'forgotPassword'])
        ->middleware('throttle:5,1');
    Route::delete('/console/logout', [AdminAuthRestController::class, 'logout'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/get', [AdminAuthRestController::class, 'get'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::put('/console/update', [AdminAuthRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::match(['get', 'post'], '/console/graphql', AdminGraphqlController::class)
        ->middleware([...$adminIntegration, 'throttle:60,1']);

    $orderComposerMw = [...$adminIntegration, 'throttle:60,1'];
    Route::post('/console/customers/{customerId}/order-composer/drafts', [AdminOrderComposerDraftRestController::class, 'createDraft'])
        ->middleware($orderComposerMw)
        ->whereNumber('customerId');
    Route::post('/console/customers/{customerId}/impersonate', AdminImpersonateController::class)
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('customerId');
    Route::get('/console/customers/{customerId}/notes', [AdminCustomerNotesController::class, 'index'])
        ->middleware($orderComposerMw)
        ->whereNumber('customerId');
    Route::post('/console/customers/{customerId}/notes', [AdminCustomerNotesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('customerId');
    Route::get('/console/customers/{customerId}/activity', [AdminCustomerDeskController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('customerId');
    Route::post('/console/customers/{customerId}/gdpr-download-data', AdminGdprDownloadController::class)
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('customerId');
    // Admin REST Dashboard + Navigation + Profile
    Route::get('/console/dashboard/stats', [AdminDashboardController::class, 'stats'])
        ->middleware($orderComposerMw);
    Route::get('/console/menu', [AdminNavigationController::class, 'menu'])
        ->middleware($orderComposerMw);
    Route::get('/console/permissions', [AdminNavigationController::class, 'permissions'])
        ->middleware($orderComposerMw);
    Route::get('/console/profile', [AdminNavigationController::class, 'profile'])
        ->middleware($orderComposerMw);
    // Admin REST Customer Reviews moderation
    Route::get('/console/customers/reviews', [AdminCustomerReviewsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/customers/reviews/{id}', [AdminCustomerReviewsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/customers/reviews/{id}', [AdminCustomerReviewsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/customers/reviews/{id}', [AdminCustomerReviewsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/customers/reviews/bulk-delete', [AdminCustomerReviewsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/customers/reviews/bulk-update-status', [AdminCustomerReviewsController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    // Admin REST customers tree (groups / gdpr / list / nested orders+invoices)
    Route::get('/console/customers/groups', [AdminCustomersRestController::class, 'groups'])
        ->middleware($orderComposerMw);
    Route::post('/console/customers/groups', [AdminCustomersRestController::class, 'storeGroup'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/customers/groups/{id}', [AdminCustomersRestController::class, 'showGroup'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/customers/groups/{id}', [AdminCustomersRestController::class, 'updateGroup'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::delete('/console/customers/groups/{id}', [AdminCustomersRestController::class, 'destroyGroup'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/customers/gdpr', [AdminGdprRequestsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/customers/gdpr/{id}', [AdminGdprRequestsController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/customers/gdpr/{id}', [AdminGdprRequestsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::delete('/console/customers/gdpr/{id}', [AdminGdprRequestsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/customers', [AdminCustomersRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/customers', [AdminCustomersRestController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/customers/bulk-delete', [AdminCustomersRestController::class, 'bulkDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/customers/bulk-update', [AdminCustomersRestController::class, 'bulkUpdate'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/customers/{id}', [AdminCustomersRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/customers/{id}', [AdminCustomersRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/customers/{id}', [AdminCustomersRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/customers/{customerId}/orders', [AdminCustomersRestController::class, 'orders'])
        ->middleware($orderComposerMw)->whereNumber('customerId');
    Route::get('/console/customers/{customerId}/invoices', [AdminCustomersRestController::class, 'invoices'])
        ->middleware($orderComposerMw)->whereNumber('customerId');
    Route::get('/console/customers/{customerId}/addresses', [AdminCustomersRestController::class, 'addressesIndex'])
        ->middleware($orderComposerMw)->whereNumber('customerId');
    Route::post('/console/customers/{customerId}/addresses', [AdminCustomersRestController::class, 'addressesStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('customerId');
    Route::post('/console/customers/{customerId}/addresses/bulk-delete', [AdminCustomersRestController::class, 'addressesBulkDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('customerId');
    Route::get('/console/customers/{customerId}/addresses/{id}', [AdminCustomersRestController::class, 'addressesShow'])
        ->middleware($orderComposerMw)->whereNumber('customerId')->whereNumber('id');
    Route::put('/console/customers/{customerId}/addresses/{id}', [AdminCustomersRestController::class, 'addressesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('customerId')->whereNumber('id');
    Route::delete('/console/customers/{customerId}/addresses/{id}', [AdminCustomersRestController::class, 'addressesDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('customerId')->whereNumber('id');

    Route::get('/console/orders', [AdminSalesRestController::class, 'ordersIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/orders/{id}', [AdminSalesRestController::class, 'ordersShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/orders/{id}/cancel', [AdminSalesRestController::class, 'cancel'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/orders/{id}/comments', [AdminSalesRestController::class, 'comment'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::post('/console/reorders/{id}', [AdminSalesRestController::class, 'reOrder'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/reorders/{id}/save-address', [AdminSalesRestController::class, 'reOrderSaveAddress'])
        ->middleware($orderComposerMw);
    Route::post('/console/reorders/{id}/save-shipping', [AdminSalesRestController::class, 'reOrderSaveShipping'])
        ->middleware($orderComposerMw);
    Route::post('/console/reorders/{id}/save-payment', [AdminSalesRestController::class, 'reOrderSavePayment'])
        ->middleware($orderComposerMw);
    Route::post('/console/reorders/{id}/save-order', [AdminSalesRestController::class, 'reOrderSaveOrder'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/shipments', [AdminSalesRestController::class, 'shipmentsIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/shipments/{id}', [AdminSalesRestController::class, 'shipmentsShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/shipments/{orderId}', [AdminSalesRestController::class, 'storeShipment'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('orderId');
    Route::get('/console/invoices', [AdminSalesRestController::class, 'invoicesIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/invoices/{id}', [AdminSalesRestController::class, 'invoicesShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/invoices/{orderId}', [AdminSalesRestController::class, 'storeInvoice'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('orderId');
    Route::get('/console/refunds', [AdminSalesRestController::class, 'refundsIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/refunds/{id}', [AdminSalesRestController::class, 'refundsShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/refunds/{orderId}', [AdminSalesRestController::class, 'storeRefund'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('orderId');
    Route::get('/console/transactions', [AdminSalesRestController::class, 'transactionsIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/transactions/{id}', [AdminSalesRestController::class, 'transactionsShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/transactions', [AdminSalesRestController::class, 'storeTransaction'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    // Console assortment REST (flat — products/categories/attributes under /console)
    Route::get('/console/products', [AdminCatalogRestController::class, 'productsIndex'])
        ->middleware($orderComposerMw);
    Route::post('/console/products', [AdminCatalogRestController::class, 'productsStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/products/bulk-update', [AdminCatalogRestController::class, 'productsBulkUpdate'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/products/bulk-update-status', [AdminCatalogRestController::class, 'productsBulkUpdate'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/products/bulk-delete', [AdminCatalogRestController::class, 'productsBulkDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/products/{id}', [AdminCatalogRestController::class, 'productsShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}', [AdminCatalogRestController::class, 'productsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/products/{id}', [AdminCatalogRestController::class, 'productsDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/products/{id}/copy', [AdminCatalogRestController::class, 'productsCopy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/products/{id}/inventories', [AdminCatalogRestController::class, 'productsInventoriesIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/products/{id}/inventories', [AdminCatalogRestController::class, 'productsInventories'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/images', [AdminCatalogRestController::class, 'productImagesIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/products/{id}/images', [AdminCatalogRestController::class, 'productImagesStore'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::put('/console/products/{id}/images/{imageId}', [AdminCatalogRestController::class, 'productImagesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id')->whereNumber('imageId');
    Route::delete('/console/products/{id}/images/{imageId}', [AdminCatalogRestController::class, 'productImagesDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id')->whereNumber('imageId');
    Route::post('/console/products/{id}/images/reorder', [AdminCatalogRestController::class, 'productImagesReorder'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/videos', [AdminCatalogRestController::class, 'productVideosIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/products/{id}/videos', [AdminCatalogRestController::class, 'productVideosStore'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::put('/console/products/{id}/videos', [AdminCatalogRestController::class, 'productVideosUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/products/{id}/videos', [AdminCatalogRestController::class, 'productVideosDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/products/{id}/downloadable-links', [AdminCatalogRestController::class, 'downloadableLinksIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/products/{id}/downloadable-links', [AdminCatalogRestController::class, 'downloadableLinksStore'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::put('/console/products/{id}/downloadable-links/{linkId}', [AdminCatalogRestController::class, 'downloadableLinksUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id')->whereNumber('linkId');
    Route::delete('/console/products/{id}/downloadable-links/{linkId}', [AdminCatalogRestController::class, 'downloadableLinksDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id')->whereNumber('linkId');
    Route::get('/console/products/{id}/downloadable-samples', [AdminCatalogRestController::class, 'downloadableSamplesIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/products/{id}/downloadable-samples', [AdminCatalogRestController::class, 'downloadableSamplesStore'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::put('/console/products/{id}/downloadable-samples/{sampleId}', [AdminCatalogRestController::class, 'downloadableSamplesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id')->whereNumber('sampleId');
    Route::delete('/console/products/{id}/downloadable-samples/{sampleId}', [AdminCatalogRestController::class, 'downloadableSamplesDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id')->whereNumber('sampleId');
    Route::get('/console/products/{id}/custom-options', [AdminCatalogRestController::class, 'customizableOptionsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/products/{id}/custom-options', [AdminCatalogRestController::class, 'customizableOptionsStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::put('/console/products/{id}/custom-options/{optionId}', [AdminCatalogRestController::class, 'customizableOptionsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id')->whereNumber('optionId');
    Route::delete('/console/products/{id}/custom-options/{optionId}', [AdminCatalogRestController::class, 'customizableOptionsDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id')->whereNumber('optionId');
    Route::get('/console/products/{id}/related-products', [AdminCatalogRestController::class, 'relatedProductsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/related-products', [AdminCatalogRestController::class, 'relatedProductsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/up-sells', [AdminCatalogRestController::class, 'upSellsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/up-sells', [AdminCatalogRestController::class, 'upSellsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/cross-sells', [AdminCatalogRestController::class, 'crossSellsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/cross-sells', [AdminCatalogRestController::class, 'crossSellsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/bundle-options', [AdminCatalogRestController::class, 'bundleOptionsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/bundle-options', [AdminCatalogRestController::class, 'bundleOptionsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/grouped-products', [AdminCatalogRestController::class, 'groupedProductsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/grouped-products', [AdminCatalogRestController::class, 'groupedProductsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');

    Route::get('/console/products/{id}/links', [AdminCatalogRestController::class, 'groupedProductsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/links', [AdminCatalogRestController::class, 'groupedProductsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/bookings', [AdminCatalogRestController::class, 'bookingProductsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/bookings', [AdminCatalogRestController::class, 'bookingProductsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/variants', [AdminCatalogRestController::class, 'variantsIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/variants', [AdminCatalogRestController::class, 'variantsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/variant-axes', [AdminCatalogRestController::class, 'variantAxesIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/variant-axes', [AdminCatalogRestController::class, 'variantAxesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/categories', [AdminCatalogRestController::class, 'productCategoriesIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/products/{id}/categories', [AdminCatalogRestController::class, 'productCategoriesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/products/{id}/customer-group-prices', [AdminCatalogRestController::class, 'customerGroupPricesIndex'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/products/{id}/customer-group-prices', [AdminCatalogRestController::class, 'customerGroupPricesStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::put('/console/products/{id}/customer-group-prices/{priceId}', [AdminCatalogRestController::class, 'customerGroupPricesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id')->whereNumber('priceId');
    Route::delete('/console/products/{id}/customer-group-prices/{priceId}', [AdminCatalogRestController::class, 'customerGroupPricesDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id')->whereNumber('priceId');
    Route::get('/console/categories', [AdminCatalogRestController::class, 'categoriesIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/categories/tree', [AdminCatalogRestController::class, 'categoriesTree'])
        ->middleware($orderComposerMw);
    Route::post('/console/categories', [AdminCatalogRestController::class, 'categoriesStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/categories/bulk-update', [AdminCatalogRestController::class, 'categoriesBulkUpdate'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/categories/bulk-update-status', [AdminCatalogRestController::class, 'categoriesBulkUpdate'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/categories/bulk-delete', [AdminCatalogRestController::class, 'categoriesBulkDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/categories/{id}', [AdminCatalogRestController::class, 'categoriesShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/categories/{id}', [AdminCatalogRestController::class, 'categoriesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/categories/{id}', [AdminCatalogRestController::class, 'categoriesDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/attributes', [AdminCatalogRestController::class, 'attributesIndex'])
        ->middleware($orderComposerMw);
    Route::post('/console/attributes', [AdminCatalogRestController::class, 'attributesStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/attributes/bulk-delete', [AdminCatalogRestController::class, 'attributesBulkDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/attributes/{id}', [AdminCatalogRestController::class, 'attributesShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/attributes/{id}', [AdminCatalogRestController::class, 'attributesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/attributes/{id}', [AdminCatalogRestController::class, 'attributesDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/attributes/{id}/options', [AdminCatalogRestController::class, 'attributeOptionsStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::put('/console/attributes/{id}/options/{optionId}', [AdminCatalogRestController::class, 'attributeOptionsUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id')->whereNumber('optionId');
    Route::delete('/console/attributes/{id}/options/{optionId}', [AdminCatalogRestController::class, 'attributeOptionsDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id')->whereNumber('optionId');
    Route::get('/console/product-templates', [AdminCatalogRestController::class, 'familiesIndex'])
        ->middleware($orderComposerMw);
    Route::post('/console/product-templates', [AdminCatalogRestController::class, 'familiesStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::get('/console/product-templates/{id}', [AdminCatalogRestController::class, 'familiesShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/product-templates/{id}', [AdminCatalogRestController::class, 'familiesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/product-templates/{id}', [AdminCatalogRestController::class, 'familiesDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/families', [AdminCatalogRestController::class, 'familiesIndex'])
        ->middleware($orderComposerMw);
    Route::post('/console/families', [AdminCatalogRestController::class, 'familiesStore'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::get('/console/families/{id}', [AdminCatalogRestController::class, 'familiesShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/families/{id}', [AdminCatalogRestController::class, 'familiesUpdate'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/families/{id}', [AdminCatalogRestController::class, 'familiesDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/settings/home-modules', [AdminSettingsThemesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/home-modules', [AdminSettingsThemesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::get('/console/settings/home-modules/{id}', [AdminSettingsThemesController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/settings/home-modules/{id}', [AdminSettingsThemesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/settings/home-modules/{id}', [AdminSettingsThemesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/cms', [AdminCmsPagesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/cms', [AdminCmsPagesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/cms/bulk-delete', [AdminCmsPagesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/cms/{id}', [AdminCmsPagesController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/cms/{id}', [AdminCmsPagesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/cms/{id}', [AdminCmsPagesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketing/subscribers', [AdminMarketingSubscribersController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/subscribers/bulk-delete', [AdminMarketingSubscribersController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/subscribers/{id}', [AdminMarketingSubscribersController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/subscribers/{id}', [AdminMarketingSubscribersController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/subscribers/{id}', [AdminMarketingSubscribersController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/templates', [AdminMarketingTemplatesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/templates', [AdminMarketingTemplatesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/marketing/templates/bulk-delete', [AdminMarketingTemplatesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/templates/{id}', [AdminMarketingTemplatesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/templates/{id}', [AdminMarketingTemplatesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/templates/{id}', [AdminMarketingTemplatesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/campaigns', [AdminMarketingCampaignsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/campaigns', [AdminMarketingCampaignsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/marketing/campaigns/bulk-delete', [AdminMarketingCampaignsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/marketing/campaigns/{id}/send', [AdminMarketingCampaignsController::class, 'send'])
        ->middleware([...$adminIntegration, 'throttle:10,1'])
        ->whereNumber('id');
    Route::get('/console/marketing/campaigns/{id}/preview-audience', [AdminMarketingCampaignsController::class, 'previewAudience'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::get('/console/marketing/campaigns/{id}', [AdminMarketingCampaignsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/campaigns/{id}', [AdminMarketingCampaignsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/campaigns/{id}', [AdminMarketingCampaignsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/events', [AdminMarketingEventsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/events', [AdminMarketingEventsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/marketing/events/bulk-delete', [AdminMarketingEventsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/events/{id}', [AdminMarketingEventsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/events/{id}', [AdminMarketingEventsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/events/{id}', [AdminMarketingEventsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/url-rewrites', [AdminMarketingUrlRewritesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/url-rewrites', [AdminMarketingUrlRewritesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/marketing/url-rewrites/bulk-delete', [AdminMarketingUrlRewritesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/url-rewrites/{id}', [AdminMarketingUrlRewritesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/url-rewrites/{id}', [AdminMarketingUrlRewritesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/url-rewrites/{id}', [AdminMarketingUrlRewritesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/search-terms', [AdminMarketingSearchTermsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/search-terms/bulk-delete', [AdminMarketingSearchTermsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/search-terms/{id}', [AdminMarketingSearchTermsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/search-terms/{id}', [AdminMarketingSearchTermsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/search-terms/{id}', [AdminMarketingSearchTermsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/search-synonyms', [AdminMarketingSearchSynonymsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/search-synonyms', [AdminMarketingSearchSynonymsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/marketing/search-synonyms/bulk-delete', [AdminMarketingSearchSynonymsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/search-synonyms/{id}', [AdminMarketingSearchSynonymsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/search-synonyms/{id}', [AdminMarketingSearchSynonymsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/search-synonyms/{id}', [AdminMarketingSearchSynonymsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/sitemaps', [AdminMarketingSitemapsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/sitemaps', [AdminMarketingSitemapsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::get('/console/marketing/sitemaps/{id}', [AdminMarketingSitemapsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/sitemaps/{id}', [AdminMarketingSitemapsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/sitemaps/{id}', [AdminMarketingSitemapsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/marketing/sitemaps/{id}/generate', [AdminMarketingSitemapsController::class, 'generate'])
        ->middleware([...$adminIntegration, 'throttle:10,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/discount-rules/cart', [AdminMarketingCartDiscountRulesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/discount-rules/cart', [AdminMarketingCartDiscountRulesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/marketing/discount-rules/cart/bulk-delete', [AdminMarketingCartDiscountRulesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/discount-rules/cart/{id}', [AdminMarketingCartDiscountRulesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/discount-rules/cart/{id}', [AdminMarketingCartDiscountRulesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/discount-rules/cart/{id}', [AdminMarketingCartDiscountRulesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/marketing/discount-rules/cart/{id}/copy', [AdminMarketingCartDiscountRulesController::class, 'copy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/marketing/discount-rules/cart/{discountRuleId}/coupons', [AdminMarketingCartDiscountRuleCouponsController::class, 'index'])
        ->middleware($orderComposerMw)
        ->whereNumber('discountRuleId');
    Route::post('/console/marketing/discount-rules/cart/{discountRuleId}/coupons', [AdminMarketingCartDiscountRuleCouponsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('discountRuleId');
    Route::post('/console/marketing/discount-rules/cart/{discountRuleId}/coupons/generate', [AdminMarketingCartDiscountRuleCouponsController::class, 'generate'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('discountRuleId');
    Route::post('/console/marketing/discount-rules/cart/{discountRuleId}/coupons/bulk-delete', [AdminMarketingCartDiscountRuleCouponsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('discountRuleId');
    Route::get('/console/marketing/discount-rules/cart/{discountRuleId}/coupons/{id}', [AdminMarketingCartDiscountRuleCouponsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('discountRuleId')
        ->whereNumber('id');
    Route::delete('/console/marketing/discount-rules/cart/{discountRuleId}/coupons/{id}', [AdminMarketingCartDiscountRuleCouponsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('discountRuleId')
        ->whereNumber('id');

    Route::get('/console/marketing/discount-rules/catalog', [AdminMarketingCatalogDiscountRulesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/discount-rules/catalog', [AdminMarketingCatalogDiscountRulesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/marketing/discount-rules/catalog/bulk-delete', [AdminMarketingCatalogDiscountRulesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/discount-rules/catalog/{id}', [AdminMarketingCatalogDiscountRulesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/marketing/discount-rules/catalog/{id}', [AdminMarketingCatalogDiscountRulesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/marketing/discount-rules/catalog/{id}', [AdminMarketingCatalogDiscountRulesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    // Marketing communications + SEO (canonical under /console/marketing/*)
    Route::get('/console/marketing/communications/subscribers', [AdminMarketingSubscribersController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketing/communications/subscribers/{id}', [AdminMarketingSubscribersController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketing/communications/subscribers/{id}', [AdminMarketingSubscribersController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketing/communications/subscribers/{id}', [AdminMarketingSubscribersController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/marketing/seo/search-synonyms', [AdminMarketingSearchSynonymsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/seo/search-synonyms', [AdminMarketingSearchSynonymsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::get('/console/marketing/seo/search-synonyms/{id}', [AdminMarketingSearchSynonymsController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketing/seo/search-synonyms/{id}', [AdminMarketingSearchSynonymsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketing/seo/search-synonyms/{id}', [AdminMarketingSearchSynonymsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketing/seo/search-synonyms/bulk-delete', [AdminMarketingSearchSynonymsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/seo/sitemaps', [AdminMarketingSitemapsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/seo/sitemaps', [AdminMarketingSitemapsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::get('/console/marketing/seo/sitemaps/{id}', [AdminMarketingSitemapsController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketing/seo/sitemaps/{id}', [AdminMarketingSitemapsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketing/seo/sitemaps/{id}', [AdminMarketingSitemapsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketing/seo/sitemaps/bulk-delete', [AdminMarketingSitemapsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/seo/url-rewrites', [AdminMarketingUrlRewritesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketing/seo/url-rewrites', [AdminMarketingUrlRewritesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::get('/console/marketing/seo/url-rewrites/{id}', [AdminMarketingUrlRewritesController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketing/seo/url-rewrites/{id}', [AdminMarketingUrlRewritesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketing/seo/url-rewrites/{id}', [AdminMarketingUrlRewritesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketing/seo/url-rewrites/bulk-delete', [AdminMarketingUrlRewritesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/seo/search-terms', [AdminMarketingSearchTermsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketing/seo/search-terms/{id}', [AdminMarketingSearchTermsController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketing/seo/search-terms/{id}', [AdminMarketingSearchTermsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketing/seo/search-terms/{id}', [AdminMarketingSearchTermsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketing/seo/search-terms/bulk-delete', [AdminMarketingSearchTermsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    // Admin REST GDPR Requests queue
    Route::get('/console/customers/gdpr-requests', [AdminGdprRequestsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/customers/gdpr-requests/{id}', [AdminGdprRequestsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/customers/gdpr-requests/{id}', [AdminGdprRequestsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::post('/console/customers/gdpr-requests/{id}/process', [AdminGdprRequestsController::class, 'process'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::delete('/console/customers/gdpr-requests/{id}', [AdminGdprRequestsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::get('/console/customers/{customerId}/cart-items', [AdminOrderComposerDraftRestController::class, 'customerCartItems'])
        ->middleware($orderComposerMw)
        ->whereNumber('customerId');
    Route::get('/console/customers/{customerId}/wishlist-items', [AdminOrderComposerDraftRestController::class, 'customerWishlistItems'])
        ->middleware($orderComposerMw)
        ->whereNumber('customerId');
    Route::get('/console/customers/{customerId}/recent-order-items', [AdminOrderComposerDraftRestController::class, 'customerRecentOrderItems'])
        ->middleware($orderComposerMw)
        ->whereNumber('customerId');
    Route::get('/console/customers/{customerId}/compare-items', [AdminOrderComposerDraftRestController::class, 'customerCompareItems'])
        ->middleware($orderComposerMw)
        ->whereNumber('customerId');
    Route::get('/console/carts/{cartId}', [AdminOrderComposerDraftRestController::class, 'show'])
        ->middleware($orderComposerMw);
    Route::post('/console/carts/{cartId}/items', [AdminOrderComposerDraftRestController::class, 'storeItems'])
        ->middleware($orderComposerMw);
    Route::put('/console/carts/{cartId}/items', [AdminOrderComposerDraftRestController::class, 'updateItems'])
        ->middleware($orderComposerMw);
    Route::delete('/console/carts/{cartId}/items', [AdminOrderComposerDraftRestController::class, 'destroyItems'])
        ->middleware($orderComposerMw);
    Route::post('/console/carts/{cartId}/addresses', [AdminOrderComposerDraftRestController::class, 'saveAddresses'])
        ->middleware($orderComposerMw);
    Route::get('/console/carts/{cartId}/shipping-methods', [AdminOrderComposerDraftRestController::class, 'listShippingMethods'])
        ->middleware($orderComposerMw);
    Route::post('/console/carts/{cartId}/shipping-methods', [AdminOrderComposerDraftRestController::class, 'setShippingMethod'])
        ->middleware($orderComposerMw);
    Route::get('/console/carts/{cartId}/payment-methods', [AdminOrderComposerDraftRestController::class, 'listPaymentMethods'])
        ->middleware($orderComposerMw);
    Route::post('/console/carts/{cartId}/payment-methods', [AdminOrderComposerDraftRestController::class, 'setPaymentMethod'])
        ->middleware($orderComposerMw);
    Route::post('/console/carts/{cartId}/coupon', [AdminOrderComposerDraftRestController::class, 'applyCoupon'])
        ->middleware($orderComposerMw);
    Route::delete('/console/carts/{cartId}/coupon', [AdminOrderComposerDraftRestController::class, 'removeCoupon'])
        ->middleware($orderComposerMw);
    Route::post('/console/orders/place/{cartId}', [AdminOrderComposerDraftRestController::class, 'place'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/orders/{orderId}/reorder', [AdminOrderComposerDraftRestController::class, 'reorder'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);

    Route::get('/console/order-composer/products', AdminOrderComposerProductsController::class)
        ->middleware($orderComposerMw);
    // Admin REST Reporting REST (+ CSV export binary).
    Route::get('/console/reporting/stats', [AdminReportingController::class, 'overview'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/sales', [AdminReportingController::class, 'sales'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/customers', [AdminReportingController::class, 'customers'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/products', [AdminReportingController::class, 'products'])
        ->middleware($orderComposerMw);

    Route::get('/console/metrics/sales/stats', [AdminReportingController::class, 'sales'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/customers/stats', [AdminReportingController::class, 'customers'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/products/stats', [AdminReportingController::class, 'products'])
        ->middleware($orderComposerMw);
    Route::get('/metrics/sales/stats', [AdminReportingController::class, 'sales'])
        ->middleware($orderComposerMw);
    Route::get('/metrics/customers/stats', [AdminReportingController::class, 'customers'])
        ->middleware($orderComposerMw);
    Route::get('/metrics/products/stats', [AdminReportingController::class, 'products'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/sales/view', [AdminReportingController::class, 'viewSales'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/customers/view', [AdminReportingController::class, 'viewCustomers'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/products/view', [AdminReportingController::class, 'viewProducts'])
        ->middleware($orderComposerMw);
    Route::get('/console/metrics/sales/export', [AdminReportingController::class, 'exportSales'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/metrics/customers/export', [AdminReportingController::class, 'exportCustomers'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/metrics/products/export', [AdminReportingController::class, 'exportProducts'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/reporting/export', AdminReportingExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/invoices/export', AdminInvoicesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);

    Route::get('/console/cms/pages', [AdminCmsPagesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/cms/pages', [AdminCmsPagesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/cms/pages/bulk-delete', [AdminCmsPagesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/cms/pages/{id}', [AdminCmsPagesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/cms/pages/{id}', [AdminCmsPagesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/cms/pages/{id}', [AdminCmsPagesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::get('/console/cms/pages/export', AdminCmsPagesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    // Admin REST Configuration (slug-keyed settings)
    Route::get('/console/configuration/menu', [AdminConfigurationController::class, 'menu'])
        ->middleware($orderComposerMw);
    Route::get('/console/configuration/slugs', [AdminConfigurationController::class, 'slugs'])
        ->middleware($orderComposerMw);
    Route::get('/console/configuration', [AdminConfigurationController::class, 'values'])
        ->middleware($orderComposerMw);
    Route::post('/console/configuration', [AdminConfigurationController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);

    Route::get('/console/settings/stores', [AdminSettingsStoresController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/stores', [AdminSettingsStoresController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/stores/{id}', [AdminSettingsStoresController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/stores/{id}', [AdminSettingsStoresController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/stores/{id}', [AdminSettingsStoresController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/settings/locales', [AdminSettingsLocalesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/locales', [AdminSettingsLocalesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/settings/locales/bulk-delete', [AdminSettingsLocalesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/locales/{id}', [AdminSettingsLocalesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/locales/{id}', [AdminSettingsLocalesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/locales/{id}', [AdminSettingsLocalesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/settings/currencies', [AdminSettingsCurrenciesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/currencies', [AdminSettingsCurrenciesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/settings/currencies/bulk-delete', [AdminSettingsCurrenciesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);

    Route::get('/console/settings/currencies/{id}', [AdminSettingsCurrenciesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/currencies/{id}', [AdminSettingsCurrenciesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/currencies/{id}', [AdminSettingsCurrenciesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/settings/exchange-rates', [AdminSettingsExchangeRatesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/exchange-rates', [AdminSettingsExchangeRatesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/settings/exchange-rates/update-rates', [AdminSettingsExchangeRatesController::class, 'updateRates'])
        ->middleware([...$adminIntegration, 'throttle:10,1']);
    Route::post('/console/settings/exchange-rates/bulk-delete', [AdminSettingsExchangeRatesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/exchange-rates/{id}', [AdminSettingsExchangeRatesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/exchange-rates/{id}', [AdminSettingsExchangeRatesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/exchange-rates/{id}', [AdminSettingsExchangeRatesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/settings/tax-rates', [AdminSettingsTaxRatesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/tax-rates', [AdminSettingsTaxRatesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/settings/tax-rates/bulk-delete', [AdminSettingsTaxRatesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/tax-rates/{id}', [AdminSettingsTaxRatesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/tax-rates/{id}', [AdminSettingsTaxRatesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/tax-rates/{id}', [AdminSettingsTaxRatesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/settings/tax-categories', [AdminSettingsTaxCategoriesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/tax-categories', [AdminSettingsTaxCategoriesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:60,1']);
    Route::post('/console/settings/tax-categories/bulk-delete', [AdminSettingsTaxCategoriesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/tax-categories/{id}', [AdminSettingsTaxCategoriesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/tax-categories/{id}', [AdminSettingsTaxCategoriesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/tax-categories/{id}', [AdminSettingsTaxCategoriesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');

    Route::get('/console/settings/warehouses', [AdminSettingsWarehousesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/warehouses', [AdminSettingsWarehousesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/settings/warehouses/bulk-delete', [AdminSettingsWarehousesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/settings/warehouses/{id}', [AdminSettingsWarehousesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/warehouses/{id}', [AdminSettingsWarehousesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/warehouses/{id}', [AdminSettingsWarehousesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/settings/roles', [AdminSettingsRolesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/roles', [AdminSettingsRolesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/settings/roles/bulk-delete', [AdminSettingsRolesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/settings/roles/{id}', [AdminSettingsRolesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/roles/{id}', [AdminSettingsRolesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/roles/{id}', [AdminSettingsRolesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/configuration/api/integration', [AdminIntegrationModuleController::class, 'show'])
        ->middleware($orderComposerMw);
    Route::put('/console/configuration/api/integration', [AdminIntegrationModuleController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/integrations/module', [AdminIntegrationModuleController::class, 'show'])
        ->middleware($orderComposerMw);
    Route::put('/console/settings/integrations/module', [AdminIntegrationModuleController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);

    Route::get('/console/settings/integrations', [AdminIntegrationsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/integrations', [AdminIntegrationsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/integrations/{id}', [AdminIntegrationsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/integrations/{id}/allowlist', [AdminIntegrationsController::class, 'updateAllowlist'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::put('/console/settings/integrations/{id}/rate-limits', [AdminIntegrationsController::class, 'updateRateLimits'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/settings/integrations/{id}/regenerate', [AdminIntegrationsController::class, 'regenerate'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/integrations/{id}', [AdminIntegrationsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::get('/console/integrations', [AdminIntegrationsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/integrations', [AdminIntegrationsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/integrations/{id}', [AdminIntegrationsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/integrations/{id}/allowlist', [AdminIntegrationsController::class, 'updateAllowlist'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::put('/console/integrations/{id}/rate-limits', [AdminIntegrationsController::class, 'updateRateLimits'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/integrations/{id}/regenerate', [AdminIntegrationsController::class, 'regenerate'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::delete('/console/integrations/{id}', [AdminIntegrationsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/settings/integrations/history', [AdminApiAuditsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/integrations/history/bulk-delete', [AdminApiAuditsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/settings/integrations/history/prune', [AdminApiAuditsController::class, 'prune'])
        ->middleware([...$adminIntegration, 'throttle:10,1']);
    Route::get('/console/settings/integrations/history/{id}', [AdminApiAuditsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::get('/console/integrations/history', [AdminApiAuditsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/integrations/history/bulk-delete', [AdminApiAuditsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/integrations/history/prune', [AdminApiAuditsController::class, 'prune'])
        ->middleware([...$adminIntegration, 'throttle:10,1']);
    Route::get('/console/integrations/history/{id}', [AdminApiAuditsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::get('/console/api-audits', [AdminApiAuditsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/api-audits/bulk-delete', [AdminApiAuditsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/api-audits/prune', [AdminApiAuditsController::class, 'prune'])
        ->middleware([...$adminIntegration, 'throttle:10,1']);
    Route::get('/console/api-audits/{id}', [AdminApiAuditsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');

    Route::get('/console/settings/storefront-keys', [AdminStorefrontKeysController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/storefront-keys', [AdminStorefrontKeysController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/storefront-keys/requirement', [AdminStorefrontKeysController::class, 'requirement'])
        ->middleware($orderComposerMw);
    Route::put('/console/settings/storefront-keys/requirement', [AdminStorefrontKeysController::class, 'requirement'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/settings/storefront-keys/{id}', [AdminStorefrontKeysController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/storefront-keys/{id}/rate-limit', [AdminStorefrontKeysController::class, 'updateRateLimit'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/settings/storefront-keys/{id}/regenerate', [AdminStorefrontKeysController::class, 'regenerate'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/storefront-keys/{id}', [AdminStorefrontKeysController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::get('/console/storefront-keys', [AdminStorefrontKeysController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/storefront-keys', [AdminStorefrontKeysController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/storefront-keys/requirement', [AdminStorefrontKeysController::class, 'requirement'])
        ->middleware($orderComposerMw);
    Route::put('/console/storefront-keys/requirement', [AdminStorefrontKeysController::class, 'requirement'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/storefront-keys/{id}', [AdminStorefrontKeysController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/storefront-keys/{id}/rate-limit', [AdminStorefrontKeysController::class, 'updateRateLimit'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/storefront-keys/{id}/regenerate', [AdminStorefrontKeysController::class, 'regenerate'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::delete('/console/storefront-keys/{id}', [AdminStorefrontKeysController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/settings/users', [AdminSettingsUsersController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/users', [AdminSettingsUsersController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/settings/users/bulk-delete', [AdminSettingsUsersController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/settings/users/{id}', [AdminSettingsUsersController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/users/{id}', [AdminSettingsUsersController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/users/{id}', [AdminSettingsUsersController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/settings/themes', [AdminSettingsThemesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/themes', [AdminSettingsThemesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/settings/themes/bulk-delete', [AdminSettingsThemesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/settings/themes/{id}', [AdminSettingsThemesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/themes/{id}', [AdminSettingsThemesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/themes/{id}', [AdminSettingsThemesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/appearance/themes', [AdminAppearanceThemesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/appearance/themes/{code}/impact', [AdminAppearanceThemesController::class, 'impact'])
        ->middleware($orderComposerMw)
        ->where('code', '[A-Za-z0-9._-]+');
    Route::post('/console/appearance/themes/{code}/activate', [AdminAppearanceThemesController::class, 'activate'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->where('code', '[A-Za-z0-9._-]+');
    Route::get('/console/appearance/themes/{code}', [AdminAppearanceThemesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->where('code', '[A-Za-z0-9._-]+');

    Route::get('/console/appearance/sections', [AdminAppearanceSectionsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/appearance/sections/preview', [AdminAppearanceSectionsController::class, 'preview'])
        ->middleware($orderComposerMw);
    Route::post('/console/appearance/sections', [AdminAppearanceSectionsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/appearance/sections/reorder', [AdminAppearanceSectionsController::class, 'reorder'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/appearance/sections/publish', [AdminAppearanceSectionsController::class, 'publish'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/appearance/sections/discard', [AdminAppearanceSectionsController::class, 'discard'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/appearance/sections/{id}/fields', [AdminAppearanceSectionsController::class, 'fields'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::post('/console/appearance/sections/{id}/status', [AdminAppearanceSectionsController::class, 'status'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/appearance/sections/{id}/duplicate', [AdminAppearanceSectionsController::class, 'duplicate'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::get('/console/appearance/sections/{id}', [AdminAppearanceSectionsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/appearance/sections/{id}', [AdminAppearanceSectionsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/appearance/sections/{id}', [AdminAppearanceSectionsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/settings/data-transfer/imports', [AdminSettingsDataTransferImportsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/data-transfer/imports', [AdminSettingsDataTransferImportsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/settings/data-transfer/imports/bulk-delete', [AdminSettingsDataTransferImportsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);

    Route::get('/console/settings/data-transfer/imports/sample/{type}/{format}', [AdminSettingsDataTransferImportsController::class, 'downloadSample'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->where('type', '[A-Za-z_]+')
        ->where('format', '[A-Za-z0-9]+');
    Route::get('/console/settings/data-transfer/imports/{id}', [AdminSettingsDataTransferImportsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::post('/console/settings/data-transfer/imports/{id}/validate', [AdminSettingsDataTransferImportsController::class, 'validateImport'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::post('/console/settings/data-transfer/imports/{id}/start', [AdminSettingsDataTransferImportsController::class, 'start'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::post('/console/settings/data-transfer/imports/{id}/link', [AdminSettingsDataTransferImportsController::class, 'link'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::post('/console/settings/data-transfer/imports/{id}/index', [AdminSettingsDataTransferImportsController::class, 'indexImport'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::post('/console/settings/data-transfer/imports/{id}/cancel', [AdminSettingsDataTransferImportsController::class, 'cancel'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/data-transfer/imports/{id}', [AdminSettingsDataTransferImportsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/rma/statuses', [AdminRmaStatusesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/rma/statuses', [AdminRmaStatusesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/rma/statuses/bulk-delete', [AdminRmaStatusesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/rma/statuses/bulk-update-status', [AdminRmaStatusesController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/rma/statuses/{id}', [AdminRmaStatusesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/rma/statuses/{id}', [AdminRmaStatusesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/rma/statuses/{id}', [AdminRmaStatusesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::get('/console/settings/return-statuses', [AdminRmaStatusesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/return-statuses', [AdminRmaStatusesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/settings/return-statuses/bulk-delete', [AdminRmaStatusesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/settings/return-statuses/bulk-update-status', [AdminRmaStatusesController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/settings/return-statuses/{id}', [AdminRmaStatusesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/return-statuses/{id}', [AdminRmaStatusesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/return-statuses/{id}', [AdminRmaStatusesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/rma/rules', [AdminRmaRulesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/rma/rules', [AdminRmaRulesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/rma/rules/bulk-delete', [AdminRmaRulesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/rma/rules/bulk-update-status', [AdminRmaRulesController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/rma/rules/{id}', [AdminRmaRulesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/rma/rules/{id}', [AdminRmaRulesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/rma/rules/{id}', [AdminRmaRulesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::get('/console/settings/return-rules', [AdminRmaRulesController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/return-rules', [AdminRmaRulesController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/settings/return-rules/bulk-delete', [AdminRmaRulesController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/settings/return-rules/bulk-update-status', [AdminRmaRulesController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/settings/return-rules/{id}', [AdminRmaRulesController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/return-rules/{id}', [AdminRmaRulesController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/return-rules/{id}', [AdminRmaRulesController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/rma/custom-fields', [AdminRmaCustomFieldsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/rma/custom-fields', [AdminRmaCustomFieldsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/rma/custom-fields/bulk-delete', [AdminRmaCustomFieldsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/rma/custom-fields/bulk-update-status', [AdminRmaCustomFieldsController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/rma/custom-fields/{id}', [AdminRmaCustomFieldsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/rma/custom-fields/{id}', [AdminRmaCustomFieldsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/rma/custom-fields/{id}', [AdminRmaCustomFieldsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::get('/console/settings/return-custom-fields', [AdminRmaCustomFieldsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/return-custom-fields', [AdminRmaCustomFieldsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/settings/return-custom-fields/bulk-delete', [AdminRmaCustomFieldsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/settings/return-custom-fields/bulk-update-status', [AdminRmaCustomFieldsController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/settings/return-custom-fields/{id}', [AdminRmaCustomFieldsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/return-custom-fields/{id}', [AdminRmaCustomFieldsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/return-custom-fields/{id}', [AdminRmaCustomFieldsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/rma/reasons', [AdminRmaReasonsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/rma/reasons', [AdminRmaReasonsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/rma/reasons/bulk-delete', [AdminRmaReasonsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/rma/reasons/bulk-update-status', [AdminRmaReasonsController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/rma/reasons/{id}', [AdminRmaReasonsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/rma/reasons/{id}', [AdminRmaReasonsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/rma/reasons/{id}', [AdminRmaReasonsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::get('/console/settings/return-reasons', [AdminRmaReasonsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/settings/return-reasons', [AdminRmaReasonsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/settings/return-reasons/bulk-delete', [AdminRmaReasonsController::class, 'bulkDelete'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::post('/console/settings/return-reasons/bulk-update-status', [AdminRmaReasonsController::class, 'bulkUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/settings/return-reasons/{id}', [AdminRmaReasonsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::put('/console/settings/return-reasons/{id}', [AdminRmaReasonsController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::delete('/console/settings/return-reasons/{id}', [AdminRmaReasonsController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');

    Route::get('/console/rma/requests', [AdminRmaRequestsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/rma/requests', [AdminRmaRequestsController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/rma/returnable-items/{orderId}', [AdminRmaRequestsController::class, 'returnableItems'])
        ->middleware($orderComposerMw)
        ->whereNumber('orderId');
    Route::get('/console/rma/request-reasons', [AdminRmaRequestsController::class, 'reasons'])
        ->middleware($orderComposerMw);
    Route::get('/console/rma/requests/{id}', [AdminRmaRequestsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::post('/console/rma/requests/{id}/status', [AdminRmaRequestsController::class, 'updateStatus'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::post('/console/rma/requests/{id}/reopen', [AdminRmaRequestsController::class, 'reopen'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])
        ->whereNumber('id');
    Route::get('/console/rma/requests/{id}/messages', [AdminRmaRequestsController::class, 'messages'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::post('/console/rma/requests/{id}/messages', [AdminRmaRequestsController::class, 'sendMessage'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])
        ->whereNumber('id');
    Route::get('/console/shipments/export', AdminShipmentsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/refunds/export', AdminRefundsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/products/export', AdminCatalogProductsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/orders/export', AdminOrdersExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/customers/export', AdminCustomersExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);

    Route::get('/console/marketplace/sellers', [AdminMarketplaceSellersRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/sellers/{id}', [AdminMarketplaceSellersRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketplace/sellers/{id}', [AdminMarketplaceSellersRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::post('/console/marketplace/sellers/{id}/review', [AdminMarketplaceSellersRestController::class, 'review'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketplace/sellers/{id}/login-as', [AdminMarketplaceSellersRestController::class, 'loginAs'])
        ->middleware([...$adminIntegration, 'throttle:20,1'])->whereNumber('id');
    Route::post('/console/marketplace/sellers/{id}/assign-product', [AdminMarketplaceSellersRestController::class, 'assignProduct'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketplace/payouts', [AdminMarketplaceFinanceRestController::class, 'payoutsIndex'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/payouts', [AdminMarketplaceFinanceRestController::class, 'payoutsStore'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketplace/payouts/{id}', [AdminMarketplaceFinanceRestController::class, 'payoutsShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/marketplace/payouts/{id}/process', [AdminMarketplaceFinanceRestController::class, 'payoutsProcess'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/marketplace/earnings', [AdminMarketplaceFinanceRestController::class, 'earningsIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/earnings/{id}', [AdminMarketplaceFinanceRestController::class, 'earningsShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/marketplace/earnings/{id}/status', [AdminMarketplaceFinanceRestController::class, 'earningsUpdateStatus'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::get('/console/marketplace/payment-requests', [AdminMarketplacePaymentRequestsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/payment-requests/{id}/pay', [AdminMarketplacePaymentRequestsRestController::class, 'pay'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/marketplace/transactions', [AdminMarketplacePaymentRequestsRestController::class, 'transactions'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/products', [AdminMarketplaceCatalogRestController::class, 'productsIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/products/{id}', [AdminMarketplaceCatalogRestController::class, 'productsShow'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/marketplace/products/{id}/review', [AdminMarketplaceCatalogRestController::class, 'productsReview'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/marketplace/orders', [AdminMarketplaceCatalogRestController::class, 'ordersIndex'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/orders/{id}', [AdminMarketplaceCatalogRestController::class, 'ordersShow'])
        ->middleware($orderComposerMw)->whereNumber('id');

    Route::get('/console/marketplace/reviews', [AdminMarketplaceReviewsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/reviews/bulk-update', [AdminMarketplaceReviewsRestController::class, 'bulkUpdate'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/marketplace/reviews/bulk-delete', [AdminMarketplaceReviewsRestController::class, 'bulkDestroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketplace/reviews/{id}', [AdminMarketplaceReviewsRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketplace/reviews/{id}', [AdminMarketplaceReviewsRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::post('/console/marketplace/reviews/{id}/moderate', [AdminMarketplaceReviewsRestController::class, 'moderate'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::delete('/console/marketplace/reviews/{id}', [AdminMarketplaceReviewsRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::post('/console/marketplace/communications/sellers/{sellerId}/block', [AdminMarketplaceCommunicationsRestController::class, 'block'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('sellerId');
    Route::post('/console/marketplace/communications/sellers/{sellerId}/unblock', [AdminMarketplaceCommunicationsRestController::class, 'unblock'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('sellerId');
    Route::get('/console/marketplace/communications', [AdminMarketplaceCommunicationsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/communications/clear-history', [AdminMarketplaceCommunicationsRestController::class, 'clearHistory'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/marketplace/communications/{id}', [AdminMarketplaceCommunicationsRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/marketplace/communications/{id}/reply', [AdminMarketplaceCommunicationsRestController::class, 'reply'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketplace/communications/{id}', [AdminMarketplaceCommunicationsRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketplace/returns', [AdminMarketplaceReturnsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/returns/{id}', [AdminMarketplaceReturnsRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/marketplace/returns/{id}/approve', [AdminMarketplaceReturnsRestController::class, 'approve'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketplace/returns/{id}/reject', [AdminMarketplaceReturnsRestController::class, 'reject'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketplace/returns/{id}/receive', [AdminMarketplaceReturnsRestController::class, 'receive'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketplace/returns/{id}/refund', [AdminMarketplaceReturnsRestController::class, 'refund'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketplace/commission-rules', [AdminMarketplaceCommissionRulesRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/commission-rules', [AdminMarketplaceCommissionRulesRestController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketplace/commission-rules/{id}', [AdminMarketplaceCommissionRulesRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketplace/commission-rules/{id}', [AdminMarketplaceCommissionRulesRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketplace/commission-rules/{id}', [AdminMarketplaceCommissionRulesRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/marketplace/stats', [AdminMarketplaceStatsRestController::class, 'overview'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/settings', [AdminMarketplaceSettingsRestController::class, 'show'])
        ->middleware($orderComposerMw);
    Route::put('/console/marketplace/settings', [AdminMarketplaceSettingsRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/marketplace/settings', [AdminMarketplaceSettingsRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);

    Route::get('/console/marketplace/discount-joins', [AdminMarketplaceDiscountJoinsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/discount-joins/{id}/approve', [AdminMarketplaceDiscountJoinsRestController::class, 'approve'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketplace/discount-joins/{id}/disapprove', [AdminMarketplaceDiscountJoinsRestController::class, 'disapprove'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketplace/checkout-deals', [AdminMarketplaceCheckoutDealsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::delete('/console/marketplace/checkout-deals/{id}', [AdminMarketplaceCheckoutDealsRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketplace/landing-page', [AdminMarketplaceLandingRestController::class, 'show'])
        ->middleware($orderComposerMw);
    Route::put('/console/marketplace/landing-page', [AdminMarketplaceLandingRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/marketplace/landing-page', [AdminMarketplaceLandingRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);

    Route::get('/console/marketplace/fulfilment-carriers', [AdminMarketplaceFulfilmentCarriersRestController::class, 'show'])
        ->middleware($orderComposerMw);
    Route::put('/console/marketplace/fulfilment-carriers', [AdminMarketplaceFulfilmentCarriersRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::post('/console/marketplace/fulfilment-carriers', [AdminMarketplaceFulfilmentCarriersRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketplace/warehouses', [AdminMarketplaceWarehousesRestController::class, 'index'])
        ->middleware($orderComposerMw);

    Route::get('/console/marketplace/subscription-plans', [AdminMarketplaceSubscriptionPlansRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/subscription-plans', [AdminMarketplaceSubscriptionPlansRestController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketplace/subscription-plans/{id}', [AdminMarketplaceSubscriptionPlansRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::put('/console/marketplace/subscription-plans/{id}', [AdminMarketplaceSubscriptionPlansRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketplace/subscription-plans/{id}', [AdminMarketplaceSubscriptionPlansRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketplace/subscription-plans/{id}/assign', [AdminMarketplaceSubscriptionPlansRestController::class, 'assign'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::get('/console/marketplace/subscriptions', [AdminMarketplaceSubscriptionPlansRestController::class, 'subscriptionsIndex'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/subscriptions/{id}/approve', [AdminMarketplaceSubscriptionPlansRestController::class, 'subscriptionsApprove'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::post('/console/marketplace/subscriptions/{id}/cancel', [AdminMarketplaceSubscriptionPlansRestController::class, 'subscriptionsCancel'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::post('/console/marketplace/sellers/account-health/bulk-refresh', [AdminMarketplaceAccountHealthRestController::class, 'bulkRefresh'])
        ->middleware([...$adminIntegration, 'throttle:20,1']);
    Route::get('/console/marketplace/sellers/{id}/account-health', [AdminMarketplaceAccountHealthRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/marketplace/sellers/{id}/account-health/refresh', [AdminMarketplaceAccountHealthRestController::class, 'refresh'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketplace/flags', [AdminMarketplaceFlagsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/flags/{id}', [AdminMarketplaceFlagsRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/marketplace/flags/{id}/moderate', [AdminMarketplaceFlagsRestController::class, 'moderate'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::delete('/console/marketplace/flags/{id}', [AdminMarketplaceFlagsRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketplace/product-flags', [AdminMarketplaceProductFlagsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/marketplace/product-flags/{id}', [AdminMarketplaceProductFlagsRestController::class, 'show'])
        ->middleware($orderComposerMw)->whereNumber('id');
    Route::post('/console/marketplace/product-flags/{id}/moderate', [AdminMarketplaceProductFlagsRestController::class, 'moderate'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    Route::delete('/console/marketplace/product-flags/{id}', [AdminMarketplaceProductFlagsRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');

    Route::get('/console/marketplace/featured-sellers', [AdminMarketplaceFeaturedSellersRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/featured-sellers/reorder', [AdminMarketplaceFeaturedSellersRestController::class, 'reorder'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::put('/console/marketplace/featured-sellers/{id}', [AdminMarketplaceFeaturedSellersRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');

    Route::get('/console/marketplace/flag-reasons', [AdminMarketplaceFlagReasonsRestController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::post('/console/marketplace/flag-reasons', [AdminMarketplaceFlagReasonsRestController::class, 'store'])
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::put('/console/marketplace/flag-reasons/{id}', [AdminMarketplaceFlagReasonsRestController::class, 'update'])
        ->middleware([...$adminIntegration, 'throttle:60,1'])->whereNumber('id');
    Route::delete('/console/marketplace/flag-reasons/{id}', [AdminMarketplaceFlagReasonsRestController::class, 'destroy'])
        ->middleware([...$adminIntegration, 'throttle:30,1'])->whereNumber('id');
    // Admin REST Sales → Bookings REST
    Route::get('/console/bookings/export', AdminBookingsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/bookings', [AdminBookingsController::class, 'index'])
        ->middleware($orderComposerMw);
    Route::get('/console/bookings/{id}', [AdminBookingsController::class, 'show'])
        ->middleware($orderComposerMw)
        ->whereNumber('id');
    Route::get('/console/transactions/export', AdminTransactionsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/warehouses/export', AdminWarehousesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/warehouses/export', AdminWarehousesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/currencies/export', AdminCurrenciesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/locales/export', AdminLocalesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/tax-rates/export', AdminTaxRatesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/tax-rules/export', AdminTaxRulesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/tax-categories/export', AdminTaxRulesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/stores/export', AdminStoresExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/roles/export', AdminRolesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/users/export', AdminUsersExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/countries/export', AdminCountriesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/states/export', AdminStatesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/data-transfer-imports/export', AdminDataTransferImportsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/customer-groups/export', AdminCustomerGroupsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/newsletter-subscribers/export', AdminNewsletterSubscribersExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/marketing/discount-rules/export', AdminPromotionsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/discounts/export', AdminPromotionsExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/exchange-rates/export', AdminExchangeRatesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/attributes/export', AdminAttributesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
    Route::get('/console/features/export', AdminAttributesExportController::class)
        ->middleware([...$adminIntegration, 'throttle:30,1']);
