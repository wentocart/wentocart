<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdvancedSettingsController;
use App\Http\Controllers\Admin\AiAssistantController;
use App\Http\Controllers\Admin\AiCustomerAssistantController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\AppController;
use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\AttributeValueController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\AutomationController;
use App\Http\Controllers\Admin\B2bQuoteController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ClassicModuleController;
use App\Http\Controllers\Admin\CmsBlockController;
use App\Http\Controllers\Admin\CmsCategoryController;
use App\Http\Controllers\Admin\CmsPageController;
use App\Http\Controllers\Admin\CommandPaletteController;
use App\Http\Controllers\Admin\CommerceSettingsController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CountryController;
use App\Http\Controllers\Admin\CountryRegionController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerGroupController;
use App\Http\Controllers\Admin\CustomerSettingsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DiscountRuleController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\DocumentTemplateController;
use App\Http\Controllers\Admin\EditorUploadController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\EuWithdrawalRequestController;
use App\Http\Controllers\Admin\GdprRequestController;
use App\Http\Controllers\Admin\GdprSettingsController;
use App\Http\Controllers\Admin\FeatureValueController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\ImageSettingsController;
use App\Http\Controllers\Admin\ImportStudioController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LocalizationController;
use App\Http\Controllers\Admin\LocalizationImportController;
use App\Http\Controllers\Admin\MfaController;
use App\Http\Controllers\Admin\ModuleHostController;
use App\Http\Controllers\Admin\Multivendor\MarketplaceAuditController;
use App\Http\Controllers\Admin\Multivendor\MarketplaceDashboardController;
use App\Http\Controllers\Admin\Multivendor\MarketplaceExtrasController;
use App\Http\Controllers\Admin\Multivendor\MultivendorBulkController;
use App\Http\Controllers\Admin\Multivendor\SellerDiscountController;
use App\Http\Controllers\Admin\Multivendor\SellerFinanceController;
use App\Http\Controllers\Admin\Multivendor\SellerOpsController;
use App\Http\Controllers\Admin\Multivendor\SellerOrderController;
use App\Http\Controllers\Admin\Multivendor\SellerProductController;
use App\Http\Controllers\Admin\Multivendor\SellerProfileController;
use App\Http\Controllers\Admin\Multivendor\SellerRequestController;
use App\Http\Controllers\Admin\Multivendor\SellerReturnController;
use App\Http\Controllers\Admin\Multivendor\SellerShopController;
use App\Http\Controllers\Admin\Multivendor\SellerTaxonomyController;
use App\Http\Controllers\Admin\NewsletterSubscriberController;
use App\Http\Controllers\Admin\OperatorController;
use App\Http\Controllers\Admin\OpsToolsController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderSettingsController;
use App\Http\Controllers\Admin\OrderStageController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\PlatformUpgradeController;
use App\Http\Controllers\Admin\PerformanceSettingsController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductQuestionController;
use App\Http\Controllers\Admin\ProductSettingsController;
use App\Http\Controllers\Admin\StockAlertController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\GiftCardController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SeoSettingsController;
use App\Http\Controllers\Admin\CustomerSubscriptionController;
use App\Http\Controllers\Admin\ShippingMethodController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\ShopGroupController;
use App\Http\Controllers\Admin\StoreSettingsController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\ThemeController;
use App\Http\Controllers\Admin\UiKitController;
use App\Http\Controllers\Admin\UiTranslationController;
use App\Http\Controllers\Admin\UrlSettingsController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\CatalogMediaController;
use App\Http\Controllers\Installer\InstalledController;
use App\Http\Controllers\PluginAssetController;
use App\Http\Controllers\Storefront\CloneHomeController;
use App\Http\Controllers\Storefront\HostedPayController;
use App\Http\Controllers\Storefront\SeoController;
use App\Http\Controllers\Storefront\SocialAuthController;
use App\Http\Controllers\Storefront\StorefrontController;
use App\Http\Controllers\Storefront\WebMcpController;
use App\Http\Controllers\ThemeAssetController;
use App\Http\Middleware\EnsureAdminMfa;
use Illuminate\Support\Facades\Route;

Route::get('/media/{path}', [CatalogMediaController::class, 'show'])->where('path', '.*')->name('media.show');
Route::redirect('/install', '/setup/index.php', 302)->name('install.show');
Route::get('/installed', InstalledController::class)->name('installed');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AdminAuthController::class, 'create'])->name('login');
    Route::post('login', [AdminAuthController::class, 'store'])->middleware('throttle:5,1,admin-login')->name('login.store');
    Route::get('forgot-password', [AdminAuthController::class, 'forgotCreate'])->name('password.request');
    Route::post('forgot-password', [AdminAuthController::class, 'forgotStore'])->middleware('throttle:5,1,admin-forgot')->name('password.email');
    Route::get('reset-password', [AdminAuthController::class, 'resetCreate'])->name('password.reset');
    Route::post('reset-password', [AdminAuthController::class, 'resetStore'])->middleware('throttle:5,1,admin-reset')->name('password.update');
    Route::post('logout', [AdminAuthController::class, 'destroy'])->name('logout');
    Route::get('mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('mfa/challenge', [MfaController::class, 'verifyChallenge'])->middleware('throttle:8,1')->name('mfa.challenge.store');
    Route::get('mfa/passkey/options', [MfaController::class, 'passkeyOptions'])->name('mfa.passkey.options');
    Route::post('mfa/passkey/assert', [MfaController::class, 'passkeyAssert'])->middleware('throttle:8,1')->name('mfa.passkey.assert');
    Route::post('locale', [LanguageController::class, 'switchLocale'])->name('locale');

    Route::middleware(['auth:admin', EnsureAdminMfa::class])->group(function () {
        Route::get('mfa/enroll', [MfaController::class, 'enroll'])->name('mfa.enroll');
        Route::post('mfa/enroll', [MfaController::class, 'confirmEnroll'])->name('mfa.enroll.store');
        Route::get('mfa/passkey/register-options', [MfaController::class, 'passkeyRegisterOptions'])->name('mfa.passkey.register-options');
        Route::post('mfa/passkey/register', [MfaController::class, 'passkeyRegister'])->name('mfa.passkey.register');
        Route::get('/', [DashboardController::class, 'home'])->name('dashboard');
        Route::get('account', [AccountController::class, 'show'])->name('account.show');
        Route::get('account/edit', [AccountController::class, 'edit'])->name('account.edit');
        Route::put('account', [AccountController::class, 'update'])->name('account.update');
        Route::get('dashboards', [DashboardController::class, 'index'])->name('dashboards.index');
        Route::middleware('permission:dashboards.manage')->group(function () {
            Route::get('dashboards/create', [DashboardController::class, 'create'])->name('dashboards.create');
            Route::get('dashboards/search/admins', [DashboardController::class, 'searchAdmins'])->name('dashboards.search.admins');
            Route::post('dashboards', [DashboardController::class, 'store'])->name('dashboards.store');
            Route::post('dashboards/bulk-delete', [DashboardController::class, 'bulkDestroy'])->name('dashboards.bulk-destroy');
            Route::post('dashboards/{dashboard}/toggle', [DashboardController::class, 'toggle'])->name('dashboards.toggle');
            Route::get('dashboards/{dashboard}/edit', [DashboardController::class, 'edit'])->name('dashboards.edit');
            Route::put('dashboards/{dashboard}', [DashboardController::class, 'update'])->name('dashboards.update');
            Route::patch('dashboards/{dashboard}/layout', [DashboardController::class, 'layout'])->name('dashboards.layout');
            Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])->name('dashboards.destroy');
        });
        Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');
        Route::get('ui-kit', [UiKitController::class, 'show'])->name('ui-kit');
        Route::post('ui-kit/flash', [UiKitController::class, 'flash'])->name('ui-kit.flash');
        Route::post('ui-kit/attempt', [UiKitController::class, 'attempt'])->name('ui-kit.attempt');
        Route::post('ui-kit/upload', [UiKitController::class, 'upload'])->middleware('permission:settings.manage')->name('ui-kit.upload');
        Route::post('editor/upload', EditorUploadController::class)->middleware('permission:cms.pages,catalog.products,catalog.files')->name('editor.upload');
        Route::get('command-palette', [CommandPaletteController::class, 'search'])->name('command-palette');
        Route::get('notifications', [AdminNotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/stream', [AdminNotificationController::class, 'stream'])->name('notifications.stream');
        Route::post('notifications/read-all', [AdminNotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [AdminNotificationController::class, 'read'])->name('notifications.read');
        Route::get('seo-fix/status', [HealthController::class, 'status'])->name('seo-fix.status');
        Route::middleware('permission:ops.activity')->group(function () {
            Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
            Route::get('activity/{operation}', [ActivityController::class, 'show'])->name('activity.show');
            Route::post('activity/{operation}/rollback', [ActivityController::class, 'rollback'])->name('activity.rollback');
        });
        Route::middleware('permission:ops.operator')->group(function () {
            Route::get('operator', [OperatorController::class, 'index'])->name('operator.index');
            Route::post('operator/preview', [OperatorController::class, 'preview'])->name('operator.preview');
            Route::post('operator/execute', [OperatorController::class, 'execute'])->name('operator.execute');
        });
        Route::middleware('permission:ops.health')->group(function () {
            Route::get('health', [HealthController::class, 'index'])->name('health.index');
            Route::get('health/issues', [HealthController::class, 'issues'])->name('health.issues');
            Route::get('health/issues/{finding}', [HealthController::class, 'issueShow'])->name('health.issues.show');
            Route::post('health/issues/{finding}/fix', [HealthController::class, 'fix'])->name('health.issues.fix');
            Route::post('health/issues/{finding}/ignore', [HealthController::class, 'ignore'])->name('health.issues.ignore');
            Route::post('health/issues/{finding}/ai', [HealthController::class, 'aiGenerate'])->name('health.issues.ai');
            Route::get('health/seo', [HealthController::class, 'seo'])->name('health.seo');
            Route::get('health/content', [HealthController::class, 'content'])->name('health.content');
            Route::get('health/images', [HealthController::class, 'images'])->name('health.images');
            Route::get('health/performance', [HealthController::class, 'performance'])->name('health.performance');
            Route::get('health/technical', [HealthController::class, 'technical'])->name('health.technical');
            Route::get('health/cleanup', [HealthController::class, 'cleanup'])->name('health.cleanup');
            Route::get('health/fix-center', [HealthController::class, 'fixCenter'])->name('health.fix-center');
            Route::get('health/ai', [HealthController::class, 'aiWorkspace'])->name('health.ai');
            Route::post('health/ai/{proposal}/approve', [HealthController::class, 'aiApprove'])->name('health.ai.approve');
            Route::post('health/ai/{proposal}/apply', [HealthController::class, 'aiApply'])->name('health.ai.apply');
            Route::get('health/inspector', [HealthController::class, 'pageInspector'])->name('health.inspector');
            Route::get('health/history', [HealthController::class, 'history'])->name('health.history');
            Route::get('health/settings', [HealthController::class, 'settings'])->name('health.settings');
            Route::put('health/settings', [HealthController::class, 'saveSettings'])->name('health.settings.update');
            Route::post('health/scan', [HealthController::class, 'scan'])->name('health.scan');
            Route::get('health/scan/{scan}', [HealthController::class, 'scanShow'])->name('health.scan.show');
            Route::get('health/scan-status', [HealthController::class, 'scanStatus'])->name('health.scan.status');
        });
        Route::middleware('permission:ops.automations')->group(function () {
            Route::get('automations', [AutomationController::class, 'index'])->name('automations.index');
            Route::get('automations/create', [AutomationController::class, 'create'])->name('automations.create');
            Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');
            Route::get('automations/{automation}/edit', [AutomationController::class, 'edit'])->name('automations.edit');
            Route::put('automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
            Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');
            Route::post('automations/{automation}/toggle', [AutomationController::class, 'toggle'])->name('automations.toggle');
            Route::post('automations/halt', [AutomationController::class, 'halt'])->name('automations.halt');
        });
        Route::middleware('permission:ops.import')->group(function () {
            Route::get('import-studio', [ImportStudioController::class, 'index'])->name('import-studio.index');
            Route::post('import-studio/upload', [ImportStudioController::class, 'upload'])->name('import-studio.upload');
            Route::post('import-studio/map', [ImportStudioController::class, 'map'])->name('import-studio.map');
            Route::post('import-studio/preview', [ImportStudioController::class, 'preview'])->name('import-studio.preview');
            Route::post('import-studio/execute', [ImportStudioController::class, 'execute'])->name('import-studio.execute');
            Route::post('import-studio/clear', [ImportStudioController::class, 'clear'])->name('import-studio.clear');
        });
        Route::middleware('permission:ops.tools')->group(function () {
            Route::get('ops-tools', [OpsToolsController::class, 'index'])->name('ops-tools.index');
            Route::post('ops-tools/webhooks', [OpsToolsController::class, 'storeWebhook'])->name('ops-tools.webhooks.store');
            Route::delete('ops-tools/webhooks/{webhook}', [OpsToolsController::class, 'destroyWebhook'])->name('ops-tools.webhooks.destroy');
            Route::post('ops-tools/webhooks/{webhook}/ping', [OpsToolsController::class, 'pingWebhook'])->name('ops-tools.webhooks.ping');
            Route::post('ops-tools/webhooks/{webhook}/rotate', [OpsToolsController::class, 'rotateWebhook'])->name('ops-tools.webhooks.rotate');
            Route::post('ops-tools/webhooks/{webhook}/toggle', [OpsToolsController::class, 'toggleWebhook'])->name('ops-tools.webhooks.toggle');
            Route::post('ops-tools/events/toggle', [OpsToolsController::class, 'toggleEvent'])->name('ops-tools.events.toggle');
            Route::post('ops-tools/deliveries/{delivery}/retry', [OpsToolsController::class, 'retryDelivery'])->name('ops-tools.deliveries.retry');
            Route::get('ops-tools/keys/create', [OpsToolsController::class, 'createKey'])->name('ops-tools.keys.create');
            Route::get('ops-tools/webservices', [OpsToolsController::class, 'webservices'])->name('ops-tools.webservices');
            Route::post('ops-tools/keys', [OpsToolsController::class, 'storeKey'])->name('ops-tools.keys.store');
            Route::delete('ops-tools/keys/{key}', [OpsToolsController::class, 'destroyKey'])->name('ops-tools.keys.destroy');
            Route::post('ops-tools/keys/{key}/toggle', [OpsToolsController::class, 'toggleKey'])->name('ops-tools.keys.toggle');
            Route::post('ops-tools/try', [OpsToolsController::class, 'tryWebservice'])->name('ops-tools.try');
        });
        Route::middleware('permission:catalog.products')->group(function () {
            Route::get('products/search', [ProductController::class, 'search'])->name('products.search');
            Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
            Route::post('products/import', [ProductController::class, 'import'])->name('products.import');
            Route::get('products/import/sample', [ProductController::class, 'importSample'])->name('products.import.sample');
            Route::post('products/bulk-delete', [ProductController::class, 'bulkDestroy'])->name('products.bulk-destroy');
            Route::post('products/bulk-duplicate', [ProductController::class, 'bulkDuplicate'])->name('products.bulk-duplicate');
            Route::post('products/bulk-status', [ProductController::class, 'bulkStatus'])->name('products.bulk-status');
            Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
            Route::post('products/{product}/toggle', [ProductController::class, 'toggle'])->name('products.toggle');
            Route::post('products/{product}/images', [ProductController::class, 'upload'])->name('products.images');
            Route::delete('products/{product}/images/{image}', [ProductController::class, 'destroyImage'])->name('products.images.destroy');
            Route::put('products/{product}/attachments', [ProductController::class, 'syncAttachments'])->name('products.attachments.sync');
            Route::delete('products/{product}/attachments/{attachment}', [ProductController::class, 'detachAttachment'])->name('products.attachments.detach');
            Route::put('products/{product}/subscription-plans/{plan}', [ProductController::class, 'updateAssignedSubscription'])->name('products.subscription-plans.update');
            Route::resource('products', ProductController::class)->except(['show']);
            Route::get('stock-alerts', [StockAlertController::class, 'index'])->name('stock-alerts.index');
            Route::post('stock-alerts/bulk', [StockAlertController::class, 'bulk'])->name('stock-alerts.bulk');
            Route::post('stock-alerts/{alert}/resend', [StockAlertController::class, 'resend'])->name('stock-alerts.resend');
            Route::delete('stock-alerts/{alert}', [StockAlertController::class, 'destroy'])->name('stock-alerts.destroy');
            Route::get('purchase-orders/export', [PurchaseOrderController::class, 'export'])->name('purchase-orders.export');
            Route::post('purchase-orders/import', [PurchaseOrderController::class, 'import'])->name('purchase-orders.import');
            Route::get('purchase-orders/import/sample', [PurchaseOrderController::class, 'importSample'])->name('purchase-orders.import.sample');
            Route::post('purchase-orders/{purchaseOrder}/ordered', [PurchaseOrderController::class, 'markOrdered'])->name('purchase-orders.ordered');
            Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
            Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
            Route::post('purchase-orders/{purchaseOrder}/receipts/{receipt}/reverse', [PurchaseOrderController::class, 'reverseReceipt'])->name('purchase-orders.receipts.reverse');
            Route::get('purchase-orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])->name('purchase-orders.print');
            Route::get('purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'pdf'])->name('purchase-orders.pdf');

            Route::resource('purchase-orders', PurchaseOrderController::class)->except(['show']);
            Route::get('gift-cards', [GiftCardController::class, 'index'])->name('gift-cards.index');
            Route::get('gift-cards/create', [GiftCardController::class, 'create'])->name('gift-cards.create');
            Route::post('gift-cards', [GiftCardController::class, 'store'])->name('gift-cards.store');
            Route::get('gift-cards/customers', [GiftCardController::class, 'searchCustomers'])->name('gift-cards.customers');
            Route::get('gift-cards/orders', [GiftCardController::class, 'searchOrders'])->name('gift-cards.orders');
            Route::get('gift-cards/{giftCard}', [GiftCardController::class, 'show'])->name('gift-cards.show');
            Route::post('gift-cards/{giftCard}/status', [GiftCardController::class, 'updateStatus'])->name('gift-cards.status');
            Route::post('gift-cards/{giftCard}/resend', [GiftCardController::class, 'resend'])->name('gift-cards.resend');
        });

        Route::middleware('permission:catalog.inventory,catalog.products,catalog.warehouses,catalog.manage')->group(function () {
            Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
            Route::get('inventory/export', [InventoryController::class, 'export'])->name('inventory.export');
            Route::post('inventory/import', [InventoryController::class, 'import'])->name('inventory.import');
            Route::get('inventory/import/sample', [InventoryController::class, 'importSample'])->name('inventory.import.sample');
            Route::get('inventory/journal', [InventoryController::class, 'journal'])->name('inventory.journal');
            Route::post('inventory/bulk-adjust', [InventoryController::class, 'bulkAdjust'])->name('inventory.bulk-adjust');
            Route::post('inventory/{product}/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
            Route::post('inventory/{product}/transfer', [InventoryController::class, 'transfer'])->name('inventory.transfer');
        });

        Route::middleware('permission:catalog.warehouses,catalog.products,catalog.manage')->group(function () {
            Route::get('warehouses/export', [WarehouseController::class, 'export'])->name('warehouses.export');
            Route::post('warehouses/import', [WarehouseController::class, 'import'])->name('warehouses.import');
            Route::get('warehouses/import/sample', [WarehouseController::class, 'importSample'])->name('warehouses.import.sample');
            Route::post('warehouses/bulk-status', [WarehouseController::class, 'bulkStatus'])->name('warehouses.bulk-status');
            Route::post('warehouses/bulk-delete', [WarehouseController::class, 'bulkDestroy'])->name('warehouses.bulk-destroy');
            Route::get('warehouses/create', [WarehouseController::class, 'create'])->name('warehouses.create');
            Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
            Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
            Route::post('warehouses/{warehouse}/toggle', [WarehouseController::class, 'toggle'])->name('warehouses.toggle');
            Route::get('warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->name('warehouses.edit');
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
            Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
        });

        Route::middleware('permission:catalog.categories')->group(function () {
            Route::get('categories/export', [CategoryController::class, 'export'])->name('categories.export');
            Route::post('categories/import', [CategoryController::class, 'import'])->name('categories.import');
            Route::get('categories/import/sample', [CategoryController::class, 'importSample'])->name('categories.import.sample');
            Route::post('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
            Route::post('categories/bulk-status', [CategoryController::class, 'bulkStatus'])->name('categories.bulk-status');
            Route::post('categories/bulk-delete', [CategoryController::class, 'bulkDestroy'])->name('categories.bulk-destroy');
            Route::post('categories/bulk-duplicate', [CategoryController::class, 'bulkDuplicate'])->name('categories.bulk-duplicate');
            Route::post('categories/{category}/toggle', [CategoryController::class, 'toggle'])->name('categories.toggle');
            Route::resource('categories', CategoryController::class)->except(['show']);
        });

        Route::middleware('permission:catalog.features')->group(function () {
            Route::get('features/values/export', [FeatureValueController::class, 'export'])->name('feature-values.export');
            Route::post('features/values/import', [FeatureValueController::class, 'import'])->name('feature-values.import');
            Route::get('features/values/import/sample', [FeatureValueController::class, 'importSample'])->name('feature-values.import.sample');
            Route::get('features/values', [FeatureValueController::class, 'index'])->name('feature-values.index');
            Route::get('features/values/create', [FeatureValueController::class, 'create'])->name('feature-values.create');
            Route::post('features/values', [FeatureValueController::class, 'store'])->name('feature-values.store');
            Route::post('features/values/{feature_value}/toggle', [FeatureValueController::class, 'toggle'])->name('feature-values.toggle');
            Route::post('features/values/{feature_value}/move', [FeatureValueController::class, 'move'])->name('feature-values.move');
            Route::post('features/values/reorder', [FeatureValueController::class, 'reorder'])->name('feature-values.reorder');
            Route::get('features/values/{feature_value}/edit', [FeatureValueController::class, 'edit'])->name('feature-values.edit');
            Route::put('features/values/{feature_value}', [FeatureValueController::class, 'update'])->name('feature-values.update');
            Route::delete('features/values/{feature_value}', [FeatureValueController::class, 'destroy'])->name('feature-values.destroy');
            Route::get('features/export', [FeatureController::class, 'export'])->name('features.export');
            Route::post('features/import', [FeatureController::class, 'import'])->name('features.import');
            Route::get('features/import/sample', [FeatureController::class, 'importSample'])->name('features.import.sample');
            Route::post('features/bulk-delete', [FeatureController::class, 'bulkDestroy'])->name('features.bulk-destroy');
            Route::post('features/bulk-status', [FeatureController::class, 'bulkStatus'])->name('features.bulk-status');
            Route::post('features/bulk-duplicate', [FeatureController::class, 'bulkDuplicate'])->name('features.bulk-duplicate');
            Route::post('features/reorder', [FeatureController::class, 'reorder'])->name('features.reorder');
            Route::post('features/{feature}/toggle', [FeatureController::class, 'toggle'])->name('features.toggle');
            Route::resource('features', FeatureController::class)->except(['show']);
            Route::get('attributes/values/export', [AttributeValueController::class, 'export'])->name('attribute-values.export');
            Route::post('attributes/values/import', [AttributeValueController::class, 'import'])->name('attribute-values.import');
            Route::get('attributes/values/import/sample', [AttributeValueController::class, 'importSample'])->name('attribute-values.import.sample');
            Route::get('attributes/values', [AttributeValueController::class, 'index'])->name('attribute-values.index');
            Route::get('attributes/values/create', [AttributeValueController::class, 'create'])->name('attribute-values.create');
            Route::post('attributes/values', [AttributeValueController::class, 'store'])->name('attribute-values.store');
            Route::post('attributes/values/bulk-status', [AttributeValueController::class, 'bulkStatus'])->name('attribute-values.bulk-status');
            Route::post('attributes/values/bulk-delete', [AttributeValueController::class, 'bulkDestroy'])->name('attribute-values.bulk-destroy');
            Route::post('attributes/values/{attribute_value}/toggle', [AttributeValueController::class, 'toggle'])->name('attribute-values.toggle');
            Route::post('attributes/values/{attribute_value}/move', [AttributeValueController::class, 'move'])->name('attribute-values.move');
            Route::post('attributes/values/reorder', [AttributeValueController::class, 'reorder'])->name('attribute-values.reorder');
            Route::get('attributes/values/{attribute_value}/edit', [AttributeValueController::class, 'edit'])->name('attribute-values.edit');
            Route::put('attributes/values/{attribute_value}', [AttributeValueController::class, 'update'])->name('attribute-values.update');
            Route::delete('attributes/values/{attribute_value}', [AttributeValueController::class, 'destroy'])->name('attribute-values.destroy');
            Route::get('attributes/export', [AttributeController::class, 'export'])->name('attributes.export');
            Route::post('attributes/import', [AttributeController::class, 'import'])->name('attributes.import');
            Route::get('attributes/import/sample', [AttributeController::class, 'importSample'])->name('attributes.import.sample');
            Route::post('attributes/bulk-delete', [AttributeController::class, 'bulkDestroy'])->name('attributes.bulk-destroy');
            Route::post('attributes/bulk-status', [AttributeController::class, 'bulkStatus'])->name('attributes.bulk-status');
            Route::post('attributes/bulk-duplicate', [AttributeController::class, 'bulkDuplicate'])->name('attributes.bulk-duplicate');
            Route::post('attributes/reorder', [AttributeController::class, 'reorder'])->name('attributes.reorder');
            Route::post('attributes/{attribute}/toggle', [AttributeController::class, 'toggle'])->name('attributes.toggle');
            Route::resource('attributes', AttributeController::class)->except(['show']);
        });

        Route::middleware('permission:catalog.files')->group(function () {
            Route::get('files/search', [AttachmentController::class, 'search'])->name('files.search');
            Route::post('files/bulk-delete', [AttachmentController::class, 'bulkDestroy'])->name('files.bulk-destroy');
            Route::get('files/{file}/preview', [AttachmentController::class, 'preview'])->name('files.preview');
            Route::get('files/{file}/download', [AttachmentController::class, 'download'])->name('files.download');
            Route::resource('files', AttachmentController::class)->except(['show'])->parameters(['files' => 'file']);
        });

        Route::middleware('permission:catalog.brands')->group(function () {
            Route::get('brands/export', [BrandController::class, 'export'])->name('brands.export');
            Route::post('brands/import', [BrandController::class, 'import'])->name('brands.import');
            Route::get('brands/import/sample', [BrandController::class, 'importSample'])->name('brands.import.sample');
            Route::post('brands/bulk-delete', [BrandController::class, 'bulkDestroy'])->name('brands.bulk-destroy');
            Route::post('brands/bulk-duplicate', [BrandController::class, 'bulkDuplicate'])->name('brands.bulk-duplicate');
            Route::post('brands/bulk-status', [BrandController::class, 'bulkStatus'])->name('brands.bulk-status');
            Route::post('brands/{brand}/duplicate', [BrandController::class, 'duplicate'])->name('brands.duplicate');
            Route::post('brands/{brand}/toggle', [BrandController::class, 'toggle'])->name('brands.toggle');
            Route::resource('brands', BrandController::class)->except(['show']);
        });

        Route::middleware('permission:catalog.suppliers')->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
            Route::get('suppliers/export', [SupplierController::class, 'export'])->name('suppliers.export');
            Route::post('suppliers/import', [SupplierController::class, 'import'])->name('suppliers.import');
            Route::get('suppliers/import/sample', [SupplierController::class, 'importSample'])->name('suppliers.import.sample');
            Route::get('suppliers/search/products', [SupplierController::class, 'searchProducts'])->name('suppliers.search.products');
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::post('suppliers/bulk-delete', [SupplierController::class, 'bulkDestroy'])->name('suppliers.bulk-destroy');
            Route::post('suppliers/bulk-status', [SupplierController::class, 'bulkStatus'])->name('suppliers.bulk-status');
            Route::post('suppliers/bulk-duplicate', [SupplierController::class, 'bulkDuplicate'])->name('suppliers.bulk-duplicate');
            Route::post('suppliers/{supplier}/duplicate', [SupplierController::class, 'duplicate'])->name('suppliers.duplicate');
            Route::post('suppliers/{supplier}/toggle', [SupplierController::class, 'toggle'])->name('suppliers.toggle');
            Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
        });

        Route::middleware('permission:catalog.manage,catalog.subscriptions.view,catalog.subscriptions.create,catalog.subscriptions.update,catalog.subscriptions.delete')->group(function () {
            Route::get('subscription-plans/export', [SubscriptionPlanController::class, 'export'])->name('subscription-plans.export');
            Route::post('subscription-plans/import', [SubscriptionPlanController::class, 'import'])->name('subscription-plans.import');
            Route::get('subscription-plans/import/sample', [SubscriptionPlanController::class, 'importSample'])->name('subscription-plans.import.sample');
            Route::post('subscription-plans/bulk-delete', [SubscriptionPlanController::class, 'bulkDestroy'])->name('subscription-plans.bulk-destroy');
            Route::post('subscription-plans/bulk-duplicate', [SubscriptionPlanController::class, 'bulkDuplicate'])->name('subscription-plans.bulk-duplicate');
            Route::post('subscription-plans/bulk-status', [SubscriptionPlanController::class, 'bulkStatus'])->name('subscription-plans.bulk-status');
            Route::post('subscription-plans/{subscription_plan}/duplicate', [SubscriptionPlanController::class, 'duplicate'])->name('subscription-plans.duplicate');
            Route::post('subscription-plans/{subscription_plan}/toggle', [SubscriptionPlanController::class, 'toggle'])->name('subscription-plans.toggle');
            Route::resource('subscription-plans', SubscriptionPlanController::class)->except(['show']);
        });

        // Who is actually subscribed — the merchant-facing half of the feature.
        Route::middleware('permission:catalog.manage,catalog.subscriptions.view,catalog.subscriptions.update')->group(function () {
            Route::get('customer-subscriptions', [CustomerSubscriptionController::class, 'index'])->name('customer-subscriptions.index');
            Route::get('customer-subscriptions/{customer_subscription}', [CustomerSubscriptionController::class, 'show'])->name('customer-subscriptions.show');
            Route::post('customer-subscriptions/{customer_subscription}/cancel', [CustomerSubscriptionController::class, 'cancel'])->name('customer-subscriptions.cancel');
            Route::post('customer-subscriptions/{customer_subscription}/cancel-at-period-end', [CustomerSubscriptionController::class, 'cancelAtPeriodEnd'])->name('customer-subscriptions.cancel-at-period-end');
            Route::post('customer-subscriptions/{customer_subscription}/resume-period-end', [CustomerSubscriptionController::class, 'resumePeriodEnd'])->name('customer-subscriptions.resume-period-end');
            Route::post('customer-subscriptions/{customer_subscription}/pause', [CustomerSubscriptionController::class, 'pause'])->name('customer-subscriptions.pause');
            Route::post('customer-subscriptions/{customer_subscription}/resume', [CustomerSubscriptionController::class, 'resume'])->name('customer-subscriptions.resume');
            Route::post('customer-subscriptions/{customer_subscription}/skip', [CustomerSubscriptionController::class, 'skip'])->name('customer-subscriptions.skip');
            Route::post('customer-subscriptions/{customer_subscription}/retry', [CustomerSubscriptionController::class, 'retry'])->name('customer-subscriptions.retry');
            Route::post('customer-subscriptions/{customer_subscription}/reactivate', [CustomerSubscriptionController::class, 'reactivate'])->name('customer-subscriptions.reactivate');
            Route::post('customer-subscriptions/{customer_subscription}/change-plan', [CustomerSubscriptionController::class, 'changePlan'])->name('customer-subscriptions.change-plan');
            Route::post('customer-subscriptions/{customer_subscription}/trial', [CustomerSubscriptionController::class, 'setTrial'])->name('customer-subscriptions.trial');
        });

        Route::middleware('permission:orders.manage')->group(function () {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/preorders', [OrderController::class, 'preorders'])->name('orders.preorders');
            Route::get('orders/export', [OrderController::class, 'export'])->name('orders.export');
            Route::post('orders/import', [OrderController::class, 'import'])->name('orders.import');
            Route::get('orders/import/sample', [OrderController::class, 'importSample'])->name('orders.import.sample');
            Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
            Route::get('orders/products', [OrderController::class, 'searchProducts'])->name('orders.products');
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            Route::post('orders/customers', [OrderController::class, 'storeCustomer'])->name('orders.customers.store');
            Route::get('orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit');
            Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
            Route::post('orders/{order}/label', [OrderController::class, 'buyLabel'])->name('orders.label');
            Route::post('orders/{order}/label/void', [OrderController::class, 'voidLabel'])->name('orders.label.void');
            Route::post('orders/{order}/refund', [OrderController::class, 'refund'])->name('orders.refund');
            Route::post('orders/{order}/comments', [OrderController::class, 'comment'])->name('orders.comments');
            Route::post('orders/{order}/pay-link', [OrderController::class, 'payLink'])->name('orders.pay-link');
            Route::post('orders/{order}/record-payment', [OrderController::class, 'recordPayment'])->name('orders.record-payment');
            Route::post('orders/{order}/shipments', [OrderController::class, 'splitShipment'])->name('orders.shipments');
            Route::post('orders/{order}/returns/{return}/approve', [OrderController::class, 'approveShopReturn'])->name('orders.returns.approve');
            Route::post('orders/{order}/returns/{return}/reject', [OrderController::class, 'rejectShopReturn'])->name('orders.returns.reject');
            Route::post('orders/{order}/returns/{return}/receive', [OrderController::class, 'receiveShopReturn'])->name('orders.returns.receive');
            Route::post('orders/{order}/returns/{return}/refund', [OrderController::class, 'refundShopReturn'])->name('orders.returns.refund');
            Route::post('orders/{order}/withdrawals/{withdrawal}/approve', [OrderController::class, 'approveEuWithdrawal'])->name('orders.withdrawals.approve');
            Route::post('orders/{order}/withdrawals/{withdrawal}/reject', [OrderController::class, 'rejectEuWithdrawal'])->name('orders.withdrawals.reject');
            Route::post('orders/{order}/withdrawals/{withdrawal}/complete', [OrderController::class, 'completeEuWithdrawal'])->name('orders.withdrawals.complete');
            Route::post('eu-withdrawals/{euWithdrawalRequest}/approve', [EuWithdrawalRequestController::class, 'approve'])->name('eu-withdrawals.approve');
            Route::post('eu-withdrawals/{euWithdrawalRequest}/reject', [EuWithdrawalRequestController::class, 'reject'])->name('eu-withdrawals.reject');
            Route::post('eu-withdrawals/{euWithdrawalRequest}/complete', [EuWithdrawalRequestController::class, 'complete'])->name('eu-withdrawals.complete');
            Route::get('orders/{order}/summary/pdf', [DocumentController::class, 'downloadOrderSummary'])->name('orders.summary.pdf');
            Route::get('orders/{order}/invoice', [DocumentController::class, 'show'])->name('orders.invoice');
            Route::get('orders/{order}/invoice/download', [DocumentController::class, 'download'])->name('orders.invoice.download');
            Route::get('orders/{order}/invoice/pdf', [DocumentController::class, 'downloadPdf'])->name('orders.invoice.pdf');
            Route::post('orders/{order}/invoice/email', [DocumentController::class, 'email'])->name('orders.invoice.email');
            Route::get('orders/{order}/credit-slip', [DocumentController::class, 'showCreditSlip'])->name('orders.credit-slip');
            Route::get('orders/{order}/credit-slip/download', [DocumentController::class, 'downloadCreditSlip'])->name('orders.credit-slip.download');
            Route::get('orders/{order}/credit-slip/pdf', [DocumentController::class, 'downloadCreditSlipPdf'])->name('orders.credit-slip.pdf');
            Route::post('orders/{order}/credit-slip/email', [DocumentController::class, 'emailCreditSlip'])->name('orders.credit-slip.email');
            Route::get('orders/{order}/delivery-slip', [DocumentController::class, 'showDeliverySlip'])->name('orders.delivery-slip');
            Route::get('orders/{order}/delivery-slip/download', [DocumentController::class, 'downloadDeliverySlip'])->name('orders.delivery-slip.download');
            Route::get('orders/{order}/delivery-slip/pdf', [DocumentController::class, 'downloadDeliverySlipPdf'])->name('orders.delivery-slip.pdf');
            Route::post('orders/{order}/delivery-slip/email', [DocumentController::class, 'emailDeliverySlip'])->name('orders.delivery-slip.email');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');

            Route::get('order-stages/export', [OrderStageController::class, 'export'])->name('order-stages.export');
            Route::post('order-stages/import', [OrderStageController::class, 'import'])->name('order-stages.import');
            Route::get('order-stages/import/sample', [OrderStageController::class, 'importSample'])->name('order-stages.import.sample');
            Route::post('order-stages/bulk-status', [OrderStageController::class, 'bulkStatus'])->name('order-stages.bulk-status');
            Route::post('order-stages/bulk-destroy', [OrderStageController::class, 'bulkDestroy'])->name('order-stages.bulk-destroy');
            Route::get('order-stages', [OrderStageController::class, 'index'])->name('order-stages.index');
            Route::get('order-stages/create', [OrderStageController::class, 'create'])->name('order-stages.create');
            Route::post('order-stages', [OrderStageController::class, 'store'])->name('order-stages.store');
            Route::get('order-stages/{orderStage}/edit', [OrderStageController::class, 'edit'])->name('order-stages.edit');
            Route::put('order-stages/{orderStage}', [OrderStageController::class, 'update'])->name('order-stages.update');
            Route::post('order-stages/{orderStage}/toggle', [OrderStageController::class, 'toggle'])->name('order-stages.toggle');
            Route::delete('order-stages/{orderStage}', [OrderStageController::class, 'destroy'])->name('order-stages.destroy');
        });

        Route::middleware('permission:orders.documents')->group(function () {
            Route::get('invoices/export', [DocumentController::class, 'exportInvoices'])->name('invoices.export');
            Route::get('invoices', [DocumentController::class, 'invoices'])->name('invoices.index');
            Route::get('invoices/{order}', [DocumentController::class, 'show'])->name('invoices.show');
            Route::get('invoices/{order}/download', [DocumentController::class, 'download'])->name('invoices.download');
            Route::get('invoices/{order}/pdf', [DocumentController::class, 'downloadPdf'])->name('invoices.pdf');
            Route::post('invoices/{order}/email', [DocumentController::class, 'email'])->name('invoices.email');
            Route::get('credit-slips/export', [DocumentController::class, 'exportCreditSlips'])->name('credit-slips.export');
            Route::get('credit-slips', [DocumentController::class, 'creditSlips'])->name('credit-slips.index');
            Route::get('credit-slips/{order}', [DocumentController::class, 'showCreditSlip'])->name('credit-slips.show');
            Route::get('credit-slips/{order}/download', [DocumentController::class, 'downloadCreditSlip'])->name('credit-slips.download');
            Route::get('credit-slips/{order}/pdf', [DocumentController::class, 'downloadCreditSlipPdf'])->name('credit-slips.pdf');
            Route::post('credit-slips/{order}/email', [DocumentController::class, 'emailCreditSlip'])->name('credit-slips.email');
            Route::get('delivery-slips/export', [DocumentController::class, 'exportDeliverySlips'])->name('delivery-slips.export');
            Route::get('delivery-slips', [DocumentController::class, 'deliverySlips'])->name('delivery-slips.index');
            Route::get('delivery-slips/{order}', [DocumentController::class, 'showDeliverySlip'])->name('delivery-slips.show');
            Route::get('delivery-slips/{order}/download', [DocumentController::class, 'downloadDeliverySlip'])->name('delivery-slips.download');
            Route::get('delivery-slips/{order}/pdf', [DocumentController::class, 'downloadDeliverySlipPdf'])->name('delivery-slips.pdf');
            Route::post('delivery-slips/{order}/email', [DocumentController::class, 'emailDeliverySlip'])->name('delivery-slips.email');
        });

        Route::middleware('permission:payments.manage')->group(function () {
            Route::get('payments/export', [PaymentMethodController::class, 'export'])->name('payments.export');
            Route::post('payments/import', [PaymentMethodController::class, 'import'])->name('payments.import');
            Route::get('payments/import/sample', [PaymentMethodController::class, 'importSample'])->name('payments.import.sample');
            Route::get('payments', [PaymentMethodController::class, 'index'])->name('payments.index');
            Route::get('payments/create', [PaymentMethodController::class, 'create'])->name('payments.create');
            Route::get('payments/search/customers', [PaymentMethodController::class, 'searchCustomers'])->name('payments.search.customers');
            Route::get('payments/search/categories', [PaymentMethodController::class, 'searchCategories'])->name('payments.search.categories');
            Route::post('payments', [PaymentMethodController::class, 'store'])->name('payments.store');
            Route::post('payments/bulk-delete', [PaymentMethodController::class, 'bulkDestroy'])->name('payments.bulk-destroy');
            Route::post('payments/bulk-status', [PaymentMethodController::class, 'bulkStatus'])->name('payments.bulk-status');
            Route::post('payments/bulk-duplicate', [PaymentMethodController::class, 'bulkDuplicate'])->name('payments.bulk-duplicate');
            Route::post('payments/{paymentMethod}/toggle', [PaymentMethodController::class, 'toggle'])->name('payments.toggle');
            Route::get('payments/{paymentMethod}/edit', [PaymentMethodController::class, 'edit'])->name('payments.edit');
            Route::put('payments/{paymentMethod}', [PaymentMethodController::class, 'update'])->name('payments.update');
            Route::delete('payments/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->name('payments.destroy');
        });

        Route::middleware('permission:shipping.manage')->group(function () {
            Route::get('shipping/export', [ShippingMethodController::class, 'export'])->name('shipping.export');
            Route::post('shipping/import', [ShippingMethodController::class, 'import'])->name('shipping.import');
            Route::get('shipping/import/sample', [ShippingMethodController::class, 'importSample'])->name('shipping.import.sample');
            Route::get('shipping', [ShippingMethodController::class, 'index'])->name('shipping.index');
            Route::get('shipping/zones', fn () => redirect()->route('admin.localization.zones'))->name('shipping.zones');
            Route::get('shipping/create', [ShippingMethodController::class, 'create'])->name('shipping.create');
            Route::post('shipping', [ShippingMethodController::class, 'store'])->name('shipping.store');
            Route::post('shipping/bulk-delete', [ShippingMethodController::class, 'bulkDestroy'])->name('shipping.bulk-destroy');
            Route::post('shipping/bulk-status', [ShippingMethodController::class, 'bulkStatus'])->name('shipping.bulk-status');
            Route::post('shipping/bulk-duplicate', [ShippingMethodController::class, 'bulkDuplicate'])->name('shipping.bulk-duplicate');
            Route::post('shipping/{shippingMethod}/duplicate', [ShippingMethodController::class, 'duplicate'])->name('shipping.duplicate');
            Route::post('shipping/{shippingMethod}/toggle', [ShippingMethodController::class, 'toggle'])->name('shipping.toggle');
            Route::get('shipping/{shippingMethod}/edit', [ShippingMethodController::class, 'edit'])->name('shipping.edit');
            Route::put('shipping/{shippingMethod}', [ShippingMethodController::class, 'update'])->name('shipping.update');
            Route::delete('shipping/{shippingMethod}', [ShippingMethodController::class, 'destroy'])->name('shipping.destroy');
        });

        Route::middleware('permission:marketing.discounts')->group(function () {
            Route::get('discounts/export', [DiscountRuleController::class, 'export'])->name('discounts.export');
            Route::post('discounts/import', [DiscountRuleController::class, 'import'])->name('discounts.import');
            Route::get('discounts/import/sample', [DiscountRuleController::class, 'importSample'])->name('discounts.import.sample');
            Route::get('discounts', [DiscountRuleController::class, 'index'])->name('discounts.index');
            Route::get('discounts/create', [DiscountRuleController::class, 'create'])->name('discounts.create');
            Route::get('discounts/search/products', [DiscountRuleController::class, 'searchProducts'])->name('discounts.search.products');
            Route::get('discounts/search/customers', [DiscountRuleController::class, 'searchCustomers'])->name('discounts.search.customers');
            Route::get('discounts/search/categories', [DiscountRuleController::class, 'searchCategories'])->name('discounts.search.categories');
            Route::get('discounts/search/brands', [DiscountRuleController::class, 'searchBrands'])->name('discounts.search.brands');
            Route::get('discounts/search/regions', [DiscountRuleController::class, 'searchRegions'])->name('discounts.search.regions');
            Route::post('discounts', [DiscountRuleController::class, 'store'])->name('discounts.store');
            Route::post('discounts/bulk-delete', [DiscountRuleController::class, 'bulkDestroy'])->name('discounts.bulk-destroy');
            Route::post('discounts/bulk-status', [DiscountRuleController::class, 'bulkStatus'])->name('discounts.bulk-status');
            Route::post('discounts/bulk-duplicate', [DiscountRuleController::class, 'bulkDuplicate'])->name('discounts.bulk-duplicate');
            Route::post('discounts/{discount}/toggle', [DiscountRuleController::class, 'toggle'])->name('discounts.toggle');
            Route::get('discounts/{discount}/edit', [DiscountRuleController::class, 'edit'])->name('discounts.edit');
            Route::put('discounts/{discount}', [DiscountRuleController::class, 'update'])->name('discounts.update');
            Route::delete('discounts/{discount}', [DiscountRuleController::class, 'destroy'])->name('discounts.destroy');
            Route::get('coupons', fn () => redirect()->route('admin.discounts.index'))->name('coupons.index');
            Route::post('coupons', fn () => redirect()->route('admin.discounts.index'))->name('coupons.store');
        });

        Route::middleware('permission:marketing.newsletter')->group(function () {
            Route::get('newsletter/export', [NewsletterSubscriberController::class, 'export'])->name('newsletter.export');
            Route::post('newsletter/import', [NewsletterSubscriberController::class, 'import'])->name('newsletter.import');
            Route::get('newsletter/import/sample', [NewsletterSubscriberController::class, 'importSample'])->name('newsletter.import.sample');
            Route::get('newsletter', [NewsletterSubscriberController::class, 'index'])->name('newsletter.index');
            Route::post('newsletter/campaigns', [NewsletterSubscriberController::class, 'sendCampaign'])->name('newsletter.campaigns.store');
            Route::post('newsletter/bulk-delete', [NewsletterSubscriberController::class, 'bulkDestroy'])->name('newsletter.bulk-destroy');
            Route::delete('newsletter/{subscriber}', [NewsletterSubscriberController::class, 'destroy'])->name('newsletter.destroy');
        });

        Route::middleware('permission:customers.customers')->group(function () {
            Route::get('quotes', [B2bQuoteController::class, 'index'])->name('quotes.index');
            Route::post('quotes/bulk', [B2bQuoteController::class, 'bulk'])->name('quotes.bulk');
            Route::post('quotes/{quote}/accept', [B2bQuoteController::class, 'accept'])->name('quotes.accept');
            Route::post('quotes/{quote}/decline', [B2bQuoteController::class, 'decline'])->name('quotes.decline');
            Route::post('quotes/{quote}/convert', [B2bQuoteController::class, 'convert'])->name('quotes.convert');
            Route::delete('quotes/{quote}', [B2bQuoteController::class, 'destroy'])->name('quotes.destroy');
            Route::get('companies/export', [CompanyController::class, 'export'])->name('companies.export');
            Route::post('companies/import', [CompanyController::class, 'import'])->name('companies.import');
            Route::get('companies/import/sample', [CompanyController::class, 'importSample'])->name('companies.import.sample');
            Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
            Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
            Route::put('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
            Route::post('companies/{company}/attach', [CompanyController::class, 'attach'])->name('companies.attach');
            Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
            Route::post('customers/import', [CustomerController::class, 'import'])->name('customers.import');
            Route::get('customers/import/sample', [CustomerController::class, 'importSample'])->name('customers.import.sample');
            Route::post('customers/bulk', [CustomerController::class, 'bulk'])->name('customers.bulk');
            Route::post('customers/{customer}/toggle', [CustomerController::class, 'toggle'])->name('customers.toggle');
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::post('customers/{customer}/impersonate', [CustomerController::class, 'impersonate'])->name('customers.impersonate');
            Route::post('customers/{customer}/desk-notes', [CustomerController::class, 'storeDeskNote'])->name('customers.desk-notes.store');
            Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::post('customers/{customer}/addresses', [CustomerController::class, 'storeAddress'])->name('customers.addresses.store');
            Route::put('customers/{customer}/addresses/{address}', [CustomerController::class, 'updateAddress'])->name('customers.addresses.update');
            Route::delete('customers/{customer}/addresses/{address}', [CustomerController::class, 'destroyAddress'])->name('customers.addresses.destroy');
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        });

        Route::middleware('permission:customers.groups')->group(function () {
            Route::get('customer-groups', [CustomerGroupController::class, 'index'])->name('customer-groups.index');
            Route::get('customer-groups/export', [CustomerGroupController::class, 'export'])->name('customer-groups.export');
            Route::post('customer-groups/import', [CustomerGroupController::class, 'import'])->name('customer-groups.import');
            Route::get('customer-groups/import/sample', [CustomerGroupController::class, 'importSample'])->name('customer-groups.import.sample');
            Route::post('customer-groups', [CustomerGroupController::class, 'store'])->name('customer-groups.store');
            Route::post('customer-groups/bulk-delete', [CustomerGroupController::class, 'bulkDestroy'])->name('customer-groups.bulk-destroy');
            Route::post('customer-groups/bulk-status', [CustomerGroupController::class, 'bulkStatus'])->name('customer-groups.bulk-status');
            Route::post('customer-groups/bulk-duplicate', [CustomerGroupController::class, 'bulkDuplicate'])->name('customer-groups.bulk-duplicate');
            Route::put('customer-groups/{customerGroup}', [CustomerGroupController::class, 'update'])->name('customer-groups.update');
            Route::post('customer-groups/{customerGroup}/toggle', [CustomerGroupController::class, 'toggle'])->name('customer-groups.toggle');
            Route::post('customer-groups/{customerGroup}/duplicate', [CustomerGroupController::class, 'duplicate'])->name('customer-groups.duplicate');
            Route::delete('customer-groups/{customerGroup}', [CustomerGroupController::class, 'destroy'])->name('customer-groups.destroy');
        });

        Route::middleware('permission:customers.addresses')->group(function () {
            Route::get('addresses/search/customers', [CustomerController::class, 'searchAddressCustomers'])->name('addresses.search.customers');
            Route::get('addresses/export', [CustomerController::class, 'exportAddresses'])->name('addresses.export');
            Route::post('addresses/import', [CustomerController::class, 'importAddresses'])->name('addresses.import');
            Route::get('addresses/import/sample', [CustomerController::class, 'importAddressesSample'])->name('addresses.import.sample');
            Route::get('addresses', [CustomerController::class, 'addresses'])->name('addresses.index');
            Route::post('addresses', [CustomerController::class, 'storeStandaloneAddress'])->name('addresses.store');
            Route::post('addresses/bulk-delete', [CustomerController::class, 'bulkDestroyAddresses'])->name('addresses.bulk-destroy');
            Route::put('addresses/{customerAddress}', [CustomerController::class, 'updateStandaloneAddress'])->name('addresses.update');
            Route::delete('addresses/{customerAddress}', [CustomerController::class, 'destroyStandaloneAddress'])->name('addresses.destroy');
        });

        Route::middleware('permission:customers.reviews')->group(function () {
            Route::get('reviews/export', [ReviewController::class, 'export'])->name('reviews.export');
            Route::post('reviews/import', [ReviewController::class, 'import'])->name('reviews.import');
            Route::get('reviews/import/sample', [ReviewController::class, 'importSample'])->name('reviews.import.sample');
            Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
            Route::post('reviews/bulk', [ReviewController::class, 'bulk'])->name('reviews.bulk');
            Route::post('reviews/{review}', [ReviewController::class, 'moderate'])->name('reviews.moderate');
        });

        Route::middleware('permission:customers.questions')->group(function () {
            Route::get('questions/export', [ProductQuestionController::class, 'export'])->name('questions.export');
            Route::post('questions/import', [ProductQuestionController::class, 'import'])->name('questions.import');
            Route::get('questions/import/sample', [ProductQuestionController::class, 'importSample'])->name('questions.import.sample');
            Route::get('questions', [ProductQuestionController::class, 'index'])->name('questions.index');
            Route::post('questions/bulk', [ProductQuestionController::class, 'bulk'])->name('questions.bulk');
            Route::post('questions/{question}', [ProductQuestionController::class, 'moderate'])->name('questions.moderate');
        });

        Route::middleware('permission:customers.support')->group(function () {
            Route::get('support', [SupportController::class, 'index'])->name('support.index');
            Route::post('support/settings', [SupportController::class, 'saveSettings'])->name('support.settings');
            Route::post('support/bulk', [SupportController::class, 'bulk'])->name('support.bulk');
            Route::get('support/{ticket}', [SupportController::class, 'show'])->name('support.show');
            Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])->name('support.reply');
            Route::post('support/{ticket}', [SupportController::class, 'updateStatus'])->name('support.status');
        });

        Route::middleware('marketplace')->group(function () {
            Route::post('multivendor/bulk', MultivendorBulkController::class)
                ->middleware('permission:multivendor.manage,multivendor.products,multivendor.categories,multivendor.brands,multivendor.features,multivendor.discounts,multivendor.shipping,multivendor.payments,multivendor.reviews,multivendor.commissions')
                ->name('multivendor.bulk');

            Route::middleware('permission:multivendor.dashboard,multivendor.seller-requests,multivendor.manage')->group(function () {
                Route::get('multivendor/dashboard', MarketplaceDashboardController::class)->name('multivendor.dashboard');
            });

            Route::middleware('permission:multivendor.seller-requests,customers.sellers')->group(function () {
                Route::get('sellers', [SellerRequestController::class, 'index'])->name('sellers.index');
                Route::get('sellers/{seller}', [SellerRequestController::class, 'show'])->name('sellers.show');
                Route::post('sellers/{seller}/review', [SellerRequestController::class, 'review'])->name('sellers.review');
                Route::get('multivendor/seller-requests', [SellerRequestController::class, 'index'])->name('multivendor.seller-requests.index');
                Route::get('multivendor/seller-requests/{seller}', [SellerRequestController::class, 'show'])->name('multivendor.seller-requests.show');
                Route::post('multivendor/seller-requests/{seller}/review', [SellerRequestController::class, 'review'])->name('multivendor.seller-requests.review');
                Route::get('multivendor/sellers', [SellerRequestController::class, 'sellers'])->name('multivendor.sellers.index');
                Route::get('multivendor/sellers/{seller}', [SellerRequestController::class, 'show'])->name('multivendor.sellers.show');
            });

            Route::middleware('permission:multivendor.seller-profiles')->group(function () {
                Route::get('multivendor/seller-profiles', [SellerProfileController::class, 'index'])->name('multivendor.seller-profiles.index');
                Route::get('multivendor/seller-profiles/{seller}/edit', [SellerProfileController::class, 'edit'])->name('multivendor.seller-profiles.edit');
                Route::put('multivendor/seller-profiles/{seller}', [SellerProfileController::class, 'update'])->name('multivendor.seller-profiles.update');
            });

            Route::middleware('permission:multivendor.seller-shops')->group(function () {
                Route::get('multivendor/seller-shops', [SellerShopController::class, 'index'])->name('multivendor.seller-shops.index');
                Route::get('multivendor/seller-shops/{seller}/edit', [SellerShopController::class, 'edit'])->name('multivendor.seller-shops.edit');
                Route::put('multivendor/seller-shops/{seller}', [SellerShopController::class, 'update'])->name('multivendor.seller-shops.update');
            });

            Route::middleware('permission:multivendor.products')->group(function () {
                Route::get('multivendor/products', [SellerProductController::class, 'index'])->name('multivendor.products.index');
                Route::get('multivendor/products/export', [SellerProductController::class, 'export'])->name('multivendor.products.export');
                Route::get('multivendor/products/create', [SellerProductController::class, 'create'])->name('multivendor.products.create');
                Route::post('multivendor/products', [SellerProductController::class, 'store'])->name('multivendor.products.store');
                Route::post('multivendor/products/{sellerProduct}/review', [SellerProductController::class, 'review'])->name('multivendor.products.review');
                Route::get('multivendor/products/{sellerProduct}/edit', [SellerProductController::class, 'edit'])->name('multivendor.products.edit');
                Route::put('multivendor/products/{sellerProduct}', [SellerProductController::class, 'update'])->name('multivendor.products.update');
                Route::delete('multivendor/products/{sellerProduct}', [SellerProductController::class, 'destroy'])->name('multivendor.products.destroy');
            });

            Route::middleware('permission:multivendor.discounts')->group(function () {
                Route::get('multivendor/discounts', [SellerDiscountController::class, 'index'])->name('multivendor.discounts.index');
                Route::get('multivendor/discounts/create', [SellerDiscountController::class, 'create'])->name('multivendor.discounts.create');
                Route::post('multivendor/discounts', [SellerDiscountController::class, 'store'])->name('multivendor.discounts.store');
                Route::get('multivendor/discounts/{sellerDiscount}/edit', [SellerDiscountController::class, 'edit'])->name('multivendor.discounts.edit');
                Route::put('multivendor/discounts/{sellerDiscount}', [SellerDiscountController::class, 'update'])->name('multivendor.discounts.update');
                Route::delete('multivendor/discounts/{sellerDiscount}', [SellerDiscountController::class, 'destroy'])->name('multivendor.discounts.destroy');
            });

            foreach (['categories', 'features', 'brands', 'suppliers'] as $kind) {
                Route::middleware('permission:multivendor.'.$kind)->group(function () use ($kind) {
                    Route::get('multivendor/'.$kind, [SellerTaxonomyController::class, 'index'])->defaults('kind', $kind)->name('multivendor.'.$kind.'.index');
                    Route::get('multivendor/'.$kind.'/create', [SellerTaxonomyController::class, 'create'])->defaults('kind', $kind)->name('multivendor.'.$kind.'.create');
                    Route::post('multivendor/'.$kind, [SellerTaxonomyController::class, 'store'])->defaults('kind', $kind)->name('multivendor.'.$kind.'.store');
                    Route::get('multivendor/'.$kind.'/{id}/edit', [SellerTaxonomyController::class, 'edit'])->defaults('kind', $kind)->name('multivendor.'.$kind.'.edit');
                    Route::put('multivendor/'.$kind.'/{id}', [SellerTaxonomyController::class, 'update'])->defaults('kind', $kind)->name('multivendor.'.$kind.'.update');
                    Route::delete('multivendor/'.$kind.'/{id}', [SellerTaxonomyController::class, 'destroy'])->defaults('kind', $kind)->name('multivendor.'.$kind.'.destroy');
                });
            }

            Route::middleware('permission:multivendor.orders')->group(function () {
                Route::get('multivendor/orders', [SellerOrderController::class, 'index'])->name('multivendor.orders.index');
                Route::get('multivendor/orders/export', [MarketplaceExtrasController::class, 'exportOrders'])->name('multivendor.orders.export');
                Route::get('multivendor/orders/{sellerOrder}', [SellerOrderController::class, 'show'])->name('multivendor.orders.show');
                Route::put('multivendor/orders/{sellerOrder}/status', [SellerOrderController::class, 'updateStatus'])->name('multivendor.orders.status');
            });

            Route::middleware('permission:multivendor.returns')->group(function () {
                Route::get('multivendor/returns', [SellerReturnController::class, 'index'])->name('multivendor.returns.index');
                Route::get('multivendor/returns/{sellerReturn}', [SellerReturnController::class, 'show'])->name('multivendor.returns.show');
                Route::post('multivendor/returns/{sellerReturn}/approve', [SellerReturnController::class, 'approve'])->name('multivendor.returns.approve');
                Route::post('multivendor/returns/{sellerReturn}/reject', [SellerReturnController::class, 'reject'])->name('multivendor.returns.reject');
                Route::post('multivendor/returns/{sellerReturn}/receive', [SellerReturnController::class, 'receive'])->name('multivendor.returns.receive');
                Route::post('multivendor/returns/{sellerReturn}/refund', [SellerReturnController::class, 'refund'])->name('multivendor.returns.refund');
            });

            Route::middleware('permission:multivendor.earnings')->group(function () {
                Route::get('multivendor/earnings', [SellerFinanceController::class, 'earnings'])->name('multivendor.earnings.index');
                Route::get('multivendor/earnings/export', [MarketplaceExtrasController::class, 'exportTransactions'])->name('multivendor.earnings.export');
                Route::post('multivendor/earnings/{sellerEarning}/status', [SellerFinanceController::class, 'updateEarning'])->name('multivendor.earnings.status');
                Route::get('multivendor/ledger', [SellerFinanceController::class, 'ledger'])->name('multivendor.ledger.index');
            });

            Route::middleware('permission:multivendor.commissions')->group(function () {
                Route::get('multivendor/commissions', [SellerFinanceController::class, 'commissions'])->name('multivendor.commissions.index');
                Route::get('multivendor/commissions/create', [SellerFinanceController::class, 'createCommission'])->name('multivendor.commissions.create');
                Route::post('multivendor/commissions', [SellerFinanceController::class, 'storeCommission'])->name('multivendor.commissions.store');
                Route::get('multivendor/commissions/{sellerCommission}/edit', [SellerFinanceController::class, 'editCommission'])->name('multivendor.commissions.edit');
                Route::put('multivendor/commissions/{sellerCommission}', [SellerFinanceController::class, 'updateCommission'])->name('multivendor.commissions.update');
                Route::delete('multivendor/commissions/{sellerCommission}', [SellerFinanceController::class, 'destroyCommission'])->name('multivendor.commissions.destroy');

                Route::get('multivendor/commission-rules', [SellerFinanceController::class, 'rules'])->name('multivendor.commission-rules.index');
                Route::get('multivendor/commission-rules/create', [SellerFinanceController::class, 'createRule'])->name('multivendor.commission-rules.create');
                Route::post('multivendor/commission-rules', [SellerFinanceController::class, 'storeRule'])->name('multivendor.commission-rules.store');
                Route::get('multivendor/commission-rules/{commissionRule}/edit', [SellerFinanceController::class, 'editRule'])->name('multivendor.commission-rules.edit');
                Route::put('multivendor/commission-rules/{commissionRule}', [SellerFinanceController::class, 'updateRule'])->name('multivendor.commission-rules.update');
                Route::delete('multivendor/commission-rules/{commissionRule}', [SellerFinanceController::class, 'destroyRule'])->name('multivendor.commission-rules.destroy');
            });

            Route::middleware('permission:multivendor.shipping')->group(function () {
                Route::get('multivendor/shipping', [SellerOpsController::class, 'shipping'])->name('multivendor.shipping.index');
                Route::get('multivendor/shipping/create', [SellerOpsController::class, 'createShipping'])->name('multivendor.shipping.create');
                Route::post('multivendor/shipping', [SellerOpsController::class, 'storeShipping'])->name('multivendor.shipping.store');
                Route::get('multivendor/shipping/{sellerShippingMethod}/edit', [SellerOpsController::class, 'editShipping'])->name('multivendor.shipping.edit');
                Route::put('multivendor/shipping/{sellerShippingMethod}', [SellerOpsController::class, 'updateShipping'])->name('multivendor.shipping.update');
                Route::delete('multivendor/shipping/{sellerShippingMethod}', [SellerOpsController::class, 'destroyShipping'])->name('multivendor.shipping.destroy');
            });

            Route::middleware('permission:multivendor.payments')->group(function () {
                Route::get('multivendor/payments', [SellerOpsController::class, 'payments'])->name('multivendor.payments.index');
                Route::get('multivendor/payments/create', [SellerOpsController::class, 'createPayment'])->name('multivendor.payments.create');
                Route::post('multivendor/payments', [SellerOpsController::class, 'storePayment'])->name('multivendor.payments.store');
                Route::get('multivendor/payments/{sellerPaymentMethod}/edit', [SellerOpsController::class, 'editPayment'])->name('multivendor.payments.edit');
                Route::put('multivendor/payments/{sellerPaymentMethod}', [SellerOpsController::class, 'updatePayment'])->name('multivendor.payments.update');
                Route::delete('multivendor/payments/{sellerPaymentMethod}', [SellerOpsController::class, 'destroyPayment'])->name('multivendor.payments.destroy');
            });

            Route::middleware('permission:multivendor.payouts')->group(function () {
                Route::get('multivendor/payouts', [SellerOpsController::class, 'payouts'])->name('multivendor.payouts.index');
                Route::get('multivendor/payouts/export', [SellerOpsController::class, 'exportPayouts'])->name('multivendor.payouts.export');
                Route::get('multivendor/payouts/create', [SellerOpsController::class, 'createPayout'])->name('multivendor.payouts.create');
                Route::post('multivendor/payouts', [SellerOpsController::class, 'storePayout'])->name('multivendor.payouts.store');
                Route::get('multivendor/payouts/{sellerWithdrawal}', [SellerOpsController::class, 'showPayout'])->name('multivendor.payouts.show');
                Route::post('multivendor/payouts/{sellerWithdrawal}/process', [SellerOpsController::class, 'processPayout'])->name('multivendor.payouts.process');
            });

            Route::put('sellers/{seller}/commission', [SellerRequestController::class, 'updateCommission'])->middleware('permission:multivendor.commissions,customers.sellers,multivendor.seller-requests')->name('sellers.commission');

            Route::middleware('permission:multivendor.manage,multivendor.dashboard')->group(function () {
                Route::get('multivendor/audits', [MarketplaceAuditController::class, 'index'])->name('multivendor.audits.index');
            });

            Route::middleware('permission:multivendor.settings')->group(function () {
                Route::get('multivendor/settings', [MarketplaceExtrasController::class, 'settings'])->name('multivendor.settings.edit');
                Route::put('multivendor/settings', [MarketplaceExtrasController::class, 'updateSettings'])->name('multivendor.settings.update');
            });

            Route::middleware('permission:multivendor.reviews')->group(function () {
                Route::get('multivendor/reviews', [MarketplaceExtrasController::class, 'reviews'])->name('multivendor.reviews.index');
                Route::post('multivendor/reviews/{sellerReview}/moderate', [MarketplaceExtrasController::class, 'moderateReview'])->name('multivendor.reviews.moderate');
                Route::delete('multivendor/reviews/{sellerReview}', [MarketplaceExtrasController::class, 'destroyReview'])->name('multivendor.reviews.destroy');
            });

            Route::middleware('permission:multivendor.messages')->group(function () {
                Route::get('multivendor/messages', [MarketplaceExtrasController::class, 'messages'])->name('multivendor.messages.index');
                Route::get('multivendor/messages/{sellerMessage}', [MarketplaceExtrasController::class, 'showMessage'])->name('multivendor.messages.show');
                Route::post('multivendor/messages/{sellerMessage}/reply', [MarketplaceExtrasController::class, 'replyMessage'])->name('multivendor.messages.reply');
            });

            Route::get('multivendor/seller-profiles/export', [MarketplaceExtrasController::class, 'exportSellers'])
                ->middleware('permission:multivendor.seller-profiles')
                ->name('multivendor.seller-profiles.export');
        });

        Route::middleware('permission:cms.pages')->group(function () {
            Route::get('cms/export', [CmsPageController::class, 'export'])->name('cms.export');
            Route::post('cms/import', [CmsPageController::class, 'import'])->name('cms.import');
            Route::get('cms/import/sample', [CmsPageController::class, 'importSample'])->name('cms.import.sample');
            Route::get('cms', [CmsPageController::class, 'index'])->name('cms.index');
            Route::get('cms/create', [CmsPageController::class, 'create'])->name('cms.create');
            Route::post('cms', [CmsPageController::class, 'store'])->name('cms.store');
            Route::post('cms/bulk-delete', [CmsPageController::class, 'bulkDelete'])->name('cms.bulk-delete');
            Route::get('cms/categories/export', [CmsCategoryController::class, 'export'])->name('cms.categories.export');
            Route::post('cms/categories/import', [CmsCategoryController::class, 'import'])->name('cms.categories.import');
            Route::get('cms/categories/import/sample', [CmsCategoryController::class, 'importSample'])->name('cms.categories.import.sample');
            Route::post('cms/categories', [CmsCategoryController::class, 'store'])->name('cms.categories.store');
            Route::put('cms/categories/{cmsCategory}', [CmsCategoryController::class, 'update'])->name('cms.categories.update');
            Route::delete('cms/categories/{cmsCategory}', [CmsCategoryController::class, 'destroy'])->name('cms.categories.destroy');
            Route::get('cms/{cmsPage}/edit', [CmsPageController::class, 'edit'])->name('cms.edit');
            Route::put('cms/{cmsPage}', [CmsPageController::class, 'update'])->name('cms.update');
            Route::delete('cms/{cmsPage}', [CmsPageController::class, 'destroy'])->name('cms.destroy');
            Route::post('cms/{cmsPage}/toggle', [CmsPageController::class, 'toggle'])->name('cms.toggle');
            Route::post('cms/{cmsPage}/toggle-visibility', [CmsPageController::class, 'toggleVisibility'])->name('cms.toggle-visibility');
        });

        Route::middleware('permission:cms.blocks')->group(function () {
            Route::get('blocks/export', [CmsBlockController::class, 'export'])->name('blocks.export');
            Route::post('blocks/import', [CmsBlockController::class, 'import'])->name('blocks.import');
            Route::get('blocks/import/sample', [CmsBlockController::class, 'importSample'])->name('blocks.import.sample');
            Route::get('blocks', [CmsBlockController::class, 'index'])->name('blocks.index');
            Route::get('blocks/create', [CmsBlockController::class, 'create'])->name('blocks.create');
            Route::post('blocks', [CmsBlockController::class, 'store'])->name('blocks.store');
            Route::post('blocks/bulk-delete', [CmsBlockController::class, 'bulkDestroy'])->name('blocks.bulk-destroy');
            Route::post('blocks/{block}/toggle', [CmsBlockController::class, 'toggle'])->name('blocks.toggle');
            Route::get('blocks/{block}/edit', [CmsBlockController::class, 'edit'])->name('blocks.edit');
            Route::put('blocks/{block}', [CmsBlockController::class, 'update'])->name('blocks.update');
            Route::delete('blocks/{block}', [CmsBlockController::class, 'destroy'])->name('blocks.destroy');
        });

        Route::middleware('permission:design.themes')->group(function () {
            Route::get('themes', [ThemeController::class, 'index'])->name('themes.index');
            Route::get('themes/settings', [ThemeController::class, 'settings'])->name('themes.settings');
            Route::put('themes/settings', [ThemeController::class, 'updateSettings'])->name('themes.settings.update');
            Route::post('themes/settings/reset', [ThemeController::class, 'resetSettings'])->name('themes.settings.reset');
            Route::post('themes/settings/logo', [ThemeController::class, 'uploadLogo'])->name('themes.settings.logo');
            Route::post('themes/settings/favicon', [ThemeController::class, 'uploadFavicon'])->name('themes.settings.favicon');
            Route::get('themes/modules/{code}', [ClassicModuleController::class, 'show'])->name('themes.modules.show');
            Route::post('themes/modules/{code}/media', [ClassicModuleController::class, 'uploadMedia'])->name('themes.modules.media');
            Route::put('themes/modules/{code}', [ClassicModuleController::class, 'update'])->name('themes.modules.update');
            Route::post('themes', [ThemeController::class, 'store'])->name('themes.store');
            Route::post('themes/enable', [ThemeController::class, 'enable'])->name('themes.enable');
            Route::post('themes/preview', [ThemeController::class, 'preview'])->name('themes.preview');
            Route::delete('themes/{theme}', [ThemeController::class, 'destroy'])->name('themes.destroy');
        });

        Route::middleware('permission:apps.manage')->group(function () {
            Route::get('apps', [AppController::class, 'index'])->name('apps.index');
            Route::post('apps', [AppController::class, 'store'])->name('apps.store');
            Route::post('apps/catalog/{vendor}/{name}', [AppController::class, 'installCatalog'])->name('apps.catalog');
            Route::get('apps/{vendor}/{name}', [AppController::class, 'show'])->name('apps.show');
            Route::put('apps/{vendor}/{name}', [AppController::class, 'update'])->name('apps.update');
            Route::post('apps/{vendor}/{name}/enable', [AppController::class, 'enable'])->name('apps.enable');
            Route::post('apps/{vendor}/{name}/upgrade', [AppController::class, 'upgrade'])->name('apps.upgrade');
            Route::delete('apps/{vendor}/{name}', [AppController::class, 'destroy'])->name('apps.destroy');
            Route::delete('apps/{vendor}/{name}/files', [AppController::class, 'delete'])->name('apps.delete');

            // Merchant-facing aliases (Extensions = Apps)
            Route::get('extensions', [AppController::class, 'index'])->name('extensions.index');
            Route::post('extensions', [AppController::class, 'store'])->name('extensions.store');
            Route::post('extensions/catalog/{vendor}/{name}', [AppController::class, 'installCatalog'])->name('extensions.catalog');
            Route::get('extensions/{vendor}/{name}', [AppController::class, 'show'])->name('extensions.show');
            Route::match(['put', 'post'], 'extensions/{vendor}/{name}', [AppController::class, 'update'])->name('extensions.update');
            Route::post('extensions/{vendor}/{name}/enable', [AppController::class, 'enable'])->name('extensions.enable');
            Route::post('extensions/{vendor}/{name}/upgrade', [AppController::class, 'upgrade'])->name('extensions.upgrade');
            Route::post('extensions/{vendor}/{name}/reset', [AppController::class, 'reset'])->name('extensions.reset');
            Route::delete('extensions/{vendor}/{name}', [AppController::class, 'destroy'])->name('extensions.destroy');
            Route::delete('extensions/{vendor}/{name}/files', [AppController::class, 'delete'])->name('extensions.delete');
            Route::get('extensions/{vendor}/{name}/download-log', [ModuleHostController::class, 'downloadLog'])->name('extensions.download-log');

            // Legacy Stripe module URLs → Weblytic Extensions host page
            Route::redirect('modules/community/wtsstripe/settings', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('modules/community/wtsstripe/transactions', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('apps/community/wtsstripe', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('extensions/community/wtsstripe', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('extensions/community/stripe-all-in-one', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('modules/community/stripe-all-in-one/{page}', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('apps/community/stripe-all-in-one', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('modules/weblytic/wtsstripe/settings', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('modules/weblytic/wtsstripe/transactions', '/admin/extensions/weblytic/wtsstripe');
            Route::redirect('apps/weblytic/wtsstripe', '/admin/extensions/weblytic/wtsstripe');
        });

        Route::get('modules/{vendor}/{name}/lookup/{source}', [ModuleHostController::class, 'lookup'])->name('modules.lookup');
        Route::middleware('permission:apps.manage')->group(function () {
            Route::get('modules/{vendor}/{name}/{page}', [ModuleHostController::class, 'show'])->name('modules.show');
            Route::post('modules/{vendor}/{name}/{page}', [ModuleHostController::class, 'store'])->name('modules.store');
        });

        Route::post('shop-context', [ShopController::class, 'switchContext'])->name('shop-context');

        Route::middleware('permission:settings.store')->group(function () {
            Route::get('general', [StoreSettingsController::class, 'general'])->name('store.general');
            Route::get('store', [StoreSettingsController::class, 'edit'])->name('store.edit');
            Route::put('store', [StoreSettingsController::class, 'update'])->name('store.update');
            Route::post('store/multishop', [StoreSettingsController::class, 'toggleMultishop'])->name('store.multishop');
            Route::post('store/multivendor', [StoreSettingsController::class, 'toggleMultivendor'])->name('store.multivendor');
            Route::post('store/community', [StoreSettingsController::class, 'toggleCommunity'])->name('store.community');
            Route::post('shop/community', [StoreSettingsController::class, 'toggleCommunity'])->name('shop.community');
            Route::post('store/admin-path', [StoreSettingsController::class, 'updateAdminPath'])->name('store.admin-path');
            Route::put('store/sessions', [StoreSettingsController::class, 'updateSessions'])->name('store.sessions');
            Route::put('store/search-shortcut', [StoreSettingsController::class, 'updateSearchShortcut'])->name('store.search-shortcut');
            Route::post('store/purpose', [StoreSettingsController::class, 'applyPurpose'])->name('store.purpose');
        });

        Route::middleware('permission:settings.urls')->group(function () {
            Route::get('urls', [UrlSettingsController::class, 'edit'])->name('urls.edit');
            Route::put('urls', [UrlSettingsController::class, 'update'])->name('urls.update');
            Route::post('urls/regenerate', [UrlSettingsController::class, 'regenerate'])->name('urls.regenerate');
        });

        Route::middleware('permission:settings.seo')->group(function () {
            Route::get('seo', [SeoSettingsController::class, 'edit'])->name('seo.edit');
            Route::put('seo', [SeoSettingsController::class, 'update'])->name('seo.update');
            Route::post('seo/fix', [SeoSettingsController::class, 'fix'])->name('seo.fix');
            Route::post('seo/rollback', [SeoSettingsController::class, 'rollback'])->name('seo.rollback');
            Route::patch('seo/pages/{type}/{id}', [SeoSettingsController::class, 'updatePage'])->name('seo.pages.update');
        });

        Route::middleware('permission:settings.ai')->group(function () {
            Route::get('ai', [AiSettingsController::class, 'edit'])->name('ai.edit');
            Route::put('ai', [AiSettingsController::class, 'update'])->name('ai.update');
            Route::post('ai/test', [AiSettingsController::class, 'test'])->name('ai.test');
            Route::post('ai/connections', [AiSettingsController::class, 'storeConnection'])->name('ai.connections.store');
            Route::post('ai/connections/bulk-delete', [AiSettingsController::class, 'destroyConnections'])->name('ai.connections.bulk-delete');
            Route::put('ai/connections/{connection}', [AiSettingsController::class, 'updateConnection'])->name('ai.connections.update');
            Route::delete('ai/connections/{connection}', [AiSettingsController::class, 'destroyConnection'])->name('ai.connections.destroy');
            Route::post('ai/connections/{connection}/activate', [AiSettingsController::class, 'activateConnection'])->name('ai.connections.activate');
            Route::put('ai/assistant', [AiAssistantController::class, 'updateSettings'])->name('ai.assistant.update');
            Route::put('ai/storefront-assistant', [AiCustomerAssistantController::class, 'updateSettings'])->name('ai.storefront.update');
            Route::get('ai/logs', [AiAssistantController::class, 'logs'])->name('ai.logs');
            Route::get('ai/logs/export', [AiAssistantController::class, 'exportLogs'])->name('ai.logs.export');
            Route::post('ai/logs/bulk-delete', [AiAssistantController::class, 'bulkDestroyLogs'])->name('ai.logs.bulk-delete');
            Route::get('ai/logs/{chat}', [AiAssistantController::class, 'showLogChat'])->name('ai.logs.show');
            Route::delete('ai/logs/{chat}', [AiAssistantController::class, 'destroyLogChat'])->name('ai.logs.destroy');
            Route::get('ai/customer-chats', [AiCustomerAssistantController::class, 'index'])->name('ai.customer-chats');
            Route::get('ai/customer-chats/export', [AiCustomerAssistantController::class, 'exportCustomerChats'])->name('ai.customer-chats.export');
            Route::post('ai/customer-chats/bulk-delete', [AiCustomerAssistantController::class, 'bulkDestroyCustomerChats'])->name('ai.customer-chats.bulk-delete');
            Route::get('ai/customer-chats/{chat}', [AiCustomerAssistantController::class, 'show'])->name('ai.customer-chats.show');
            Route::delete('ai/customer-chats/{chat}', [AiCustomerAssistantController::class, 'destroy'])->name('ai.customer-chats.destroy');
        });

        // Field content assist + floating admin assistant — any signed-in admin.
        // Throttled: every call spends LLM budget.
        Route::middleware('throttle:60,1')->group(function () {
            Route::post('ai/content', [AiSettingsController::class, 'content'])->name('ai.content');
            Route::post('ai/assistant/ask', [AiAssistantController::class, 'ask'])->name('ai.assistant.ask');
            Route::post('ai/assistant/mutate', [AiAssistantController::class, 'mutate'])->name('ai.assistant.mutate');
            Route::post('ai/assistant/export-csv', [AiAssistantController::class, 'exportCsv'])->name('ai.assistant.export');
        });
        Route::get('ai/assistant/chats', [AiAssistantController::class, 'chats'])->name('ai.assistant.chats');
        Route::post('ai/assistant/chats', [AiAssistantController::class, 'storeChat'])->name('ai.assistant.chats.store');
        Route::get('ai/assistant/chats/{chat}', [AiAssistantController::class, 'showChat'])->name('ai.assistant.chats.show');
        Route::delete('ai/assistant/chats/{chat}', [AiAssistantController::class, 'destroyChat'])->name('ai.assistant.chats.destroy');

        Route::middleware('permission:settings.images')->group(function () {
            Route::get('images', [ImageSettingsController::class, 'edit'])->name('images.edit');
            Route::put('images', [ImageSettingsController::class, 'update'])->name('images.update');
            Route::post('images/regenerate', [ImageSettingsController::class, 'regenerate'])->name('images.regenerate');
        });

        Route::middleware('permission:settings.products')->group(function () {
            Route::get('product-settings', [ProductSettingsController::class, 'edit'])->name('product-settings.edit');
            Route::put('product-settings', [ProductSettingsController::class, 'update'])->name('product-settings.update');
        });

        Route::middleware('permission:settings.orders')->group(function () {
            Route::get('order-settings', [OrderSettingsController::class, 'edit'])->name('order-settings.edit');
            Route::put('order-settings', [OrderSettingsController::class, 'update'])->name('order-settings.update');
        });
        Route::middleware('permission:settings.customers')->group(function () {
            Route::get('customer-settings', [CustomerSettingsController::class, 'edit'])->name('customer-settings.edit');
            Route::put('customer-settings', [CustomerSettingsController::class, 'update'])->name('customer-settings.update');
        });
        Route::middleware('permission:settings.gdpr')->group(function () {
            Route::get('gdpr', [GdprSettingsController::class, 'edit'])->name('gdpr.edit');
            Route::put('gdpr', [GdprSettingsController::class, 'update'])->name('gdpr.update');
        });
        Route::middleware('permission:customers.gdpr')->group(function () {
            Route::get('gdpr-requests/export', [GdprRequestController::class, 'export'])->name('gdpr-requests.export');
            Route::get('gdpr-requests', [GdprRequestController::class, 'index'])->name('gdpr-requests.index');
            Route::get('gdpr-requests/{gdprRequest}', [GdprRequestController::class, 'edit'])->name('gdpr-requests.edit');
            Route::put('gdpr-requests/{gdprRequest}', [GdprRequestController::class, 'update'])->name('gdpr-requests.update');
            Route::delete('gdpr-requests/{gdprRequest}', [GdprRequestController::class, 'destroy'])->name('gdpr-requests.destroy');
        });
        Route::middleware('permission:settings.cart')->group(function () {
            Route::get('cart-settings', [CommerceSettingsController::class, 'editCart'])->name('cart-settings.edit');
            Route::put('cart-settings', [CommerceSettingsController::class, 'updateCart'])->name('cart-settings.update');
        });
        Route::middleware('permission:settings.inventory')->group(function () {
            Route::get('inventory-settings', [CommerceSettingsController::class, 'editInventory'])->name('inventory-settings.edit');
            Route::put('inventory-settings', [CommerceSettingsController::class, 'updateInventory'])->name('inventory-settings.update');
        });
        Route::middleware('permission:settings.pricing')->group(function () {
            Route::get('pricing-settings', [CommerceSettingsController::class, 'editPricing'])->name('pricing-settings.edit');
            Route::put('pricing-settings', [CommerceSettingsController::class, 'updatePricing'])->name('pricing-settings.update');
        });
        Route::middleware('permission:settings.returns')->group(function () {
            Route::get('return-settings', [CommerceSettingsController::class, 'editReturns'])->name('return-settings.edit');
            Route::put('return-settings', [CommerceSettingsController::class, 'updateReturns'])->name('return-settings.update');
        });
        Route::middleware('permission:settings.search')->group(function () {
            Route::get('search-settings', [CommerceSettingsController::class, 'editSearch'])->name('search-settings.edit');
            Route::put('search-settings', [CommerceSettingsController::class, 'updateSearch'])->name('search-settings.update');
            Route::post('search-settings/meilisearch-ping', [CommerceSettingsController::class, 'pingMeilisearch'])->name('search-settings.meilisearch-ping');
            Route::post('search-settings/elasticsearch-ping', [CommerceSettingsController::class, 'pingElasticsearch'])->name('search-settings.elasticsearch-ping');
        });
        Route::middleware('permission:settings.reviews')->group(function () {
            Route::get('review-settings', [CommerceSettingsController::class, 'editReviews'])->name('review-settings.edit');
            Route::put('review-settings', [CommerceSettingsController::class, 'updateReviews'])->name('review-settings.update');
        });
        Route::middleware('permission:settings.document-rules')->group(function () {
            Route::get('document-settings', [CommerceSettingsController::class, 'editDocuments'])->name('document-settings.edit');
            Route::put('document-settings', [CommerceSettingsController::class, 'updateDocuments'])->name('document-settings.update');
        });

        Route::middleware('permission:settings.performance')->group(function () {
            Route::get('performance', [PerformanceSettingsController::class, 'edit'])->name('performance.edit');
            Route::put('performance', [PerformanceSettingsController::class, 'update'])->name('performance.update');
            Route::put('performance/debug', [PerformanceSettingsController::class, 'updateDebug'])->name('performance.debug');
            Route::post('performance/refresh-storefront', [PerformanceSettingsController::class, 'refreshStorefront'])->name('performance.refresh-storefront');
            Route::post('performance/clear', [PerformanceSettingsController::class, 'clear'])->name('performance.clear');
            Route::post('performance/benchmark', [PerformanceSettingsController::class, 'runBenchmark'])->name('performance.benchmark');
        });

        Route::middleware('permission:settings.advanced')->group(function () {
            Route::get('advanced', [AdvancedSettingsController::class, 'edit'])->name('advanced.edit');
            Route::put('advanced/profiler', [AdvancedSettingsController::class, 'updateProfiler'])->name('advanced.profiler');
            Route::put('advanced/jump', [AdvancedSettingsController::class, 'updateJump'])->name('advanced.jump');
            Route::put('advanced/maintenance', [AdvancedSettingsController::class, 'updateMaintenance'])->name('advanced.maintenance');
            Route::post('advanced/maintenance/preview', [AdvancedSettingsController::class, 'previewMaintenance'])->name('advanced.maintenance.preview');
            Route::post('advanced/demo/install', [AdvancedSettingsController::class, 'installDemo'])->name('advanced.demo.install');
            Route::post('advanced/demo/remove', [AdvancedSettingsController::class, 'removeDemo'])->name('advanced.demo.remove');
            Route::post('advanced/demo/millions', [AdvancedSettingsController::class, 'installMillions'])->name('advanced.demo.millions');
            Route::get('advanced/email-logs/export', [AdvancedSettingsController::class, 'exportEmailLogs'])->name('advanced.email-logs.export');
            Route::post('advanced/queries/run', [AdvancedSettingsController::class, 'runQuery'])->name('advanced.queries.run');
            Route::post('advanced/queries', [AdvancedSettingsController::class, 'saveQuery'])->name('advanced.queries.store');
            Route::delete('advanced/queries/{savedQuery}', [AdvancedSettingsController::class, 'destroyQuery'])->name('advanced.queries.destroy');

            Route::get('platform-upgrade/status', [PlatformUpgradeController::class, 'status'])->name('platform-upgrade.status');
            Route::post('platform-upgrade/backup', [PlatformUpgradeController::class, 'backup'])->name('platform-upgrade.backup');
            Route::post('platform-upgrade/apply', [PlatformUpgradeController::class, 'apply'])->name('platform-upgrade.apply');
            Route::post('platform-upgrade/restore', [PlatformUpgradeController::class, 'restore'])->name('platform-upgrade.restore');
            Route::get('platform-upgrade/backups/{backup}/download/{file}', [PlatformUpgradeController::class, 'download'])->name('platform-upgrade.download');
            Route::delete('platform-upgrade/backups/{backup}', [PlatformUpgradeController::class, 'destroyBackup'])->name('platform-upgrade.destroy-backup');
        });

        Route::middleware('permission:settings.shops')->group(function () {
            Route::get('shops', [ShopController::class, 'index'])->name('shops.index');
            Route::get('shops/create', [ShopController::class, 'create'])->name('shops.create');
            Route::post('shops', [ShopController::class, 'store'])->name('shops.store');
            Route::post('shops/default', [ShopController::class, 'setDefault'])->name('shops.default');
            Route::post('shops/{shop}/default', [ShopController::class, 'makeDefault'])->name('shops.make-default');
            Route::post('shops/{shop}/toggle', [ShopController::class, 'toggle'])->name('shops.toggle');
            Route::get('shops/{shop}/edit', [ShopController::class, 'edit'])->name('shops.edit');
            Route::put('shops/{shop}', [ShopController::class, 'update'])->name('shops.update');
            Route::delete('shops/{shop}', [ShopController::class, 'destroy'])->name('shops.destroy');
            Route::post('shop-groups', [ShopGroupController::class, 'store'])->name('shop-groups.store');
            Route::put('shop-groups/{shopGroup}', [ShopGroupController::class, 'update'])->name('shop-groups.update');
            Route::delete('shop-groups/{shopGroup}', [ShopGroupController::class, 'destroy'])->name('shop-groups.destroy');
        });

        Route::middleware('permission:localization.manage')->group(function () {
            Route::get('localization', [LocalizationController::class, 'index'])->name('localization.index');
            Route::post('localization/defaults', [LocalizationController::class, 'saveDefaults'])->name('localization.defaults');
            Route::get('localization/packs', [LocalizationController::class, 'packs'])->name('localization.packs');
            Route::post('localization/packs', [LocalizationController::class, 'applyPack'])->name('localization.packs.apply');
            Route::get('localization/import', [LocalizationImportController::class, 'index'])->name('localization.import');
            Route::get('localization/export', [LocalizationImportController::class, 'export'])->name('localization.export');
            Route::post('localization/import', [LocalizationImportController::class, 'stage'])->name('localization.import.stage');
            Route::post('localization/import/{batch}/apply', [LocalizationImportController::class, 'apply'])->name('localization.import.apply');
        });

        Route::middleware('permission:localization.countries')->group(function () {
            Route::get('localization/countries/export', [CountryController::class, 'export'])->name('localization.countries.export');
            Route::post('localization/countries/import', [CountryController::class, 'import'])->name('localization.countries.import');
            Route::get('localization/countries/import/sample', [CountryController::class, 'importSample'])->name('localization.countries.import.sample');
            Route::get('localization/zones', [ShippingMethodController::class, 'zones'])->name('localization.zones');
            Route::get('localization/zones/export', [ShippingMethodController::class, 'exportZones'])->name('localization.zones.export');
            Route::post('localization/zones/import', [ShippingMethodController::class, 'importZones'])->name('localization.zones.import');
            Route::get('localization/zones/import/sample', [ShippingMethodController::class, 'importZonesSample'])->name('localization.zones.import.sample');
            Route::post('localization/zones', [ShippingMethodController::class, 'storeZone'])->name('localization.zones.store');
            Route::post('localization/zones/bulk-delete', [ShippingMethodController::class, 'bulkDestroyZones'])->name('localization.zones.bulk-destroy');
            Route::post('localization/zones/bulk-status', [ShippingMethodController::class, 'bulkStatusZones'])->name('localization.zones.bulk-status');
            Route::post('localization/zones/{shippingZone}/toggle', [ShippingMethodController::class, 'toggleZone'])->name('localization.zones.toggle');
            Route::put('localization/zones/{shippingZone}', [ShippingMethodController::class, 'updateZone'])->name('localization.zones.update');
            Route::delete('localization/zones/{shippingZone}', [ShippingMethodController::class, 'destroyZone'])->name('localization.zones.destroy');
            Route::get('localization/countries', [CountryController::class, 'index'])->name('localization.countries.index');
            Route::get('localization/countries/create', [CountryController::class, 'create'])->name('localization.countries.create');
            Route::post('localization/countries', [CountryController::class, 'store'])->name('localization.countries.store');
            Route::get('localization/countries/{country}/edit', [CountryController::class, 'edit'])->name('localization.countries.edit');
            Route::put('localization/countries/{country}', [CountryController::class, 'update'])->name('localization.countries.update');
            Route::post('localization/countries/{country}/toggle', [CountryController::class, 'toggle'])->name('localization.countries.toggle');
            Route::post('localization/countries/{country}/default', [CountryController::class, 'makeDefault'])->name('localization.countries.default');

            Route::get('localization/states/export', [CountryRegionController::class, 'export'])->name('localization.states.export');
            Route::post('localization/states/import', [CountryRegionController::class, 'import'])->name('localization.states.import');
            Route::get('localization/states/import/sample', [CountryRegionController::class, 'importSample'])->name('localization.states.import.sample');
            Route::get('localization/states', [CountryRegionController::class, 'index'])->name('localization.states.index');
            Route::post('localization/states/{countryRegion}/toggle', [CountryRegionController::class, 'toggle'])->name('localization.states.toggle');
        });

        Route::middleware('permission:localization.currencies')->group(function () {
            Route::get('localization/currencies/export', [CurrencyController::class, 'export'])->name('localization.currencies.export');
            Route::post('localization/currencies/import', [CurrencyController::class, 'import'])->name('localization.currencies.import');
            Route::get('localization/currencies/import/sample', [CurrencyController::class, 'importSample'])->name('localization.currencies.import.sample');
            Route::get('localization/currencies', [CurrencyController::class, 'index'])->name('localization.currencies.index');
            Route::get('localization/currencies/create', [CurrencyController::class, 'create'])->name('localization.currencies.create');
            Route::post('localization/currencies', [CurrencyController::class, 'store'])->name('localization.currencies.store');
            Route::get('localization/currencies/{currency}/edit', [CurrencyController::class, 'edit'])->name('localization.currencies.edit');
            Route::put('localization/currencies/{currency}', [CurrencyController::class, 'update'])->name('localization.currencies.update');
            Route::post('localization/currencies/{currency}/toggle', [CurrencyController::class, 'toggle'])->name('localization.currencies.toggle');
            Route::post('localization/currencies/{currency}/default', [CurrencyController::class, 'makeDefault'])->name('localization.currencies.default');
        });

        Route::middleware('permission:localization.languages')->group(function () {
            Route::get('localization/languages/export', [LanguageController::class, 'export'])->name('localization.languages.export');
            Route::post('localization/languages/import', [LanguageController::class, 'import'])->name('localization.languages.import');
            Route::get('localization/languages/import/sample', [LanguageController::class, 'importSample'])->name('localization.languages.import.sample');
            Route::get('localization/languages', [LanguageController::class, 'index'])->name('localization.languages.index');
            Route::get('localization/languages/create', [LanguageController::class, 'create'])->name('localization.languages.create');
            Route::post('localization/languages', [LanguageController::class, 'store'])->name('localization.languages.store');
            Route::get('localization/languages/{language}/edit', [LanguageController::class, 'edit'])->name('localization.languages.edit');
            Route::put('localization/languages/{language}', [LanguageController::class, 'update'])->name('localization.languages.update');
            Route::post('localization/languages/{language}/toggle', [LanguageController::class, 'toggle'])->name('localization.languages.toggle');
            Route::post('localization/languages/{language}/default', [LanguageController::class, 'makeDefault'])->name('localization.languages.default');
        });

        Route::middleware('permission:localization.translations')->group(function () {
            Route::get('localization/translations', [UiTranslationController::class, 'index'])->name('localization.translations.index');
            Route::put('localization/translations', [UiTranslationController::class, 'update'])->name('localization.translations.update');
        });

        Route::middleware('permission:taxes.manage')->group(function () {
            Route::get('tax/export', [TaxController::class, 'export'])->name('tax.export');
            Route::post('tax/import', [TaxController::class, 'import'])->name('tax.import');
            Route::get('tax/import/sample', [TaxController::class, 'importSample'])->name('tax.import.sample');
            Route::get('tax', [TaxController::class, 'index'])->name('tax.index');
            Route::post('tax/classes', [TaxController::class, 'storeClass'])->name('tax.classes.store');
            Route::put('tax/classes/{taxClass}', [TaxController::class, 'updateClass'])->name('tax.classes.update');
            Route::delete('tax/classes/{taxClass}', [TaxController::class, 'destroyClass'])->name('tax.classes.destroy');
            Route::post('tax/classes/bulk-delete', [TaxController::class, 'bulkDestroyClasses'])->name('tax.classes.bulk-destroy');
            Route::post('tax/zones', [TaxController::class, 'storeZone'])->name('tax.zones.store');
            Route::put('tax/zones/{taxZone}', [TaxController::class, 'updateZone'])->name('tax.zones.update');
            Route::delete('tax/zones/{taxZone}', [TaxController::class, 'destroyZone'])->name('tax.zones.destroy');
            Route::post('tax/zones/bulk-delete', [TaxController::class, 'bulkDestroyZones'])->name('tax.zones.bulk-destroy');
            Route::post('tax/zones/bulk-status', [TaxController::class, 'bulkStatusZones'])->name('tax.zones.bulk-status');
            Route::post('tax/zones/{taxZone}/toggle', [TaxController::class, 'toggleZone'])->name('tax.zones.toggle');
            Route::post('tax/rates', [TaxController::class, 'storeRate'])->name('tax.rates.store');
            Route::put('tax/rates/{taxRate}', [TaxController::class, 'updateRate'])->name('tax.rates.update');
            Route::delete('tax/rates/{taxRate}', [TaxController::class, 'destroyRate'])->name('tax.rates.destroy');
            Route::post('tax/rates/bulk-delete', [TaxController::class, 'bulkDestroyRates'])->name('tax.rates.bulk-destroy');
            Route::post('tax/rates/bulk-status', [TaxController::class, 'bulkStatusRates'])->name('tax.rates.bulk-status');
            Route::post('tax/rates/{taxRate}/toggle', [TaxController::class, 'toggleRate'])->name('tax.rates.toggle');
            Route::post('tax/rules', [TaxController::class, 'storeRule'])->name('tax.rules.store');
            Route::put('tax/rules/{taxRule}', [TaxController::class, 'updateRule'])->name('tax.rules.update');
            Route::delete('tax/rules/{taxRule}', [TaxController::class, 'destroyRule'])->name('tax.rules.destroy');
            Route::post('tax/rules/bulk-delete', [TaxController::class, 'bulkDestroyRules'])->name('tax.rules.bulk-destroy');
            Route::post('tax/rules/bulk-status', [TaxController::class, 'bulkStatusRules'])->name('tax.rules.bulk-status');
            Route::post('tax/rules/{taxRule}/toggle', [TaxController::class, 'toggleRule'])->name('tax.rules.toggle');
            Route::post('tax/packs', [TaxController::class, 'applyPack'])->name('tax.packs.apply');
        });

        Route::middleware('permission:settings.email')->group(function () {
            Route::get('email', [EmailTemplateController::class, 'index'])->name('email.index');
            Route::put('email/events', [EmailTemplateController::class, 'saveEvents'])->name('email.events');
            Route::put('email/smtp', [EmailTemplateController::class, 'saveSmtp'])->name('email.smtp');
            Route::post('email/smtp/test', [EmailTemplateController::class, 'sendTest'])->name('email.smtp.test');
            Route::get('email/templates/create', [EmailTemplateController::class, 'create'])->name('email.create');
            Route::post('email/templates', [EmailTemplateController::class, 'store'])->name('email.store');
            Route::post('email/templates/bulk-status', [EmailTemplateController::class, 'bulkStatus'])->name('email.bulk-status');
            Route::post('email/templates/bulk-delete', [EmailTemplateController::class, 'bulkDestroy'])->name('email.bulk-destroy');
            Route::post('email/templates/bulk-duplicate', [EmailTemplateController::class, 'bulkDuplicate'])->name('email.bulk-duplicate');
            Route::post('email/templates/{emailTemplate}/toggle', [EmailTemplateController::class, 'toggle'])->name('email.toggle');
            Route::get('email/templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])->name('email.edit');
            Route::put('email/templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('email.update');
            Route::post('email/templates/{emailTemplate}/duplicate', [EmailTemplateController::class, 'duplicate'])->name('email.duplicate');
            Route::delete('email/templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->name('email.destroy');
        });

        Route::middleware('permission:settings.documents')->group(function () {
            Route::get('documents', [DocumentTemplateController::class, 'index'])->name('documents.index');
            Route::get('documents/create', [DocumentTemplateController::class, 'create'])->name('documents.create');
            Route::post('documents', [DocumentTemplateController::class, 'store'])->name('documents.store');
            Route::post('documents/bulk-status', [DocumentTemplateController::class, 'bulkStatus'])->name('documents.bulk-status');
            Route::post('documents/bulk-delete', [DocumentTemplateController::class, 'bulkDestroy'])->name('documents.bulk-destroy');
            Route::post('documents/bulk-duplicate', [DocumentTemplateController::class, 'bulkDuplicate'])->name('documents.bulk-duplicate');
            Route::post('documents/{documentTemplate}/toggle', [DocumentTemplateController::class, 'toggle'])->name('documents.toggle');
            Route::post('documents/{documentTemplate}/use', [DocumentTemplateController::class, 'useTemplate'])->name('documents.use');
            Route::get('documents/{documentTemplate}/edit', [DocumentTemplateController::class, 'edit'])->name('documents.edit');
            Route::put('documents/{documentTemplate}', [DocumentTemplateController::class, 'update'])->name('documents.update');
            Route::post('documents/{documentTemplate}/duplicate', [DocumentTemplateController::class, 'duplicate'])->name('documents.duplicate');
            Route::delete('documents/{documentTemplate}', [DocumentTemplateController::class, 'destroy'])->name('documents.destroy');
        });

        Route::get('roles', fn () => redirect()->route('admin.staff.profiles.index'))->name('roles.index');

        Route::middleware('super_admin')->prefix('staff')->name('staff.')->group(function () {
            Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
            Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
            Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
            Route::post('employees/bulk-delete', [EmployeeController::class, 'bulkDestroy'])->name('employees.bulk-destroy');
            Route::post('employees/{admin}/toggle', [EmployeeController::class, 'toggle'])->name('employees.toggle');
            Route::get('employees/{admin}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
            Route::put('employees/{admin}', [EmployeeController::class, 'update'])->name('employees.update');
            Route::delete('employees/{admin}', [EmployeeController::class, 'destroy'])->name('employees.destroy');

            Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
            Route::get('teams/create', [TeamController::class, 'create'])->name('teams.create');
            Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
            Route::post('teams/bulk-delete', [TeamController::class, 'bulkDestroy'])->name('teams.bulk-destroy');
            Route::post('teams/{team}/toggle', [TeamController::class, 'toggle'])->name('teams.toggle');
            Route::get('teams/{team}/edit', [TeamController::class, 'edit'])->name('teams.edit');
            Route::put('teams/{team}', [TeamController::class, 'update'])->name('teams.update');
            Route::delete('teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');

            Route::get('profiles', [ProfileController::class, 'index'])->name('profiles.index');
            Route::get('profiles/create', [ProfileController::class, 'create'])->name('profiles.create');
            Route::post('profiles', [ProfileController::class, 'store'])->name('profiles.store');
            Route::post('profiles/bulk-delete', [ProfileController::class, 'bulkDestroy'])->name('profiles.bulk-destroy');
            Route::post('profiles/{profile}/toggle', [ProfileController::class, 'toggle'])->name('profiles.toggle');
            Route::get('profiles/{profile}/edit', [ProfileController::class, 'edit'])->name('profiles.edit');
            Route::put('profiles/{profile}', [ProfileController::class, 'update'])->name('profiles.update');
            Route::delete('profiles/{profile}', [ProfileController::class, 'destroy'])->name('profiles.destroy');

            Route::redirect('roles', '/admin/staff/profiles');
            Route::redirect('permissions', '/admin/staff/profiles');
        });
    });
});

Route::get('/api.json', \App\Http\Controllers\Api\ApiLandingController::class)
    ->middleware('throttle:60,1');

Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/sitemap/{name}.xml', [SeoController::class, 'sitemapChunk'])->where('name', 'pages|product-[0-9]+|category-[0-9]+|cms-[0-9]+|brand-[0-9]+')->name('sitemap.chunk');
Route::get('/feeds/google-merchant.xml', [SeoController::class, 'googleMerchant'])->name('feeds.google-merchant');
Route::get('/pay/{driver}/{number}', [HostedPayController::class, 'show'])->middleware(['signed', 'throttle:30,1'])->name('pay.show');
Route::post('/pay/{driver}/{number}', [HostedPayController::class, 'confirm'])->middleware(['signed', 'throttle:10,1'])->name('pay.confirm');
Route::get('/integrations/tokens/{token}/revoke', [\App\Http\Controllers\IntegrationTokenRevokeController::class, 'confirm'])
    ->middleware(['signed', 'throttle:30,1'])
    ->whereNumber('token')
    ->name('integrations.token.revoke.confirm');
Route::post('/integrations/tokens/{token}/revoke', [\App\Http\Controllers\IntegrationTokenRevokeController::class, 'submit'])
    ->middleware(['signed', 'throttle:10,1'])
    ->whereNumber('token')
    ->name('integrations.token.revoke.submit');

Route::get('/download/attachment/{attachment}/preview', [AttachmentDownloadController::class, 'preview'])->name('attachments.preview');
Route::get('/download/attachment/{attachment}', AttachmentDownloadController::class)->name('attachments.download');
Route::get('/themes/{theme}/{path}', ThemeAssetController::class)->where('path', '.*')->name('theme.asset');
// Logos are static files. Skip the admin boot so a page of cards does not boot every extension once per image.
Route::get('/plugins/{vendor}/{name}/{path}', PluginAssetController::class)
    ->where('path', '.*')
    ->withoutMiddleware([
        \App\Http\Middleware\BootPlugins::class,
        \App\Http\Middleware\HandleInertiaRequests::class,
        \App\Http\Middleware\CaptureProfiler::class,
        \App\Http\Middleware\MarkAdminListToast::class,
        \App\Http\Middleware\ShopMaintenance::class,
    ])
    ->name('plugin.asset');

Route::get('/clone/{path?}', CloneHomeController::class)->where('path', '.*')->name('clone.home');

Route::middleware('throttle:20,1')->group(function () {
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook', 'linkedin'])
        ->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook', 'linkedin'])
        ->name('social.callback');
});

Route::middleware('throttle:60,1')->prefix('webmcp')->name('webmcp.')->group(function () {
    Route::get('/product', [WebMcpController::class, 'product'])->name('product');
    Route::get('/wishlist/add', [WebMcpController::class, 'addToWishlist'])->name('wishlist.add');
    Route::post('/newsletter', [WebMcpController::class, 'subscribeNewsletter'])->name('newsletter');
});

$adminSegment = (string) config('wento.admin_prefix', 'admin');
$adminSegment = strcasecmp($adminSegment, 'admin') === 0 ? '' : '|'.preg_quote($adminSegment, '/');
Route::get('/{path?}', StorefrontController::class)->where('path', '^(?!admin|api|install|installed|up|storage|build|setup|sanctum|sitemap|robots\\.txt|feeds|themes|plugins|clone|download|pay|auth|webmcp'.$adminSegment.').*$')->name('storefront');
