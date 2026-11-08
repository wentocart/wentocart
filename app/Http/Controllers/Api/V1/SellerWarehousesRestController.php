<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerWarehouse;
use App\Domain\Marketplace\SellerWarehouseSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 */
class SellerWarehousesRestController extends Controller
{
    public function index(): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerWarehouseSchema::ensure();
        $this->ensureDefault($seller);
        $rows = SellerWarehouse::query()
            ->where('seller_id', $seller->id)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (SellerWarehouse $s) => $this->row($s))
            ->values()
            ->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerWarehouseSchema::ensure();
        $data = $this->validated($request);
        if (SellerWarehouse::query()->where('code', $data['code'])->exists()) {
            return response()->json(['message' => 'Source code already in use.', 'error' => 'validation'], 422);
        }
        $row = SellerWarehouse::query()->create(array_merge($data, [
            'seller_id' => $seller->id,
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
        ]));

        return response()->json([
            'message' => 'Warehouse created successfully.',
            'data' => $this->row($row),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerWarehouseSchema::ensure();
        $row = SellerWarehouse::query()->where('seller_id', $seller->id)->whereKey($id)->first();
        if (! $row) {
            return response()->json(['message' => 'Warehouse not found.', 'error' => 'not_found'], 404);
        }
        $data = $this->validated($request, $row);
        if (SellerWarehouse::query()
            ->where('code', $data['code'])
            ->where('id', '!=', $row->id)
            ->exists()) {
            return response()->json(['message' => 'Source code already in use.', 'error' => 'validation'], 422);
        }
        $row->update($data);

        return response()->json([
            'message' => 'Warehouse updated successfully.',
            'data' => $this->row($row->fresh()),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerWarehouseSchema::ensure();
        $row = SellerWarehouse::query()->where('seller_id', $seller->id)->whereKey($id)->first();
        if (! $row) {
            return response()->json(['message' => 'Warehouse not found.', 'error' => 'not_found'], 404);
        }
        $activeCount = SellerWarehouse::query()
            ->where('seller_id', $seller->id)
            ->where('is_enabled', true)
            ->count();
        if ($row->is_enabled && $activeCount <= 1) {
            return response()->json([
                'message' => 'The last active warehouse cannot be deleted.',
                'error' => 'last_active',
            ], 422);
        }
        $row->delete();

        return response()->json(['message' => 'Warehouse deleted.', 'data' => ['id' => $id]]);
    }

    private function ensureDefault(Seller $seller): void
    {
        if (SellerWarehouse::query()->where('seller_id', $seller->id)->exists()) {
            return;
        }
        SellerWarehouse::query()->create([
            'seller_id' => $seller->id,
            'code' => 'default_'.$seller->id,
            'name' => 'Default',
            'priority' => 0,
            'is_enabled' => true,
            'country_code' => $seller->country_code,
            'city' => $seller->city,
            'street' => $seller->address_line1,
            'postcode' => $seller->postal_code,
            'contact_name' => $seller->contact_name,
            'contact_email' => $seller->email,
            'contact_phone' => $seller->contact_phone,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SellerWarehouse $existing = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'min:2', 'max:64',
                'regex:/^[A-Za-z][A-Za-z0-9_]*$/',
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'street' => ['nullable', 'string', 'max:190'],
            'postcode' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'fax' => ['nullable', 'string', 'max:40'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_enabled' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
        ]);
        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? $data['status'] ?? $existing?->is_enabled ?? false);
        $data['priority'] = (int) ($data['priority'] ?? $existing?->priority ?? 0);
        unset($data['status']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SellerWarehouse $s): array
    {
        return [
            'id' => (int) $s->id,
            'code' => (string) $s->code,
            'name' => (string) $s->name,
            'description' => (string) ($s->description ?? ''),
            'countryCode' => (string) ($s->country_code ?? ''),
            'state' => (string) ($s->state ?? ''),
            'city' => (string) ($s->city ?? ''),
            'street' => (string) ($s->street ?? ''),
            'postcode' => (string) ($s->postcode ?? ''),
            'contactName' => (string) ($s->contact_name ?? ''),
            'contactEmail' => (string) ($s->contact_email ?? ''),
            'contactPhone' => (string) ($s->contact_phone ?? ''),
            'fax' => (string) ($s->fax ?? ''),
            'latitude' => $s->latitude !== null ? (float) $s->latitude : null,
            'longitude' => $s->longitude !== null ? (float) $s->longitude : null,
            'priority' => (int) $s->priority,
            'isEnabled' => (bool) $s->is_enabled,
        ];
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
