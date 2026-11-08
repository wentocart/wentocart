<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\AiProviderFactory;
use App\Domain\Ai\AiSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 */
class SellerMagicAiController extends Controller
{
    public function generateProductContent(Request $request): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        if (! AiSettings::contentEnabled()) {
            return response()->json([
                'message' => 'AI content generation is not enabled.',
                'error' => 'ai_disabled',
            ], 422);
        }
        $data = $request->validate([
            'description' => ['nullable', 'string', 'min:10', 'max:2000'],
            'name' => ['nullable', 'string', 'max:200'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'tone' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'string', 'max:12'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'max:40'],
            'reference_images' => ['nullable', 'array', 'max:'.MarketplaceSettings::aiMaxReferenceImages()],
            'reference_images.*' => ['file', 'image', 'max:2048'],
        ]);
        $describe = trim((string) ($data['description'] ?? $data['name'] ?? ''));
        if (strlen($describe) < 10) {
            return response()->json([
                'message' => 'Describe your product in at least 10 characters.',
                'error' => 'validation',
            ], 422);
        }
        $tone = trim((string) ($data['tone'] ?? 'professional'));
        $locale = trim((string) ($data['locale'] ?? app()->getLocale()));
        $keywords = trim((string) ($data['keywords'] ?? ''));
        $defaultFields = [
            'name', 'short_description', 'description', 'meta_title', 'meta_keywords', 'meta_description',
        ];
        $fields = MarketplaceSettings::aiLetSellersChooseFields() && is_array($data['fields'] ?? null)
            ? array_values($data['fields'])
            : $defaultFields;
        $refCount = count($request->file('reference_images', []) ?: []);
        if ($refCount > 0 && ! MarketplaceSettings::aiAllowReferenceImages()) {
            return response()->json([
                'message' => 'Reference images are not allowed.',
                'error' => 'reference_images_disabled',
            ], 422);
        }
        $images = [];
        foreach ($request->file('reference_images', []) ?: [] as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $mime = (string) ($file->getMimeType() ?: 'image/jpeg');
            $b64 = base64_encode((string) file_get_contents($file->getRealPath()));
            $images[] = [
                'mime' => $mime,
                'base64' => $b64,
                'url' => 'data:'.$mime.';base64,'.$b64,
                'data_url' => 'data:'.$mime.';base64,'.$b64,
            ];
        }

        $prompt = "Write ecommerce product listing content for a marketplace seller.\n"
            ."Product description from seller: {$describe}\n"
            .($keywords !== '' ? "Keywords: {$keywords}\n" : '')
            ."Tone: {$tone}\n"
            ."Locale/language: {$locale}\n"
            .'Fields to fill: '.implode(', ', $fields)."\n"
            .($images !== []
                ? 'Seller attached '.count($images)." reference photo(s); use visual details from the images.\n"
                : '')
            .'Return JSON with keys: name, short_description, description, meta_title, meta_keywords, meta_description, price_suggestion (number or null).';

        try {
            $active = AiSettings::activeConnection();
            if (! $active) {
                return response()->json(['message' => 'No AI connection configured.', 'error' => 'ai_unconfigured'], 422);
            }
            $raw = AiProviderFactory::make($active)->complete($prompt, [
                'system' => 'You are a helpful ecommerce copywriter. Reply with valid JSON only. When images are provided, ground product visuals in them.',
                'timeout' => 45,
                'max_tokens' => 1200,
                'images' => $images,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'AI generation failed: '.$e->getMessage(),
                'error' => 'ai_error',
            ], 422);
        }

        $parsed = $this->parseJson($raw);

        return response()->json([
            'data' => [
                'name' => (string) ($parsed['name'] ?? ''),
                'shortDescription' => (string) ($parsed['short_description'] ?? $parsed['shortDescription'] ?? ''),
                'description' => (string) ($parsed['description'] ?? ''),
                'metaTitle' => (string) ($parsed['meta_title'] ?? $parsed['metaTitle'] ?? ''),
                'metaKeywords' => (string) ($parsed['meta_keywords'] ?? $parsed['metaKeywords'] ?? ''),
                'metaDescription' => (string) ($parsed['meta_description'] ?? $parsed['metaDescription'] ?? ''),
                'priceSuggestion' => isset($parsed['price_suggestion']) ? (float) $parsed['price_suggestion'] : null,
                'referenceImageCount' => $refCount,
                'fields' => $fields,
                'raw' => $raw,
                'sellerId' => (int) $seller->id,
            ],
        ]);
    }

    public function status(): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }

        return response()->json([
            'data' => [
                'enabled' => AiSettings::contentEnabled(),
                'marketplaceEnabled' => MarketplaceSettings::enabled(),
                'allowReferenceImages' => MarketplaceSettings::aiAllowReferenceImages(),
                'maximumReferenceImages' => MarketplaceSettings::aiMaxReferenceImages(),
                'letSellersChooseFields' => MarketplaceSettings::aiLetSellersChooseFields(),
                'generatableFields' => [
                    'name', 'short_description', 'description',
                    'meta_title', 'meta_keywords', 'meta_description', 'price_suggestion',
                ],
            ],
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function parseJson(string $raw): array
    {
        $trim = trim($raw);
        if (preg_match('/\{.*\}/s', $trim, $m)) {
            $trim = $m[0];
        }
        $decoded = json_decode($trim, true);

        return is_array($decoded) ? $decoded : [];
    }
}
