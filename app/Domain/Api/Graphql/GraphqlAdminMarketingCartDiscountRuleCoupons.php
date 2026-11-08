<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminMarketingCartDiscountRuleCoupons
{
    public static function assertManage(): Admin
    {
        return GraphqlAdminMarketingCartDiscountRules::assertManage();
    }

    protected static function parentRule(int $discountRuleId): DiscountRule
    {
        $source = DiscountRule::query()->where('apply_as', 'cart')->whereNull('parent_id')->find($discountRuleId);
        if (! $source) {
            throw new UserError('Cart discount rule not found.');
        }

        return $source;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $discountRuleId, int $id): ?array
    {
        self::assertManage();
        self::parentRule($discountRuleId);
        $row = DiscountRule::query()
            ->where('apply_as', 'coupon')
            ->where('parent_id', $discountRuleId)
            ->whereKey($id)
            ->first();

        return $row ? self::map($row) : null;
    }

    public static function paginate(int $discountRuleId, array $filters): array
    {
        self::assertManage();
        self::parentRule($discountRuleId);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = DiscountRule::query()
            ->where('apply_as', 'coupon')
            ->where('parent_id', $discountRuleId)
            ->orderByDesc('id');

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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(int $discountRuleId, array $input): array
    {
        self::assertManage();
        $source = self::parentRule($discountRuleId);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if ($code === '') {
            throw new UserError('code is required.');
        }
        if (DiscountRule::query()->where('code', $code)->exists()) {
            throw new UserError('A coupon with this code already exists.');
        }

        $maxUses = array_key_exists('usage_limit', $input) || array_key_exists('usageLimit', $input)
            ? (int) ($input['usage_limit'] ?? $input['usageLimit'])
            : $source->max_uses;
        $perCustomer = array_key_exists('usage_per_customer', $input) || array_key_exists('usagePerCustomer', $input)
            ? (int) ($input['usage_per_customer'] ?? $input['usagePerCustomer'])
            : $source->max_uses_per_customer;
        $expiresAt = $source->expires_at;
        if (array_key_exists('expired_at', $input) || array_key_exists('expiredAt', $input)) {
            $raw = trim((string) ($input['expired_at'] ?? $input['expiredAt'] ?? ''));
            $expiresAt = $raw !== '' ? $raw : null;
        }

        $row = DiscountRule::query()->create([
            'name' => ($source->name ?: 'Cart discount rule').' '.$code,
            'apply_as' => 'coupon',
            'code' => $code,
            'action' => $source->action,
            'value' => $source->value,
            'currency_code' => $source->currency_code,
            'priority' => $source->priority,
            'stop_further' => (bool) $source->stop_further,
            'min_cart' => $source->min_cart,
            'min_qty' => $source->min_qty,
            'max_discount' => $source->max_discount,
            'max_uses' => $maxUses,
            'max_uses_per_customer' => $perCustomer,
            'used_count' => 0,
            'starts_at' => $source->starts_at,
            'expires_at' => $expiresAt,
            'is_enabled' => true,
            'store_id' => $source->store_id,
            'seller_id' => $source->seller_id,
            'parent_id' => $source->id,
        ]);

        return self::map($row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $discountRuleId, int $id): array
    {
        self::assertManage();
        self::parentRule($discountRuleId);
        $row = DiscountRule::query()
            ->where('apply_as', 'coupon')
            ->where('parent_id', $discountRuleId)
            ->find($id);
        if (! $row) {
            throw new UserError('Coupon not found.');
        }
        $snapshot = self::map($row);
        $row->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int}
     */
    public static function bulkDelete(int $discountRuleId, array $ids): array
    {
        self::assertManage();
        self::parentRule($discountRuleId);
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one coupon id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = DiscountRule::query()
                ->where('apply_as', 'coupon')
                ->where('parent_id', $discountRuleId)
                ->find($id);
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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function generate(int $discountRuleId, array $input): array
    {
        self::assertManage();
        $source = self::parentRule($discountRuleId);
        $length = (int) ($input['length'] ?? 0);
        if ($length < 4 || $length > 30) {
            throw new UserError('length must be between 4 and 30.');
        }
        $format = strtolower(trim((string) ($input['format'] ?? '')));
        if (! in_array($format, ['alphabetic', 'alphanumeric', 'numeric'], true)) {
            throw new UserError('format must be alphabetic, alphanumeric, or numeric.');
        }
        $qty = (int) ($input['coupon_qty'] ?? $input['couponQty'] ?? 0);
        if ($qty < 1 || $qty > 100) {
            throw new UserError('coupon_qty must be between 1 and 100.');
        }
        $prefix = strtoupper(trim((string) ($input['prefix'] ?? '')));
        $suffix = strtoupper(trim((string) ($input['suffix'] ?? '')));
        $alphabet = match ($format) {
            'alphabetic' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'numeric' => '0123456789',
            default => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        };
        $codes = [];
        $coupons = [];
        $attempts = 0;
        while (count($codes) < $qty && $attempts < $qty * 20) {
            $attempts++;
            $body = '';
            for ($i = 0; $i < $length; $i++) {
                $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = $prefix.$body.$suffix;
            if ($code === '' || isset($codes[$code]) || DiscountRule::query()->where('code', $code)->exists()) {
                continue;
            }
            $row = DiscountRule::query()->create([
                'name' => ($source->name ?: 'Cart discount rule').' '.$code,
                'apply_as' => 'coupon',
                'code' => $code,
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
                'is_enabled' => true,
                'store_id' => $source->store_id,
                'seller_id' => $source->seller_id,
                'parent_id' => $source->id,
            ]);
            $codes[$code] = true;
            $coupons[] = self::map($row);
        }
        $codeList = array_keys($codes);

        return [
            'ok' => true,
            'generated' => count($codeList),
            'updated_count' => count($codeList),
            'codes' => $codeList,
            'coupons' => $coupons,
            'message' => count($codeList).' coupon(s) generated.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(DiscountRule $row): array
    {
        return [
            'id' => (int) $row->id,

            'code' => $row->code,
            'name' => $row->name,
            'discount_rule_id' => $row->parent_id ? (int) $row->parent_id : null,
            'discountRuleId' => $row->parent_id ? (int) $row->parent_id : null,
            'isPrimary' => false,
            'is_primary' => false,
            'type' => 1,
            'usageLimit' => $row->max_uses,
            'usage_limit' => $row->max_uses,
            'usagePerCustomer' => $row->max_uses_per_customer,
            'usage_per_customer' => $row->max_uses_per_customer,
            'timesUsed' => (int) $row->used_count,
            'times_used' => (int) $row->used_count,
            'expiredAt' => $row->expires_at?->toDateString(),
            'expired_at' => $row->expires_at?->toDateString(),
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
        ];
    }
}
