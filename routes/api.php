<?php

use App\Http\Controllers\Api\ApiLandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', ApiLandingController::class)->middleware('throttle:60,1');
Route::get('/json', ApiLandingController::class)->middleware('throttle:60,1');
// Wento API: unversioned aliases retired. Canonical surfaces live under /api/v1/store and /api/v1/console.

Route::prefix('v1')->group(function () {
    require __DIR__.'/api/graphql.php';
    require __DIR__.'/api/console.php';
    require __DIR__.'/api/store.php';
    require __DIR__.'/api/seller.php';
    require __DIR__.'/api/store-extra.php';
    require __DIR__.'/api/auth.php';
    require __DIR__.'/api/store-checkout.php';
    require __DIR__.'/api/payments.php';
    require __DIR__.'/api/ws.php';
    require __DIR__.'/api/customer.php';
});
