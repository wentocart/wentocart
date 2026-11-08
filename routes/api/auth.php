<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
    // Named buckets. A bare throttle shares one counter per IP, so catalog and cart
    // requests were using up the sign-in limit and the form showed "Invalid credentials".
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware(['throttle:5,1,customer-register', 'honeypot']);
    Route::get('/auth/register-options', [AuthController::class, 'registerOptions']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1,customer-login');
    Route::post('/auth/mfa', [AuthController::class, 'verifyMfa'])->middleware('throttle:8,1,customer-mfa');
    Route::post('/auth/passkey/options', [AuthController::class, 'passkeyOptions'])->middleware('throttle:8,1,customer-passkey');
    Route::post('/auth/passkey/assert', [AuthController::class, 'passkeyAssert'])->middleware('throttle:8,1,customer-passkey');
    Route::post('/auth/sms/send', [AuthController::class, 'sendSms'])->middleware('throttle:5,1,customer-sms');
    Route::post('/auth/sms', [AuthController::class, 'verifySms'])->middleware('throttle:8,1,customer-sms');
    Route::post('/auth/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:10,1,customer-otp');
    Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1,customer-otp');
    Route::post('/auth/forgot', [AuthController::class, 'forgot'])->middleware('throttle:5,1,customer-forgot');
    Route::post('/auth/reset', [AuthController::class, 'reset'])->middleware('throttle:5,1,customer-reset');
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
