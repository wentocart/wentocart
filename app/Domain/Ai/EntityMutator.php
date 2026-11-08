<?php

namespace App\Domain\Ai;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\Operator;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Allowlisted mutations proposed by the assistant (never raw AI SQL writes).
 * Uses domain services so related rows (store, group, translations, shop pivot) stay consistent.
 */
final class EntityMutator
{
    /**
     * @param  array{action: string, entity: string, id?: int|null, fields?: array<string, mixed>}  $plan
     * @return array{ok: bool, message: string, entity?: string, id?: int, link?: string}
     */
    public static function apply(array $plan): array
    {
        if (! AssistantSettings::writesEnabled()) {
            throw new RuntimeException('AI write actions are disabled. Enable them under Configure → AI.');
        }

        $action = self::normalizeAction((string) ($plan['action'] ?? ''));
        $entity = strtolower(trim((string) ($plan['entity'] ?? '')));
        $id = isset($plan['id']) ? (int) $plan['id'] : null;
        $fields = is_array($plan['fields'] ?? null) ? $plan['fields'] : [];

        return match ($entity) {
            'customer' => self::customer($action, $id, $fields),
            'product' => self::product($action, $id, $fields),
            'order' => self::order($action, $id, $fields),
            default => throw new InvalidArgumentException('Unsupported entity for AI writes.'),
        };
    }

    public static function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));

        return match ($action) {
            'insert', 'add', 'create' => 'create',
            'edit', 'change', 'update' => 'update',
            'remove', 'destroy', 'delete' => 'delete',
            default => $action,
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{ok: bool, message: string, entity?: string, id?: int, link?: string}
     */
    protected static function customer(string $action, ?int $id, array $fields): array
    {
        $service = app(CustomerService::class);

        if ($action === 'create') {
            $email = strtolower(trim((string) ($fields['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('A valid email is required to create a customer.');
            }
            if (Customer::query()->where('email', $email)->exists()) {
                throw new InvalidArgumentException('A customer with that email already exists.');
            }

            [$first, $last, $name] = self::splitCustomerName($fields);
            $groupCode = self::defaultCustomerGroupCode($fields);

            try {
                $customer = $service->save([
                    'name' => $name,
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'phone' => trim((string) ($fields['phone'] ?? '')) ?: null,
                    'customer_group' => $groupCode,
                    'newsletter' => (bool) ($fields['newsletter'] ?? false),
                    'is_enabled' => array_key_exists('is_enabled', $fields) ? (bool) $fields['is_enabled'] : true,
                    'notes' => trim((string) ($fields['notes'] ?? '')),
                ]);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Could not create customer: '.$e->getMessage(), 0, $e);
            }

            // Ensure group id is linked when code exists.
            if ($customer->customer_group && ! $customer->customer_group_id) {
                $group = CustomerGroup::query()->where('code', $customer->customer_group)->first();
                if ($group) {
                    $customer->update(['customer_group_id' => $group->id]);
                }
            }

            return [
                'ok' => true,
                'message' => 'Customer “'.$customer->name.'” was created (#'.$customer->id.').',
                'entity' => 'customer',
                'id' => $customer->id,
                'link' => '/admin/customers/'.$customer->id,
            ];
        }

        if ($action === 'update') {
            if (! Customer::query()->find($id)) {
                throw new InvalidArgumentException('Customer not found.');
            }
            try {
                $op = app(Operator::class)->execute('customers.customer.update', [
                    'id' => $id,
                    'fields' => $fields,
                ], ['source' => 'ai']);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Could not update customer: '.$e->getMessage(), 0, $e);
            }
            $customer = Customer::query()->findOrFail($id);

            return [
                'ok' => true,
                'message' => 'Customer “'.$customer->name.'” was updated.',
                'entity' => 'customer',
                'id' => $customer->id,
                'link' => '/admin/customers/'.$customer->id,
                'operation_id' => $op->id,
            ];
        }

        if ($action === 'delete') {
            $customer = Customer::query()->find($id);
            if (! $customer) {
                throw new InvalidArgumentException('Customer not found.');
            }
            if ($customer->orders()->exists()) {
                throw new InvalidArgumentException('This customer has orders. Disable the account instead of deleting.');
            }
            $label = $customer->name;
            DB::transaction(function () use ($customer) {
                $customer->addresses()->delete();
                $customer->delete();
            });
            $service->bumpListCache();

            return [
                'ok' => true,
                'message' => 'Customer “'.$label.'” was deleted.',
                'entity' => 'customer',
                'id' => $id,
            ];
        }

        throw new InvalidArgumentException('Unsupported customer action.');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{ok: bool, message: string, entity?: string, id?: int, link?: string}
     */
    protected static function product(string $action, ?int $id, array $fields): array
    {
        $service = app(ProductService::class);
        $lang = Language::defaultCode();

        if ($action === 'create') {
            $name = trim((string) ($fields['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('Product name is required.');
            }
            $sku = trim((string) ($fields['sku'] ?? ''));
            if ($sku === '') {
                $sku = 'AI-'.Str::upper(Str::random(8));
            }
            $qty = (int) ($fields['stock_qty'] ?? 0);
            $slug = Str::slug($name) ?: 'product';

            try {
                $product = $service->save([
                    'product' => [
                        'type' => 'simple',
                        'sku' => $sku,
                        'price' => (float) ($fields['price'] ?? 0),
                        'stock_qty' => $qty,
                        'stock_status' => $qty > 0 ? 'in_stock' : 'out_of_stock',
                        'is_enabled' => array_key_exists('is_enabled', $fields) ? (bool) $fields['is_enabled'] : true,
                        'visibility' => 'catalog_search',
                        'available_for_order' => true,
                        'show_price' => true,
                        'manage_stock' => true,
                        'min_qty' => null,
                        'qty_step' => null,
                    ],
                    'translations' => [
                        $lang => [
                            'language_code' => $lang,
                            'name' => $name,
                            'slug' => $slug,
                            'short_description' => trim((string) ($fields['short_description'] ?? '')),
                            'description' => (string) ($fields['description'] ?? ''),
                        ],
                    ],
                    'category_ids' => [],
                    'tag_ids' => [],
                ]);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Could not create product: '.$e->getMessage(), 0, $e);
            }

            return [
                'ok' => true,
                'message' => 'Product “'.$name.'” was created (#'.$product->id.').',
                'entity' => 'product',
                'id' => $product->id,
                'link' => '/admin/products/'.$product->id.'/edit',
            ];
        }

        if ($action === 'update') {
            $product = Product::query()->find($id);
            if (! $product) {
                throw new InvalidArgumentException('Product not found.');
            }

            try {
                $op = app(Operator::class)->execute('catalog.product.update', [
                    'id' => $id,
                    'fields' => $fields,
                ], ['source' => 'ai']);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Could not update product: '.$e->getMessage(), 0, $e);
            }

            $name = $product->fresh()->translation($lang)?->name ?: ('#'.$id);

            return [
                'ok' => true,
                'message' => 'Product “'.$name.'” was updated.',
                'entity' => 'product',
                'id' => $id,
                'link' => '/admin/products/'.$id.'/edit',
                'operation_id' => $op->id,
            ];
        }

        if ($action === 'delete') {
            $product = Product::query()->find($id);
            if (! $product) {
                throw new InvalidArgumentException('Product not found.');
            }
            $label = $product->translation()?->name ?: ('#'.$product->id);
            $product->delete();

            return [
                'ok' => true,
                'message' => 'Product “'.$label.'” was deleted.',
                'entity' => 'product',
                'id' => $id,
            ];
        }

        throw new InvalidArgumentException('Unsupported product action.');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{ok: bool, message: string, entity?: string, id?: int, link?: string}
     */
    protected static function order(string $action, ?int $id, array $fields): array
    {
        if ($action !== 'update') {
            throw new InvalidArgumentException('Orders can only have their status updated via AI — not created or deleted.');
        }

        $order = Order::query()->find($id);
        if (! $order) {
            throw new InvalidArgumentException('Order not found.');
        }

        $status = strtolower(trim((string) ($fields['status'] ?? '')));
        try {
            $op = app(Operator::class)->execute('orders.status.update', [
                'id' => $id,
                'status' => $status,
            ], ['source' => 'ai']);
        } catch (Throwable $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $order->refresh();

        return [
            'ok' => true,
            'message' => 'Order '.($order->number ?: '#'.$order->id).' status was set to '.$order->status.'.',
            'entity' => 'order',
            'id' => $order->id,
            'link' => '/admin/orders/'.$order->id,
            'operation_id' => $op->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{0: string, 1: string, 2: string}
     */
    protected static function splitCustomerName(array $fields): array
    {
        $first = trim((string) ($fields['first_name'] ?? ''));
        $last = trim((string) ($fields['last_name'] ?? ''));
        $name = trim((string) ($fields['name'] ?? ''));

        if ($name !== '' && $first === '' && $last === '') {
            $parts = preg_split('/\s+/', $name, 2) ?: [];
            $first = $parts[0] ?? $name;
            $last = $parts[1] ?? '';
        }
        if ($name === '') {
            $name = trim($first.' '.$last);
        }
        if ($name === '') {
            $email = (string) ($fields['email'] ?? '');
            $name = $email !== '' ? (strstr($email, '@', true) ?: 'Customer') : 'Customer';
            if ($first === '') {
                $first = $name;
            }
        }

        return [$first, $last, $name];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected static function defaultCustomerGroupCode(array $fields): string
    {
        $requested = trim((string) ($fields['customer_group'] ?? ''));
        if ($requested !== '' && CustomerGroup::query()->where('code', $requested)->where('is_enabled', true)->exists()) {
            return $requested;
        }

        $preferred = ['retailer', 'general', 'default'];
        foreach ($preferred as $code) {
            if (CustomerGroup::query()->where('code', $code)->where('is_enabled', true)->exists()) {
                return $code;
            }
        }

        $first = CustomerGroup::query()->where('is_enabled', true)->orderBy('id')->value('code');

        return $first ? (string) $first : 'retailer';
    }
}
