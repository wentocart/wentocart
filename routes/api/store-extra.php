<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CmsController;
use App\Http\Controllers\Api\V1\I18nController;
use App\Http\Controllers\Api\V1\MarketplaceShopController;
use App\Http\Controllers\Api\V1\ModuleFrontController;
    Route::get('/marketplace/landing', [MarketplaceShopController::class, 'landing'])
        ->middleware(['marketplace', ...$storefrontKey, 'throttle:120,1']);
    Route::get('/store/marketplace/landing', [MarketplaceShopController::class, 'landing'])
        ->middleware(['marketplace', ...$storefrontKey, 'throttle:120,1']);
    Route::get('/catalog/seller/{slug}', [MarketplaceShopController::class, 'show'])->middleware(['marketplace', ...$storefrontKey, 'throttle:120,1']);
    Route::post('/catalog/seller/{slug}/contact', [MarketplaceShopController::class, 'contact'])->middleware(['marketplace', 'throttle:8,1']);
    Route::post('/catalog/seller/{slug}/flag', [MarketplaceShopController::class, 'flag'])->middleware(['marketplace', 'auth:customer', 'throttle:8,1']);
    Route::post('/catalog/products/{id}/flag', [MarketplaceShopController::class, 'flagProduct'])->middleware(['marketplace', 'auth:customer', 'throttle:8,1'])->whereNumber('id');
    Route::get('/catalog/products/{id}/seller-offers', [MarketplaceShopController::class, 'sellerOffers'])
        ->middleware(['marketplace', ...$storefrontKey, 'throttle:120,1'])
        ->whereNumber('id');
    Route::get('/store/products/{id}/seller-offers', [MarketplaceShopController::class, 'sellerOffers'])
        ->middleware(['marketplace', ...$storefrontKey, 'throttle:120,1'])
        ->whereNumber('id');
    Route::get('/catalog/pages', [CatalogController::class, 'pages'])->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/catalog/page/{slug}', [CatalogController::class, 'page'])->middleware([...$storefrontKey, 'throttle:120,1'])->where('slug', '.*');
    Route::get('/catalog/compare', [CatalogController::class, 'compare'])->middleware([...$storefrontKey, 'throttle:120,1']);
    Route::get('/catalog/resolve/{path}', [CatalogController::class, 'resolve'])->middleware([...$storefrontKey, 'throttle:120,1'])->where('path', '.*');
    Route::match(['get', 'post'], '/modules/{vendor}/{name}/{page}', [ModuleFrontController::class, 'show'])
        ->middleware('throttle:30,1');
    Route::get('/cms/blocks', [CmsController::class, 'blocks']);
    Route::get('/cms/menu/{code}', [CmsController::class, 'menu']);
    Route::get('/i18n/{locale}', [I18nController::class, 'show']);
    Route::post('/i18n/locale', [I18nController::class, 'switchLocale']);
    Route::post('/i18n/currency', [I18nController::class, 'switchCurrency']);
