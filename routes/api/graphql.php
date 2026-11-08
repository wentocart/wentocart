<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiLandingController;
use App\Http\Controllers\Api\V1\GraphiqlController;
use App\Http\Controllers\Api\V1\AdminGraphiqlController;
use App\Http\Controllers\Api\V1\GraphqlController;
use App\Http\Controllers\Api\V1\GraphqlLlmsController;
use App\Http\Controllers\Api\V1\GraphqlSandboxController;
use App\Http\Controllers\Api\V1\ShopOpenApiController;
use App\Http\Controllers\Api\V1\AdminOpenApiController;
use App\Http\Controllers\Api\V1\ApiCollectionsController;
    Route::get('/', ApiLandingController::class)->middleware('throttle:60,1');
    Route::match(['get', 'post'], '/graphql', GraphqlController::class)->middleware(['storefront.key', 'throttle:120,1']);
    Route::get('/graphiql', GraphiqlController::class)->middleware('throttle:30,1');
    Route::get('/console/graphiql', AdminGraphiqlController::class)->middleware('throttle:30,1');
    Route::get('/sandbox', GraphqlSandboxController::class)->middleware('throttle:30,1');
    Route::get('/llms.txt', GraphqlLlmsController::class)->middleware('throttle:60,1');
    Route::get('/llms-full.txt', GraphqlLlmsController::class)->middleware('throttle:60,1');
    // Shop OpenAPI 3 + Swagger UI (canonical under /api/v1)
    Route::get('/store/docs', [ShopOpenApiController::class, 'docs'])->middleware('throttle:60,1');
    Route::get('/store/openapi.json', [ShopOpenApiController::class, 'docs'])->middleware('throttle:60,1');
    Route::get('/store/playground', [ShopOpenApiController::class, 'playground'])->middleware('throttle:60,1');
    // Admin OpenAPI 3 + Swagger UI
    Route::get('/console/docs', [AdminOpenApiController::class, 'docs'])->middleware('throttle:60,1');
    Route::get('/console/openapi.json', [AdminOpenApiController::class, 'docs'])->middleware('throttle:60,1');
    Route::get('/console/playground', [AdminOpenApiController::class, 'playground'])->middleware('throttle:60,1');
    Route::get('/collections', [ApiCollectionsController::class, 'index'])->middleware('throttle:60,1');
    Route::get('/collections/store', [ApiCollectionsController::class, 'store'])->middleware('throttle:60,1');
    Route::get('/collections/console', [ApiCollectionsController::class, 'console'])->middleware('throttle:60,1');
    Route::get('/collections/environment', [ApiCollectionsController::class, 'environment'])->middleware('throttle:60,1');
