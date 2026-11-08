<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Marketplace\Models\Seller;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 */
class SellerBarcodesRestController extends Controller
{
    public function update(Request $request, int $productId): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        $product = Product::query()
            ->where('seller_id', $seller->id)
            ->whereKey($productId)
            ->firstOrFail();
        $data = $request->validate([
            'barcode' => [
                'required',
                'string',
                'max:64',
                Rule::unique('products', 'barcode')->ignore($product->id),
            ],
            'format' => ['nullable', 'string', Rule::in(['code128', 'ean13', 'upca'])],
        ]);
        $product->forceFill(['barcode' => trim($data['barcode'])])->save();

        return response()->json([
            'message' => 'Barcode saved.',
            'data' => $this->row($product->fresh()),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'format' => ['nullable', 'string', Rule::in(['code128', 'ean13', 'upca'])],
        ]);
        $format = (string) ($data['format'] ?? 'code128');
        $created = 0;
        $skipped = 0;
        $products = Product::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $data['product_ids'])
            ->get();
        foreach ($products as $product) {
            if ($product->barcode) {
                $skipped++;
                continue;
            }
            $value = $this->generateValue((int) $product->id, $format);
            // Ensure uniqueness.
            while (Product::query()->where('barcode', $value)->whereKeyNot($product->id)->exists()) {
                $value = $this->generateValue((int) $product->id, $format, true);
            }
            $product->forceFill(['barcode' => $value])->save();
            $created++;
        }

        return response()->json([
            'message' => "Created {$created} barcode(s); skipped {$skipped}.",
            'data' => ['created' => $created, 'skipped' => $skipped, 'format' => $format],
        ]);
    }

    public function print(Request $request): Response|JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);
        $rows = Product::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $data['product_ids'])
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->get(['id', 'sku', 'barcode']);

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Barcodes</title>'
            .'<style>body{font-family:sans-serif} .label{border:1px solid #ccc;padding:12px;margin:8px;display:inline-block;width:220px;text-align:center}'
            .'.code{font-family:monospace;font-size:18px;letter-spacing:2px;margin-top:8px}</style></head><body>';
        foreach ($rows as $p) {
            $name = e((string) ($p->sku ?: 'Product #'.$p->id));
            $code = e((string) $p->barcode);
            $html .= '<div class="label"><div>ID '.((int) $p->id).'</div><div>'.$name.'</div>'
                .'<div class="code">*'.$code.'*</div><div>'.$code.'</div></div>';
        }
        $html .= '</body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'barcode' => (string) ($product->barcode ?? ''),
            'generated' => $product->barcode ? 1 : 0,
        ];
    }

    private function generateValue(int $productId, string $format, bool $jitter = false): string
    {
        $base = str_pad((string) $productId, 11, '0', STR_PAD_LEFT);
        if ($jitter) {
            $base = substr($base, 0, 10).((string) random_int(0, 9));
        }
        if ($format === 'upca') {
            return $this->withCheckDigit(substr($base, 0, 11));
        }
        if ($format === 'ean13') {
            return $this->withCheckDigit(substr('0'.$base, 0, 12));
        }

        return 'S'.$productId.($jitter ? random_int(10, 99) : '');
    }

    private function withCheckDigit(string $digits): string
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $n = (int) $digits[$len - 1 - $i];
            $sum += ($i % 2 === 0) ? $n * 3 : $n;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $digits.(string) $check;
    }

    /**
     * @return Seller|JsonResponse
     */
    private function owner(): Seller|JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $seller = Seller::query()->where('customer_id', $customer->id)->first();
        if (! $seller || $seller->status !== Seller::STATUS_APPROVED) {
            return response()->json(['message' => 'Seller shop not found.', 'error' => 'not_found'], 404);
        }

        return $seller;
    }
}
