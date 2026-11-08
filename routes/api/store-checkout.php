<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\StorefrontAiController;
    Route::get('/ai/assistant/config', [StorefrontAiController::class, 'config']);
    Route::get('/ai/assistant/terms', [StorefrontAiController::class, 'terms']);
    Route::get('/ai/assistant/chats', [StorefrontAiController::class, 'chats']);
    Route::post('/ai/assistant/chats', [StorefrontAiController::class, 'storeChat'])->middleware('throttle:20,1');
    Route::get('/ai/assistant/chats/{chat}', [StorefrontAiController::class, 'showChat']);
    Route::delete('/ai/assistant/chats/{chat}', [StorefrontAiController::class, 'destroyChat']);
    Route::post('/ai/assistant/ask', [StorefrontAiController::class, 'ask'])->middleware('throttle:8,1');

    Route::get('/checkout/options', [CheckoutController::class, 'options'])->middleware('throttle:60,1');
    Route::get('/checkout/carrier-rates', [CheckoutController::class, 'carrierRates'])->middleware('throttle:60,1');
    Route::get('/cart', [CheckoutController::class, 'cart'])->middleware('throttle:60,1');
    Route::post('/cart', [CheckoutController::class, 'add'])->middleware('throttle:30,1');
    Route::patch('/cart/items/{item}', [CheckoutController::class, 'updateItem'])->middleware('throttle:30,1');
    Route::delete('/cart/items/{item}', [CheckoutController::class, 'removeItem'])->middleware('throttle:30,1');
    Route::post('/cart/coupon', [CheckoutController::class, 'coupon'])->middleware('throttle:20,1');
    Route::post('/cart/gift-card', [CheckoutController::class, 'giftCard'])->middleware('throttle:20,1');
    Route::delete('/cart/gift-card', [CheckoutController::class, 'removeGiftCard'])->middleware('throttle:20,1');
    Route::post('/cart/gift-wrap', [CheckoutController::class, 'giftWrap'])->middleware('throttle:20,1');
    Route::post('/cart/store-credit', [CheckoutController::class, 'storeCredit'])->middleware('throttle:20,1');
    Route::post('/cart/loyalty', [CheckoutController::class, 'loyalty'])->middleware('throttle:20,1');
    Route::match(['get', 'post'], '/cart/recover', [CheckoutController::class, 'recover'])->middleware('throttle:30,1');
    Route::get('/wishlist/shared/{token}', [ShopController::class, 'sharedWishlist'])->middleware('throttle:30,1');
    Route::post('/checkout', [CheckoutController::class, 'place'])->middleware('throttle:30,1');
    Route::post('/checkout/finalize', [CheckoutController::class, 'finalize'])->middleware('throttle:10,1');
    Route::get('/checkout/thanks/{number}', [CheckoutController::class, 'thanks'])->middleware('throttle:30,1');
    Route::post('/orders/lookup', [CheckoutController::class, 'lookup'])->middleware('throttle:8,10,guest-order-lookup');
    Route::post('/orders/invoice', [CheckoutController::class, 'guestInvoice'])->middleware('throttle:8,10,guest-order-invoice');
