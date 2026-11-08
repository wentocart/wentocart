<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Plugin\ExtensionMarketplace;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ExtensionMarketplaceController extends Controller
{
    public function __invoke(ExtensionMarketplace $marketplace): JsonResponse
    {
        return response()->json($marketplace->payload());
    }
}
