<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin REST REST customers tree: /api/v1/console/customers[/groups|/{id}/orders|…]
 */
class AdminCustomersRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = Customer::query()->orderByDesc('id');
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Customer $c) => $this->customerRow($c))
            ->values()
            ->all();

        return response()->json($rows)->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($id);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($this->customerRow($customer));
    }

    public function groups(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        $rows = CustomerGroup::query()
            ->withCount('customers')
            ->orderBy('position')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (CustomerGroup $g) => [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'code' => (string) $g->code,
                'discountPercent' => (float) ($g->discount_percent ?? 0),
                'status' => $g->is_enabled ? 1 : 0,
                'isSystem' => (bool) $g->is_system,
                'customersCount' => (int) ($g->customers_count ?? 0),
            ])
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function showGroup(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $g = CustomerGroup::query()->withCount('customers')->find($id);
        if (! $g) {
            return response()->json(['message' => 'Customer group not found.', 'error' => 'not_found'], 404);
        }

        return response()->json([
            'id' => (int) $g->id,
            'name' => (string) $g->name,
            'code' => (string) $g->code,
            'discountPercent' => (float) ($g->discount_percent ?? 0),
            'status' => $g->is_enabled ? 1 : 0,
            'isSystem' => (bool) $g->is_system,
            'customersCount' => (int) ($g->customers_count ?? 0),
        ]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['message' => 'Name is required.', 'error' => 'validation'], 422);
        }
        $code = Str::slug(trim((string) ($request->input('code') ?: $name)));
        if ($code === '' || CustomerGroup::query()->where('code', $code)->exists()) {
            return response()->json(['message' => 'A unique group code is required.', 'error' => 'validation'], 422);
        }
        $g = CustomerGroup::query()->create([
            'name' => $name,
            'code' => $code,
            'discount_percent' => max(0, min(100, (float) $request->input('discountPercent', $request->input('discount_percent', 0)))),
            'is_enabled' => $request->boolean('status', $request->boolean('enabled', true)),
            'is_system' => false,
            'position' => (int) CustomerGroup::query()->max('position') + 1,
        ]);

        return response()->json([
            'id' => (int) $g->id,
            'name' => (string) $g->name,
            'code' => (string) $g->code,
            'discountPercent' => (float) $g->discount_percent,
            'status' => $g->is_enabled ? 1 : 0,
        ], 201);
    }

    public function updateGroup(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $g = CustomerGroup::query()->find($id);
        if (! $g) {
            return response()->json(['message' => 'Customer group not found.', 'error' => 'not_found'], 404);
        }
        if ($request->filled('name')) {
            $g->name = trim((string) $request->input('name'));
        }
        if ($request->has('discountPercent') || $request->has('discount_percent')) {
            $g->discount_percent = max(0, min(100, (float) $request->input('discountPercent', $request->input('discount_percent', 0))));
        }
        if ($request->has('status') || $request->has('enabled')) {
            $g->is_enabled = $request->boolean('status', $request->boolean('enabled'));
        }
        $g->save();

        return response()->json([
            'id' => (int) $g->id,
            'name' => (string) $g->name,
            'code' => (string) $g->code,
            'discountPercent' => (float) $g->discount_percent,
            'status' => $g->is_enabled ? 1 : 0,
        ]);
    }

    public function destroyGroup(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $g = CustomerGroup::query()->find($id);
        if (! $g) {
            return response()->json(['message' => 'Customer group not found.', 'error' => 'not_found'], 404);
        }
        if ($g->is_system) {
            return response()->json(['message' => 'System customer groups cannot be deleted.', 'error' => 'delete_error'], 422);
        }
        $g->delete();

        return response()->json(['message' => 'Customer group deleted successfully.', 'data' => ['id' => $id]]);
    }

    public function orders(int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }
        $rows = $customer->orders()->latest('id')->limit(50)->get()
            ->map(fn (Order $o) => [
                'id' => (int) $o->id,
                'incrementId' => (string) ($o->number ?? ''),
                'status' => (string) ($o->status ?? ''),
                'grandTotal' => (float) ($o->grand_total ?? 0),
                'formattedGrandTotal' => Money::format((float) ($o->grand_total ?? 0)),
                'createdAt' => optional($o->created_at)?->toIso8601String(),
            ])->values()->all();

        return response()->json($rows);
    }

    public function invoices(int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }
        // Wento invoices track via order number; expose paid orders as invoice cards.
        $rows = $customer->orders()
            ->where(function ($q) {
                $q->where('payment_status', 'paid')
                    ->orWhere('amount_paid', '>', 0)
                    ->orWhereIn('status', ['processing', 'completed', 'delivered']);
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Order $o) => [
                'id' => (int) $o->id,
                'orderId' => (int) $o->id,
                'incrementId' => (string) ($o->number ?? ''),
                'state' => (string) ($o->payment_status ?: $o->status ?: 'pending'),
                'grandTotal' => (float) ($o->grand_total ?? 0),
                'formattedGrandTotal' => Money::format((float) ($o->grand_total ?? 0)),
                'createdAt' => optional($o->created_at)?->toIso8601String(),
            ])->values()->all();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $email = strtolower(trim((string) $request->input('email', '')));
        $first = trim((string) ($request->input('first_name') ?? $request->input('firstName') ?? ''));
        if ($email === '' || $first === '') {
            return response()->json(['message' => 'Email and first name are required.', 'error' => 'validation'], 422);
        }
        try {
            $row = app(CustomerService::class)->save([
                'email' => $email,
                'first_name' => $first,
                'last_name' => trim((string) ($request->input('last_name') ?? $request->input('lastName') ?? '')),
                'phone' => $request->input('phone'),
                'password' => $request->input('password'),
                'customer_group' => (string) ($request->input('customer_group') ?? $request->input('group') ?? 'retailer'),
                'is_enabled' => $request->boolean('status', $request->boolean('enabled', true)),
            ]);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();

            return response()->json(['message' => $messages[0] ?? 'Validation failed.', 'error' => 'validation'], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'create_error'], 422);
        }

        return response()->json($this->customerRow($row), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($id);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }
        try {
            $row = app(CustomerService::class)->save([
                'email' => strtolower(trim((string) ($request->input('email') ?? $customer->email))),
                'first_name' => trim((string) ($request->input('first_name') ?? $request->input('firstName') ?? $customer->first_name ?? '')),
                'last_name' => trim((string) ($request->input('last_name') ?? $request->input('lastName') ?? $customer->last_name ?? '')),
                'phone' => $request->has('phone') ? $request->input('phone') : $customer->phone,
                'password' => $request->input('password'),
                'customer_group' => (string) ($request->input('customer_group') ?? $request->input('group') ?? $customer->customer_group ?? 'retailer'),
                'is_enabled' => $request->has('status') || $request->has('enabled')
                    ? $request->boolean('status', $request->boolean('enabled'))
                    : (bool) $customer->is_enabled,
            ], $customer);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();

            return response()->json(['message' => $messages[0] ?? 'Validation failed.', 'error' => 'validation'], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'update_error'], 422);
        }

        return response()->json($this->customerRow($row));
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($id);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }
        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully.', 'data' => ['id' => $id]]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'At least one customer id is required.', 'error' => 'validation'], 422);
        }
        $deleted = Customer::query()->whereIn('id', $ids)->delete();

        return response()->json([
            'message' => 'Customers deleted successfully.',
            'data' => ['deleted_count' => (int) $deleted],
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'At least one customer id is required.', 'error' => 'validation'], 422);
        }
        $value = $request->input('value', $request->input('status'));
        $enabled = is_bool($value) ? $value : ((int) $value === 1);
        $updated = Customer::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return response()->json([
            'message' => 'Customers updated successfully.',
            'data' => ['updated_count' => (int) $updated],
        ]);
    }

    public function addressesIndex(int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }
        $rows = $customer->addresses()->orderByDesc('id')->get()
            ->map(fn (CustomerAddress $a) => $this->addressRow($a))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function addressesShow(int $customerId, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $address = CustomerAddress::query()->where('customer_id', $customerId)->whereKey($id)->first();
        if (! $address) {
            return response()->json(['message' => 'Address not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->addressRow($address)]);
    }

    public function addressesStore(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }
        $payload = $this->addressPayload($request);
        app(CustomerService::class)->addAddress($customer, $payload);
        $address = $customer->addresses()->latest('id')->first();
        if (! $address) {
            return response()->json(['message' => 'Address could not be created.', 'error' => 'create_error'], 422);
        }

        return response()->json(['data' => $this->addressRow($address)], 201);
    }

    public function addressesUpdate(Request $request, int $customerId, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }
        $exists = $customer->addresses()->whereKey($id)->exists();
        if (! $exists) {
            return response()->json(['message' => 'Address not found.', 'error' => 'not_found'], 404);
        }
        app(CustomerService::class)->updateAddress($customer, $id, $this->addressPayload($request));
        $address = CustomerAddress::query()->where('customer_id', $customerId)->whereKey($id)->firstOrFail();

        return response()->json(['data' => $this->addressRow($address)]);
    }

    public function addressesDestroy(int $customerId, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }
        if (! $customer->addresses()->whereKey($id)->exists()) {
            return response()->json(['message' => 'Address not found.', 'error' => 'not_found'], 404);
        }
        app(CustomerService::class)->removeAddress($customer, $id);

        return response()->json(['message' => 'Address deleted successfully.', 'data' => ['id' => $id]]);
    }

    public function addressesBulkDestroy(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'At least one address id is required.', 'error' => 'validation'], 422);
        }
        $owned = CustomerAddress::query()->where('customer_id', $customerId)->whereIn('id', $ids)->pluck('id')->all();
        $deleted = $owned === [] ? 0 : app(CustomerService::class)->bulkRemoveAddresses($owned);

        return response()->json([
            'message' => 'Addresses deleted successfully.',
            'data' => ['deleted_count' => (int) $deleted],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function addressPayload(Request $request): array
    {
        return [
            'first_name' => trim((string) ($request->input('first_name') ?? $request->input('firstName') ?? '')),
            'last_name' => trim((string) ($request->input('last_name') ?? $request->input('lastName') ?? '')),
            'phone' => $request->input('phone'),
            'company' => $request->input('company'),
            'line1' => (string) ($request->input('line1') ?? $request->input('address1') ?? $request->input('address') ?? ''),
            'line2' => $request->input('line2') ?? $request->input('address2'),
            'city' => (string) ($request->input('city') ?? ''),
            'state' => (string) ($request->input('state') ?? ''),
            'postal_code' => (string) ($request->input('postal_code') ?? $request->input('postcode') ?? ''),
            'country_code' => strtoupper((string) ($request->input('country_code') ?? $request->input('country') ?? 'US')),
            'is_default_billing' => $request->boolean('is_default_billing', $request->boolean('default_billing')),
            'is_default_shipping' => $request->boolean('is_default_shipping', $request->boolean('default_shipping')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressRow(CustomerAddress $a): array
    {
        return [
            'id' => (int) $a->id,
            'firstName' => (string) ($a->first_name ?? ''),
            'lastName' => (string) ($a->last_name ?? ''),
            'phone' => (string) ($a->phone ?? ''),
            'company' => (string) ($a->company ?? ''),
            'address1' => (string) ($a->line1 ?? ''),
            'address2' => (string) ($a->line2 ?? ''),
            'city' => (string) ($a->city ?? ''),
            'state' => (string) ($a->state ?? ''),
            'postcode' => (string) ($a->postal_code ?? ''),
            'country' => (string) ($a->country_code ?? ''),
            'defaultBilling' => (bool) $a->is_default_billing,
            'defaultShipping' => (bool) $a->is_default_shipping,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerRow(Customer $c): array
    {
        return [
            'id' => (int) $c->id,
            'email' => (string) $c->email,
            'firstName' => (string) ($c->first_name ?? ''),
            'lastName' => (string) ($c->last_name ?? ''),
            'name' => (string) ($c->name ?? ''),
            'phone' => (string) ($c->phone ?? ''),
            'status' => $c->is_enabled ? 1 : 0,
            'group' => (string) ($c->customer_group ?? ''),
            'createdAt' => optional($c->created_at)?->toIso8601String(),
            'updatedAt' => optional($c->updated_at)?->toIso8601String(),
        ];
    }

    private function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if (! $admin->hasPermission('customers.manage')) {
            return response()->json(['message' => 'Forbidden.', 'error' => 'forbidden'], 403);
        }

        return null;
    }
}
