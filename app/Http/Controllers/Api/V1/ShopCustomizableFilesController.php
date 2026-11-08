<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Domain\Catalog\Services\CustomizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 */
class ShopCustomizableFilesController extends Controller
{
    public function store(Request $request, CustomizationService $customizations): JsonResponse
    {
        $productId = (int) ($request->input('product_id') ?? $request->input('productId') ?? 0);
        $optionId = (int) ($request->input('option_id') ?? $request->input('optionId') ?? 0);
        if ($productId < 1 || $optionId < 1) {
            return response()->json([
                'message' => 'product_id and option_id are required.',
                'success' => false,
            ], 400);
        }
        if (! $request->hasFile('file')) {
            return response()->json([
                'message' => 'The file field is required.',
                'success' => false,
            ], 400);
        }

        $product = Product::query()->enabled()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $field = ProductCustomizationField::query()
            ->where('product_id', $product->id)
            ->whereKey($optionId)
            ->first();
        if (! $field) {
            return response()->json(['message' => 'Customizable option not found.', 'success' => false], 404);
        }

        try {
            $staged = $customizations->stageCustomizableFile($product, $field, $request->file('file'));
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: $e->getMessage();

            return response()->json(['message' => (string) $msg, 'success' => false], 422);
        }

        return response()->json([
            'token' => $staged['token'],
            'fileName' => $staged['fileName'],
            'optionId' => $staged['optionId'],
            'success' => true,
        ], 201);
    }
}
