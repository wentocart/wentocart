<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Settings\CustomerSettings;
use GraphQL\Error\UserError;

/**
 * Shop REST address-book surface:
 * getCustomerAddresses / createAddUpdateCustomerAddress / createDeleteCustomerAddress.
 */
final class GraphqlCustomerAddresses
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function connection(array $args): array
    {
        $user = self::requireCustomer();
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 20)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^address:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $total = $user->addresses()->count();
        $q = $user->addresses()
            ->orderByDesc('is_default_shipping')
            ->orderByDesc('is_default_billing')
            ->orderBy('id');
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(function (CustomerAddress $a) {
            return [
                'cursor' => base64_encode('address:'.$a->id),
                'node' => self::map($a),
            ];
        })->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'endCursor' => $end ? base64_encode('address:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{addUpdateCustomerAddress: array<string, mixed>}
     */
    public static function addUpdate(array $args): array
    {
        $user = self::requireCustomer();
        $input = $args['input'] ?? $args;
        if (! is_array($input)) {
            throw new UserError('Invalid address input.');
        }

        $first = trim((string) ($input['firstName'] ?? ''));
        $last = trim((string) ($input['lastName'] ?? ''));
        $line1 = trim((string) ($input['address1'] ?? $input['address'] ?? $input['line1'] ?? ''));
        $line2 = trim((string) ($input['address2'] ?? $input['line2'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $state = trim((string) ($input['state'] ?? ''));
        $country = strtoupper(trim((string) ($input['country'] ?? $input['countryCode'] ?? '')));
        $postcode = trim((string) ($input['postcode'] ?? $input['postalCode'] ?? $input['zipCode'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? $input['phoneNumber'] ?? ''));
        $email = trim((string) ($input['email'] ?? $user->email ?? ''));
        $company = trim((string) ($input['companyName'] ?? $input['company'] ?? ''));
        $vat = trim((string) ($input['vatId'] ?? $input['taxId'] ?? ''));
        $default = (bool) ($input['defaultAddress'] ?? $input['defaultBilling'] ?? $input['isDefaultBilling'] ?? false);
        $useShipping = (bool) ($input['useForShipping'] ?? $input['defaultShipping'] ?? $input['isDefaultShipping'] ?? $default);
        $addressId = (int) ($input['addressId'] ?? $input['id'] ?? 0);

        if ($first === '' || $line1 === '' || $city === '' || $country === '') {
            throw new UserError('firstName, address1, city, and country are required.');
        }
        if (strlen($country) !== 2) {
            throw new UserError('Country must be a 2-letter ISO code.');
        }

        $data = [
            'first_name' => $first,
            'last_name' => $last !== '' ? $last : null,
            'line1' => $line1,
            'line2' => $line2 !== '' ? $line2 : null,
            'city' => $city,
            'state' => $state !== '' ? $state : null,
            'postal_code' => $postcode !== '' ? $postcode : null,
            'country_code' => $country,
            'phone' => $phone !== '' ? $phone : null,
            'company' => $company !== '' ? $company : null,
            'tax_id' => $vat !== '' ? $vat : null,
            'label' => $email !== '' ? $email : null,
            'is_default_billing' => $default,
            'is_default_shipping' => $useShipping,
        ];

        if ($addressId > 0) {
            $row = $user->addresses()->whereKey($addressId)->first();
            if (! $row) {
                throw new UserError('Address not found.');
            }
            $row->fill($data)->save();
        } else {
            $max = CustomerSettings::maxSavedAddresses();
            $count = $user->addresses()->count();
            if ($max !== null && $count >= $max) {
                throw new UserError('You have reached the maximum number of saved addresses.');
            }
            if (! CustomerSettings::allowMultipleAddresses() && $count >= 1) {
                throw new UserError('Multiple addresses are not allowed.');
            }
            $row = $user->addresses()->create($data);
        }

        if ($row->is_default_shipping) {
            $user->addresses()->whereKeyNot($row->id)->update(['is_default_shipping' => false]);
        }
        if ($row->is_default_billing) {
            $user->addresses()->whereKeyNot($row->id)->update(['is_default_billing' => false]);
        }

        return [
            'addUpdateCustomerAddress' => self::map($row->fresh()),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{deleteCustomerAddress: array{id: string, success: bool, message: string}}
     */
    public static function delete(array $args): array
    {
        $user = self::requireCustomer();
        $input = $args['input'] ?? $args;
        if (! is_array($input)) {
            throw new UserError('Invalid address input.');
        }
        $id = (int) ($input['id'] ?? $input['addressId'] ?? 0);
        if ($id < 1) {
            throw new UserError('Address id is required.');
        }
        $row = $user->addresses()->whereKey($id)->first();
        if (! $row) {
            throw new UserError('Address not found.');
        }
        $row->delete();

        return [
            'deleteCustomerAddress' => [
                'id' => (string) $id,
                'success' => true,
                'message' => 'Address deleted.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(CustomerAddress $a): array
    {
        $name = trim(($a->first_name ?? '').' '.($a->last_name ?? '')) ?: null;
        $addressLines = trim(implode("\n", array_filter([(string) $a->line1, (string) $a->line2])));

        return [
            'id' => (string) $a->id,

            'addressId' => (int) $a->id,
            'addressType' => 'customer',
            'label' => $a->label,
            'name' => $name,
            'firstName' => $a->first_name,
            'lastName' => $a->last_name,
            'email' => $a->label,
            'phone' => $a->phone,
            'companyName' => $a->company,
            'vatId' => $a->tax_id,
            'address' => $addressLines !== '' ? $addressLines : $a->line1,
            'address1' => $a->line1,
            'address2' => $a->line2,
            'line1' => $a->line1,
            'line2' => $a->line2,
            'city' => $a->city,
            'state' => $a->state,
            'country' => $a->country_code,
            'country_code' => $a->country_code,
            'postcode' => $a->postal_code,
            'postal_code' => $a->postal_code,
            'defaultAddress' => (bool) $a->is_default_billing,
            'useForShipping' => (bool) $a->is_default_shipping,
            'defaultBilling' => (bool) $a->is_default_billing,
            'defaultShipping' => (bool) $a->is_default_shipping,
            'is_default_shipping' => (bool) $a->is_default_shipping,
            'is_default_billing' => (bool) $a->is_default_billing,
            'phoneNumber' => $a->phone,
            'zipCode' => $a->postal_code,
            'createdAt' => optional($a->created_at)?->toIso8601String(),
            'updatedAt' => optional($a->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * Shop REST → addCustomerAddress { address { … } }.
     *
     * @param  array<string, mixed>  $args
     * @return array{address: array<string, mixed>}
     */
    public static function addCustomerAddress(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        unset($input['addressId'], $input['id']);

        return ['address' => self::addUpdate(['input' => $input])['addUpdateCustomerAddress']];
    }

    /**
     * Shop REST → updateCustomerAddress { address { … } }.
     *
     * @param  array<string, mixed>  $args
     * @return array{address: array<string, mixed>}
     */
    public static function updateCustomerAddress(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $addressId = (int) ($input['addressId'] ?? $input['id'] ?? $args['addressId'] ?? $args['id'] ?? 0);
        if ($addressId < 1) {
            throw new UserError('addressId is required.');
        }
        $input['addressId'] = $addressId;

        return ['address' => self::addUpdate(['input' => $input])['addUpdateCustomerAddress']];
    }

    protected static function requireCustomer(): Customer
    {
        $user = auth('customer')->user();
        if (! $user instanceof Customer) {
            throw new UserError('Authentication required.');
        }

        return $user;
    }
}
