<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SellerPortalController;
use App\Http\Controllers\Api\V1\SellerBookingsRestController;
use App\Http\Controllers\Api\V1\SellerStaffAuthController;
    // (outside auth:customer — staff use Bearer tokens from /seller/login)
    Route::post('/seller/login', [SellerStaffAuthController::class, 'login'])
        ->middleware(['marketplace', 'throttle:20,1']);
    Route::get('/seller/me', [SellerStaffAuthController::class, 'me'])
        ->middleware(['marketplace', 'throttle:120,1']);
    Route::post('/seller/logout', [SellerStaffAuthController::class, 'logout'])
        ->middleware(['marketplace', 'throttle:60,1']);
    Route::middleware(['marketplace', 'seller:dashboard'])->group(function () {
        Route::get('/seller/panel/dashboard', [SellerPortalController::class, 'dashboard']);
    });
    Route::middleware(['marketplace', 'seller:sales.orders'])->get('/seller/panel/orders', [SellerPortalController::class, 'orders']);
    Route::middleware(['marketplace', 'seller:catalog.products'])->get('/seller/panel/products', [SellerPortalController::class, 'products']);
    Route::middleware(['marketplace', 'seller:communications'])->get('/seller/panel/messages', [SellerPortalController::class, 'messages']);
    Route::middleware(['marketplace', 'seller:sales.orders'])->get('/seller/panel/returns', [SellerPortalController::class, 'returns']);
    Route::middleware(['marketplace', 'seller:customers.reviews'])->get('/seller/panel/reviews', [SellerPortalController::class, 'reviews']);
    Route::middleware(['marketplace', 'seller:sales.transactions'])->get('/seller/panel/earnings', [SellerPortalController::class, 'earnings']);
    Route::middleware(['marketplace', 'seller:sales.bookings'])->get('/seller/panel/bookings', [SellerBookingsRestController::class, 'index']);
