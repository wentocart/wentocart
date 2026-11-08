<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
    Route::post('/payments/{driver}/webhook', PaymentWebhookController::class)->middleware('throttle:60,1');
