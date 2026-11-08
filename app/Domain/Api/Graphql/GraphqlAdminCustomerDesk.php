<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerDeskService;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Auth;

/**
 * Wento Customer Desk — unified activity timeline for one shopper.
 */
final class GraphqlAdminCustomerDesk
{
    /**
     * @return array{
     *   customerId: string,
     *   summary: array<string, int>,
     *   events: list<array<string, mixed>>
     * }
     */
    public static function timeline(int $customerId, int $limit = 40): array
    {
        self::assertAdmin();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }

        $desk = app(CustomerDeskService::class)->timeline($customer, $limit);

        return [
            'customerId' => (string) $desk['customer_id'],
            'summary' => $desk['summary'],
            'events' => array_map(static function (array $event): array {
                return [
                    'id' => (string) ($event['id'] ?? ''),
                    'type' => (string) ($event['type'] ?? ''),
                    'at' => $event['at'] ?? null,
                    'title' => (string) ($event['title'] ?? ''),
                    'body' => (string) ($event['body'] ?? ''),
                    'href' => $event['href'] ?? null,
                    'metaJson' => json_encode($event['meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ];
            }, $desk['events']),
        ];
    }

    protected static function assertAdmin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }
}
