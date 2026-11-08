<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\WebserviceController;
use App\Http\Controllers\Api\V1\WebserviceResourcesController;
    Route::prefix('ws')->middleware('throttle:60,1')->group(function () {
        Route::get('/', [WebserviceController::class, 'index'])->middleware('webservice:shop.read');
        Route::get('/store', [WebserviceController::class, 'shop'])->middleware('webservice:shop.read');
        Route::get('/languages', [WebserviceResourcesController::class, 'languages'])->middleware('webservice:shop.read');
        Route::get('/currencies', [WebserviceResourcesController::class, 'currencies'])->middleware('webservice:shop.read');
        Route::get('/countries', [WebserviceResourcesController::class, 'countries'])->middleware('webservice:shop.read');
        Route::get('/countries/{country}', [WebserviceResourcesController::class, 'showCountry'])->middleware('webservice:shop.read');
        Route::get('/shipping-methods', [WebserviceResourcesController::class, 'shippingMethods'])->middleware('webservice:shop.read');
        Route::get('/cms-pages', [WebserviceResourcesController::class, 'cmsPages'])->middleware('webservice:shop.read');
        Route::get('/cms-pages/{page}', [WebserviceResourcesController::class, 'showCmsPage'])->middleware('webservice:shop.read');

        Route::get('/products', [WebserviceController::class, 'products'])->middleware('webservice:catalog.read');
        Route::post('/products', [WebserviceController::class, 'storeProduct'])->middleware('webservice:catalog.write');
        Route::get('/products/{product}', [WebserviceController::class, 'showProduct'])->middleware('webservice:catalog.read');
        Route::patch('/products/{product}', [WebserviceController::class, 'updateProduct'])->middleware('webservice:catalog.write');
        Route::delete('/products/{product}', [WebserviceController::class, 'destroyProduct'])->middleware('webservice:catalog.write');
        Route::get('/stock', [WebserviceResourcesController::class, 'stock'])->middleware('webservice:catalog.read');
        Route::get('/categories', [WebserviceResourcesController::class, 'categories'])->middleware('webservice:catalog.read');
        Route::get('/categories/{category}', [WebserviceResourcesController::class, 'showCategory'])->middleware('webservice:catalog.read');
        Route::get('/brands', [WebserviceResourcesController::class, 'brands'])->middleware('webservice:catalog.read');
        Route::get('/brands/{brand}', [WebserviceResourcesController::class, 'showBrand'])->middleware('webservice:catalog.read');
        Route::get('/tags', [WebserviceResourcesController::class, 'tags'])->middleware('webservice:catalog.read');
        Route::get('/tags/{tag}', [WebserviceResourcesController::class, 'showTag'])->middleware('webservice:catalog.read');
        Route::get('/suppliers', [WebserviceResourcesController::class, 'suppliers'])->middleware('webservice:catalog.read');
        Route::get('/suppliers/{supplier}', [WebserviceResourcesController::class, 'showSupplier'])->middleware('webservice:catalog.read');
        Route::get('/features', [WebserviceResourcesController::class, 'features'])->middleware('webservice:catalog.read');
        Route::get('/features/{feature}', [WebserviceResourcesController::class, 'showFeature'])->middleware('webservice:catalog.read');

        Route::get('/orders', [WebserviceController::class, 'orders'])->middleware('webservice:orders.read');
        Route::get('/orders/{order}', [WebserviceController::class, 'showOrder'])->middleware('webservice:orders.read');
        Route::patch('/orders/{order}/status', [WebserviceController::class, 'updateOrderStatus'])->middleware('webservice:orders.write');

        Route::get('/customers', [WebserviceController::class, 'customers'])->middleware('webservice:customers.read');
        Route::post('/customers', [WebserviceController::class, 'storeCustomer'])->middleware('webservice:customers.write');
        Route::get('/customers/{customer}/addresses', [WebserviceResourcesController::class, 'customerAddresses'])->middleware('webservice:customers.read');
        Route::get('/customers/{customer}', [WebserviceController::class, 'showCustomer'])->middleware('webservice:customers.read');
        Route::patch('/customers/{customer}', [WebserviceController::class, 'updateCustomer'])->middleware('webservice:customers.write');
        Route::get('/addresses', [WebserviceResourcesController::class, 'addresses'])->middleware('webservice:customers.read');
        Route::get('/addresses/{address}', [WebserviceResourcesController::class, 'showAddress'])->middleware('webservice:customers.read');
        Route::get('/customer-groups', [WebserviceResourcesController::class, 'customerGroups'])->middleware('webservice:customers.read');

        Route::get('/webhooks', [WebserviceController::class, 'webhooks'])->middleware('webservice:webhooks.manage');
        Route::post('/webhooks', [WebserviceController::class, 'storeWebhook'])->middleware('webservice:webhooks.manage');
        Route::delete('/webhooks/{webhook}', [WebserviceController::class, 'destroyWebhook'])->middleware('webservice:webhooks.manage');
    });
