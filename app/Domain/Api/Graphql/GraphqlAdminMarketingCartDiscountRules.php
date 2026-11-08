<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminMarketingCartDiscountRules
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('marketing.discounts')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters): array
    {
        self::assertManage();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = DiscountRule::query()->where('apply_as', 'cart')->whereNull('parent_id');

        $name = trim((string) ($filters['name'] ?? $filters['q'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$name.'%');
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $q->where('is_enabled', (int) $filters['status'] === 1);
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $sortMap = ['id' => 'id', 'name' => 'name', 'sort_order' => 'priority', 'priority' => 'priority', 'created_at' => 'created_at'];
        $column = $sortMap[$sort] ?? 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (DiscountRule $row) => self::map($row))->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertManage();
        $row = DiscountRule::query()->where('apply_as', 'cart')->whereNull('parent_id')->find($id);

        return $row ? self::map($row, true) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new UserError('name is required.');
        }

        [$action, $value] = self::parseAction($input);
        $couponType = (int) ($input['coupon_type'] ?? 0);
        $useAuto = (int) ($input['use_auto_generation'] ?? 0);
        $code = null;
        $applyAs = 'cart';
        if ($couponType === 1) {
            if ($useAuto === 1) {
                $code = self::uniqueCode(8);
            } else {
                $code = strtoupper(trim((string) ($input['coupon_code'] ?? '')));
                if ($code === '') {
                    throw new UserError('coupon_code is required when coupon_type is 1 and use_auto_generation is 0.');
                }
                if (DiscountRule::query()->where('code', $code)->exists()) {
                    throw new UserError('A coupon with this code already exists.');
                }
            }
            $applyAs = 'coupon';
        }

        $starts = self::parseDate($input['starts_from'] ?? $input['startsFrom'] ?? null);
        $ends = self::parseDate($input['ends_till'] ?? $input['endsTill'] ?? null);
        if ($starts && $ends && $ends < $starts) {
            throw new UserError('ends_till must be >= starts_from.');
        }

        $row = DiscountRule::query()->create([
            'name' => $name,
            'apply_as' => $applyAs === 'coupon' ? 'cart' : 'cart',
            'code' => $couponType === 1 ? $code : null,
            'action' => $action,
            'value' => $value,
            'priority' => (int) ($input['sort_order'] ?? $input['priority'] ?? 100),
            'stop_further' => (int) ($input['end_other_rules'] ?? 0) === 1 || (bool) ($input['stopFurther'] ?? false),
            'min_cart' => isset($input['min_cart']) ? (float) $input['min_cart'] : (isset($input['minCart']) ? (float) $input['minCart'] : null),
            'max_uses' => isset($input['uses_per_coupon']) ? (int) $input['uses_per_coupon'] : null,
            'max_uses_per_customer' => isset($input['usage_per_customer']) ? (int) $input['usage_per_customer'] : null,
            'used_count' => 0,
            'starts_at' => $starts,
            'expires_at' => $ends,
            'is_enabled' => ! array_key_exists('status', $input) || (int) $input['status'] === 1,
        ]);

        // Specific-coupon cart discount rules: keep apply_as=cart; store code on the rule (primary coupon).
        if ($couponType === 1 && $code) {
            $row->code = $code;
            $row->save();
        }

        return self::map($row->fresh() ?? $row, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = DiscountRule::query()->where('apply_as', 'cart')->whereNull('parent_id')->find($id);
        if (! $row) {
            throw new UserError('Cart discount rule not found.');
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('action_type', $input) || array_key_exists('discount_amount', $input) || array_key_exists('action', $input) || array_key_exists('value', $input)) {
            [$action, $value] = self::parseAction(array_merge([
                'action_type' => self::toRuleAction((string) $row->action),
                'discount_amount' => (float) $row->value,
                'action' => (string) $row->action,
                'value' => (float) $row->value,
            ], $input));
            $row->action = $action;
            $row->value = $value;
        }
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $row->is_enabled = (int) $input['status'] === 1;
        }
        if (array_key_exists('sort_order', $input) || array_key_exists('priority', $input)) {
            $row->priority = (int) ($input['sort_order'] ?? $input['priority']);
        }
        if (array_key_exists('end_other_rules', $input) || array_key_exists('stopFurther', $input)) {
            $row->stop_further = (int) ($input['end_other_rules'] ?? 0) === 1 || (bool) ($input['stopFurther'] ?? false);
        }
        if (array_key_exists('starts_from', $input) || array_key_exists('startsFrom', $input)) {
            $row->starts_at = self::parseDate($input['starts_from'] ?? $input['startsFrom'] ?? null);
        }
        if (array_key_exists('ends_till', $input) || array_key_exists('endsTill', $input)) {
            $row->expires_at = self::parseDate($input['ends_till'] ?? $input['endsTill'] ?? null);
        }
        if ($row->starts_at && $row->expires_at && $row->expires_at < $row->starts_at) {
            throw new UserError('ends_till must be >= starts_from.');
        }
        if (array_key_exists('coupon_code', $input) && $input['coupon_code'] !== null) {
            $code = strtoupper(trim((string) $input['coupon_code']));
            if ($code !== '' && DiscountRule::query()->where('code', $code)->where('id', '!=', $row->id)->exists()) {
                throw new UserError('A coupon with this code already exists.');
            }
            $row->code = $code !== '' ? $code : null;
        }
        $row->save();

        return self::map($row->fresh() ?? $row, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = DiscountRule::query()->where('apply_as', 'cart')->whereNull('parent_id')->find($id);
        if (! $row) {
            throw new UserError('Cart discount rule not found.');
        }
        $snapshot = self::map($row, true);
        $row->delete();

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public static function copy(int $id): array
    {
        self::assertManage();
        $source = DiscountRule::query()->where('apply_as', 'cart')->whereNull('parent_id')->find($id);
        if (! $source) {
            throw new UserError('Cart discount rule not found.');
        }
        $copy = DiscountRule::query()->create([
            'name' => 'Copy of '.$source->name,
            'apply_as' => 'cart',
            'code' => null,
            'action' => $source->action,
            'value' => $source->value,
            'currency_code' => $source->currency_code,
            'priority' => $source->priority,
            'stop_further' => (bool) $source->stop_further,
            'min_cart' => $source->min_cart,
            'min_qty' => $source->min_qty,
            'max_discount' => $source->max_discount,
            'max_uses' => $source->max_uses,
            'max_uses_per_customer' => $source->max_uses_per_customer,
            'used_count' => 0,
            'starts_at' => $source->starts_at,
            'expires_at' => $source->expires_at,
            'is_enabled' => false,
            'store_id' => $source->store_id,
            'seller_id' => $source->seller_id,
        ]);

        return self::map($copy, true);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one cart discount rule id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = DiscountRule::query()->where('apply_as', 'cart')->whereNull('parent_id')->find($id);
            if (! $row) {
                $skipped[] = $id;
                continue;
            }
            $row->delete();
            $deleted[] = $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(DiscountRule $row, bool $detail = false): array
    {
        $couponType = $row->code ? 1 : 0;
        $payload = [
            'id' => (int) $row->id,

            'name' => (string) $row->name,
            'description' => null,
            'coupon_type' => $couponType,
            'use_auto_generation' => 0,
            'coupon_code' => $row->code,
            'action_type' => self::toRuleAction((string) $row->action),
            'action' => (string) $row->action,
            'discount_amount' => (float) $row->value,
            'value' => (float) $row->value,
            'apply_as' => (string) $row->apply_as,
            'sort_order' => (int) $row->priority,
            'priority' => (int) $row->priority,
            'end_other_rules' => $row->stop_further ? 1 : 0,
            'status' => $row->is_enabled ? 1 : 0,
            'is_enabled' => (bool) $row->is_enabled,
            'starts_from' => optional($row->starts_at)?->toDateString(),
            'ends_till' => optional($row->expires_at)?->toDateString(),
            'usage_per_customer' => $row->max_uses_per_customer,
            'uses_per_coupon' => $row->max_uses,
            'stores' => $detail ? [] : null,
            'customerGroups' => $detail ? [] : null,
            'customer_groups' => $detail ? [] : null,
            'conditions' => $detail ? [] : null,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
        if (! $detail) {
            unset($payload['stores'], $payload['customerGroups'], $payload['customer_groups'], $payload['conditions']);
            $payload['stores'] = [];
            $payload['customer_groups'] = [];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: float}
     */
    protected static function parseAction(array $input): array
    {
        $compat = strtolower(trim((string) ($input['action_type'] ?? '')));
        $action = trim((string) ($input['action'] ?? ''));
        if ($compat !== '') {
            $action = match ($compat) {
                'by_percent', 'to_percent' => 'percent',
                'by_fixed', 'cart_fixed', 'to_fixed' => 'amount',
                'buy_x_get_y' => 'buy_x_get_y',
                default => throw new UserError('action_type must be by_percent, by_fixed, cart_fixed, or buy_x_get_y.'),
            };
        }
        if ($action === '') {
            $action = 'percent';
        }
        if (! in_array($action, ['percent', 'amount', 'set_price', 'buy_x_get_y'], true)) {
            throw new UserError('action must be percent, amount, set_price, or buy_x_get_y.');
        }
        $value = (float) ($input['discount_amount'] ?? $input['value'] ?? 0);
        if ($value <= 0) {
            throw new UserError('discount_amount must be greater than zero.');
        }
        if ($action === 'percent' && $value > 100) {
            throw new UserError('Percent discount_amount cannot exceed 100.');
        }

        return [$action, $value];
    }

    protected static function toRuleAction(string $action): string
    {
        return match ($action) {
            'percent' => 'by_percent',
            'amount' => 'by_fixed',
            'buy_x_get_y' => 'buy_x_get_y',
            'set_price' => 'to_fixed',
            default => 'by_percent',
        };
    }

    protected static function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            throw new UserError('Invalid date: '.$raw);
        }
    }

    protected static function uniqueCode(int $length): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(16)), 0, $length));
        } while (DiscountRule::query()->where('code', $code)->exists());

        return $code;
    }
}
