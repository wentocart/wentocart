<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Settings\CustomerSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Shop REST customer-addresses REST under /api/v1/store/customer-addresses.
 */
class ShopCustomerAddressesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        [$sortCol, $dir] = $this->sortOrder($request);
        $rows = $customer->addresses()
            ->orderBy($sortCol, $dir)
            ->orderBy('id', $dir)
            ->get()
            ->map(fn (CustomerAddress $a) => $this->readRow($a, $customer))
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $max = CustomerSettings::maxSavedAddresses();
        $count = $customer->addresses()->count();
        if ($max !== null && $count >= $max) {
            return response()->json(['message' => 'You have reached the maximum number of saved addresses.', 'success' => false], 400);
        }
        if (! CustomerSettings::allowMultipleAddresses() && $count >= 1) {
            return response()->json(['message' => 'Multiple addresses are not allowed.', 'success' => false], 400);
        }

        $data = $this->writeData($request, required: true);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $address = $customer->addresses()->create($data);
        $this->applyDefault($customer, $address, (bool) ($request->boolean('defaultAddress') || $request->boolean('default_address')));

        return response()->json($this->writeResponse($address->fresh(), $customer), 201);
    }

    public function update(Request $request, int $addressId): JsonResponse
    {
        $customer = $this->customer($request);
        $address = $customer->addresses()->whereKey($addressId)->first();
        if (! $address) {
            return response()->json([
                'detail' => 'Customer address with ID "'.$addressId.'" not found',
                'message' => 'Customer address with ID "'.$addressId.'" not found',
            ], 404);
        }

        $data = $this->writeData($request, required: false);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        if ($data !== []) {
            $address->update($data);
        }
        if ($request->has('defaultAddress') || $request->has('default_address')) {
            $this->applyDefault($customer, $address->fresh(), $request->boolean('defaultAddress') || $request->boolean('default_address'));
        }

        return response()->json($this->writeResponse($address->fresh(), $customer));
    }

    public function destroy(Request $request, int $addressId): Response|JsonResponse
    {
        $customer = $this->customer($request);
        $address = $customer->addresses()->whereKey($addressId)->first();
        if (! $address) {
            return response()->json([
                'detail' => 'Customer address with ID "'.$addressId.'" not found',
                'message' => 'Customer address with ID "'.$addressId.'" not found',
            ], 404);
        }
        $address->delete();

        return response()->noContent();
    }

    /**
     */
    public function makeDefault(Request $request, int $addressId): JsonResponse
    {
        $customer = $this->customer($request);
        $address = $customer->addresses()->whereKey($addressId)->first();
        if (! $address) {
            return response()->json([
                'detail' => 'Customer address with ID "'.$addressId.'" not found',
                'message' => 'Customer address with ID "'.$addressId.'" not found',
            ], 404);
        }
        $this->applyDefault($customer, $address, true);

        return response()->json([
            'success' => true,
            'message' => 'Default address updated.',
            'data' => $this->readRow($address->fresh(), $customer),
        ]);
    }

    public function show(Request $request, int $addressId): JsonResponse
    {
        $customer = $this->customer($request);
        $address = $customer->addresses()->whereKey($addressId)->first();
        if (! $address) {
            return response()->json([
                'detail' => 'Customer address with ID "'.$addressId.'" not found',
                'message' => 'Customer address with ID "'.$addressId.'" not found',
            ], 404);
        }

        return response()->json($this->readRow($address, $customer));
    }

    private function customer(Request $request): Customer
    {
        $user = $request->user('customer');
        if (! $user instanceof Customer) {
            abort(response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403));
        }

        return $user;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sortOrder(Request $request): array
    {
        $sort = (string) $request->query('sort', 'id');
        $order = strtolower((string) $request->query('order', 'asc'));
        if (str_contains($sort, '-')) {
            [$sort, $maybe] = array_pad(explode('-', $sort, 2), 2, '');
            if (in_array(strtolower($maybe), ['asc', 'desc'], true)) {
                $order = strtolower($maybe);
            }
        }
        $col = in_array($sort, ['id', 'created_at'], true) ? $sort : 'id';
        $dir = $order === 'desc' ? 'desc' : 'asc';

        return [$col, $dir];
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function writeData(Request $request, bool $required): array|JsonResponse
    {
        $map = [
            'first_name' => ['firstName', 'first_name'],
            'last_name' => ['lastName', 'last_name'],
            'company' => ['companyName', 'company_name', 'company'],
            'tax_id' => ['vatId', 'vat_id', 'tax_id'],
            'phone' => ['phone'],
            'line1' => ['address1', 'address', 'line1'],
            'line2' => ['address2', 'line2'],
            'city' => ['city'],
            'state' => ['state'],
            'country_code' => ['country', 'country_code'],
            'postal_code' => ['postcode', 'postal_code'],
        ];
        $out = [];
        foreach ($map as $wento => $keys) {
            foreach ($keys as $key) {
                if ($request->exists($key)) {
                    $val = $request->input($key);
                    $out[$wento] = is_string($val) ? trim($val) : $val;
                    break;
                }
            }
        }
        if (isset($out['country_code']) && is_string($out['country_code'])) {
            $out['country_code'] = strtoupper($out['country_code']);
        }

        if ($required) {
            foreach (['first_name', 'last_name', 'line1', 'city'] as $need) {
                if (empty($out[$need])) {
                    return response()->json([
                        'message' => 'The '.$need.' field is required.',
                        'success' => false,
                    ], 400);
                }
            }
            $out['country_code'] = $out['country_code'] ?? 'US';
            $out['state'] = $out['state'] ?? '';
            $out['postal_code'] = $out['postal_code'] ?? '';
            $out['phone'] = $out['phone'] ?? '';
        }

        return $out;
    }

    private function applyDefault(Customer $customer, CustomerAddress $address, bool $default): void
    {
        if (! $default) {
            return;
        }
        $customer->addresses()->whereKeyNot($address->id)->update([
            'is_default_billing' => false,
            'is_default_shipping' => false,
        ]);
        $address->forceFill([
            'is_default_billing' => true,
            'is_default_shipping' => true,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function readRow(CustomerAddress $a, Customer $customer): array
    {
        $street = trim((string) $a->line1.($a->line2 ? ', '.$a->line2 : ''));

        return [
            'id' => (int) $a->id,
            'addressType' => 'customer',
            'firstName' => (string) ($a->first_name ?? ''),
            'lastName' => (string) ($a->last_name ?? ''),
            'companyName' => $a->company,
            'vatId' => $a->tax_id,
            'address' => $street,
            'city' => (string) ($a->city ?? ''),
            'state' => (string) ($a->state ?? ''),
            'country' => (string) ($a->country_code ?? ''),
            'postcode' => (string) ($a->postal_code ?? ''),
            'email' => (string) ($customer->email ?? ''),
            'phone' => (string) ($a->phone ?? ''),
            'defaultAddress' => (bool) ($a->is_default_billing || $a->is_default_shipping),
            'useForShipping' => false,
            'createdAt' => optional($a->created_at)?->toIso8601String(),
            'updatedAt' => optional($a->updated_at)?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function writeResponse(CustomerAddress $a, Customer $customer): array
    {
        return [
            'id' => (int) $a->id,
            'addressId' => (int) $a->id,
            'firstName' => (string) ($a->first_name ?? ''),
            'lastName' => (string) ($a->last_name ?? ''),
            'companyName' => $a->company,
            'vatId' => $a->tax_id,
            'email' => (string) ($customer->email ?? ''),
            'phone' => $a->phone,
            'address1' => (string) ($a->line1 ?? ''),
            'address2' => $a->line2,
            'country' => (string) ($a->country_code ?? ''),
            'state' => (string) ($a->state ?? ''),
            'city' => (string) ($a->city ?? ''),
            'postcode' => (string) ($a->postal_code ?? ''),
            'defaultAddress' => (bool) ($a->is_default_billing || $a->is_default_shipping),
        ];
    }
}
