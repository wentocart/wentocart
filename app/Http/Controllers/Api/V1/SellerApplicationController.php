<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SellerApplicationController extends Controller
{
    public function __construct(protected SellerService $sellers) {}

    public function show(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $seller = $customer->seller;

        return response()->json([
            'seller' => $seller ? $this->sellers->present($seller) : null,
            'can_apply' => $seller === null || $seller->canReapply(),
            'countries' => Country::optionsForAddresses(),
            'languages' => Language::enabledList()->map(fn (Language $language) => [
                'code' => $language->code,
                'name' => $language->name,
                'is_default' => (bool) $language->is_default,
            ])->values(),
            'default_locale' => Language::defaultCode(),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        // Seller identity and lifecycle fields are always server-controlled.
        foreach ([
            'seller_id', 'customer_id', 'status', 'commission_rate',
            'approved_at', 'reviewed_at', 'reviewed_by', 'blocked_at',
        ] as $key) {
            $request->request->remove($key);
        }

        $existing = $customer->seller;
        $data = $this->validated($request, $existing);
        $seller = $this->sellers->apply($customer, $data, (bool) $existing?->canReapply());

        return response()->json(['seller' => $this->sellers->present($seller)], $existing ? 200 : 201);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Seller $existing): array
    {
        $enabled = Language::enabledList()->pluck('code')->all();
        $default = Language::defaultCode();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'shop_name' => ['required', 'string', 'max:120'],
            'shop_slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('sellers', 'shop_slug')->ignore($existing?->id),
            ],
            'profile_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:40'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'address_line1' => ['required', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'max:4'],
            'description' => ['nullable', 'string', 'max:4000'],
            'tax_id' => ['nullable', 'string', 'max:80'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', 'max:8'],
            'translations.*.shop_name' => ['nullable', 'string', 'max:120'],
            'translations.*.description' => ['nullable', 'string', 'max:4000'],
        ]);

        $translations = [];
        foreach ($data['translations'] ?? [] as $row) {
            $code = strtolower(trim((string) ($row['language_code'] ?? '')));
            if ($code === '' || ($enabled !== [] && ! in_array($code, $enabled, true))) {
                continue;
            }
            $name = trim((string) ($row['shop_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $translations[] = [
                'language_code' => $code,
                'shop_name' => $name,
                'description' => $row['description'] ?? null,
            ];
        }

        // Ensure default-locale shop_name is present (required for apply).
        $hasDefault = collect($translations)->contains(fn ($row) => $row['language_code'] === $default);
        if (! $hasDefault) {
            $translations[] = [
                'language_code' => $default,
                'shop_name' => $data['shop_name'],
                'description' => $data['description'] ?? null,
            ];
        } else {
            foreach ($translations as &$row) {
                if ($row['language_code'] === $default) {
                    $data['shop_name'] = $row['shop_name'];
                    $data['description'] = $row['description'];
                }
            }
            unset($row);
        }

        if (trim((string) $data['shop_name']) === '') {
            throw ValidationException::withMessages([
                'shop_name' => 'Shop name is required.',
            ]);
        }

        $data['translations'] = $translations;

        return $data;
    }
}
