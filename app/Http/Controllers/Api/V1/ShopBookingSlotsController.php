<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ServiceProductService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST GET /api/v1/store/booking-slots?id=&date=
 */
class ShopBookingSlotsController extends Controller
{
    public function __invoke(Request $request, ServiceProductService $services): JsonResponse
    {
        $id = (int) $request->query('id', $request->query('productId', $request->query('product_id', 0)));
        $date = trim((string) $request->query('date', ''));
        if ($id <= 0 || $date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'message' => 'Query parameters id and date (YYYY-MM-DD) are required.',
                'error' => 'validation',
            ], 422);
        }

        $product = Product::query()->enabled()->find($id);
        if (! $product) {
            return response()->json([]);
        }
        $product->loadMissing('serviceSetting');

        return response()->json($services->slotsForDate($product, $date));
    }
}
