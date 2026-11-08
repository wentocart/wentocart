<?php

namespace App\Http\Controllers\Admin\Multivendor\Concerns;

use App\Domain\Marketplace\Models\Seller;
use Illuminate\Http\Request;

trait PresentsMultivendor
{
    /** @return list<array{id: int, label: string, slug: string, status: string}> */
    protected function sellerOptions(?string $status = null): array
    {
        return Seller::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('shop_name')
            ->get(['id', 'shop_name', 'shop_slug', 'status'])
            ->map(fn (Seller $seller) => [
                'id' => $seller->id,
                'label' => $seller->shop_name,
                'slug' => $seller->shop_slug,
                'status' => $seller->status,
            ])
            ->all();
    }

    protected function sellerId(Request $request): ?int
    {
        $value = $request->query('seller_id');
        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        return (int) $value;
    }

    protected function listFilters(Request $request): array
    {
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $columns = [
            'search', 'shop', 'seller', 'customer', 'created_at', 'name', 'sku', 'number',
            'email', 'shop_name', 'shop_slug', 'city', 'country_code', 'profile_name', 'code', 'order_number',
            'customer_email', 'title', 'type', 'account_name', 'entry_type', 'reference',
            'scope', 'action', 'slug', 'direction', 'target', 'commission_source', 'commission_type',
            'customer_name', 'preview',
        ];

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', 'all'),
            'seller_id' => $request->query('seller_id', 'all'),
            'id_min' => trim((string) $request->query('id_min', '')),
            'id_max' => trim((string) $request->query('id_max', '')),
            'sort' => trim((string) $request->query('sort', 'id')),
            'dir' => $dir,
        ];

        foreach ($columns as $key) {
            $filters[$key] = trim((string) $request->query($key, ''));
        }

        foreach ([
            'price', 'stock_qty', 'amount', 'rating', 'value', 'quantity', 'refund_total',
            'grand_total', 'earning_total', 'gross_amount', 'commission_amount', 'net_amount',
            'commission_rate', 'rate', 'live_rate',
        ] as $key) {
            $filters[$key.'_min'] = trim((string) $request->query($key.'_min', ''));
            $filters[$key.'_max'] = trim((string) $request->query($key.'_max', ''));
        }

        return $filters;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function applySellerColumnFilters($query, array $filters): void
    {
        if ($filters['shop'] !== '') {
            $needle = $filters['shop'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('shop_name', 'like', '%'.$needle.'%')
                    ->orWhere('shop_slug', 'like', '%'.$needle.'%');
            });
        }

        if ($filters['seller'] !== '') {
            $needle = $filters['seller'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('first_name', 'like', '%'.$needle.'%')
                    ->orWhere('last_name', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%');
            });
        }

        if ($filters['customer'] !== '') {
            $needle = $filters['customer'];
            $query->whereHas('customer', fn ($customer) => $customer
                ->where('email', 'like', '%'.$needle.'%')
                ->orWhere('name', 'like', '%'.$needle.'%')
                ->orWhere('first_name', 'like', '%'.$needle.'%')
                ->orWhere('last_name', 'like', '%'.$needle.'%'));
        }

        if ($filters['created_at'] !== '') {
            $this->applyOnDate($query, $filters['created_at'], 'created_at');
        }

        if ($filters['profile_name'] !== '') {
            $query->where('profile_name', 'like', '%'.$filters['profile_name'].'%');
        }

        if ($filters['shop_name'] !== '') {
            $needle = $filters['shop_name'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('shop_name', 'like', '%'.$needle.'%')
                    ->orWhere('shop_slug', 'like', '%'.$needle.'%');
            });
        }

        if ($filters['shop_slug'] !== '') {
            $query->where('shop_slug', 'like', '%'.$filters['shop_slug'].'%');
        }

        if ($filters['city'] !== '') {
            $query->where('city', 'like', '%'.$filters['city'].'%');
        }

        if ($filters['email'] !== '') {
            $query->where('email', 'like', '%'.$filters['email'].'%');
        }

        if (($filters['country_code'] ?? '') !== '') {
            $query->where('country_code', 'like', '%'.$filters['country_code'].'%');
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, string|list<string>>  $map
     */
    protected function applyMappedFilters($query, array $filters, array $map): void
    {
        foreach ($map as $key => $column) {
            if (str_ends_with($key, '_range')) {
                $this->applyRange($query, $filters, substr($key, 0, -6), is_array($column) ? $column[0] : $column);

                continue;
            }

            if ($key === 'created_at') {
                $this->applyOnDate($query, (string) ($filters[$key] ?? ''), is_array($column) ? $column[0] : $column);

                continue;
            }

            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $this->applyLike($query, $value, is_array($column) ? $column : [$column]);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  list<string>  $columns
     */
    protected function applyLike($query, string $value, array $columns): void
    {
        $value = trim($value);
        if ($value === '' || $columns === []) {
            return;
        }

        $query->where(function ($inner) use ($value, $columns) {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $inner->{$method}($column, 'like', '%'.$value.'%');
            }
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function applyRange($query, array $filters, string $key, string $column): void
    {
        $min = $filters[$key.'_min'] ?? '';
        $max = $filters[$key.'_max'] ?? '';
        if ($min !== '' && is_numeric($min)) {
            $query->where($column, '>=', $min);
        }
        if ($max !== '' && is_numeric($max)) {
            $query->where($column, '<=', $max);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function applyOnDate($query, string $value, string $column = 'created_at'): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $query->whereDate($column, $value);

            return;
        }

        $query->where($column, 'like', '%'.$value.'%');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  list<string>  $allowed
     * @param  array<string, string>  $map
     */
    protected function applySellerPeopleSort($query, array $filters, array $allowed, array $map = []): void
    {
        $sort = $filters['sort'] ?? 'id';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'seller') {
            $query->orderBy('first_name', $dir)->orderBy('last_name', $dir)->orderByDesc('id');

            return;
        }

        if ($sort === 'customer') {
            $customers = (new \App\Domain\Customer\Models\Customer)->getTable();
            $sellers = (new Seller)->getTable();
            $query->orderBy(
                \App\Domain\Customer\Models\Customer::query()
                    ->select('email')
                    ->whereColumn($customers.'.id', $sellers.'.customer_id')
                    ->limit(1),
                $dir
            )->orderByDesc('id');

            return;
        }

        $this->applyListOrdering($query, $filters, $allowed, $map);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function applyListIdRange($query, array $filters): void
    {
        if ($filters['id_min'] !== '') {
            $query->where('id', '>=', (int) $filters['id_min']);
        }
        if ($filters['id_max'] !== '') {
            $query->where('id', '<=', (int) $filters['id_max']);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  list<string>  $allowed
     * @param  array<string, string>  $map
     */
    protected function applyListOrdering($query, array $filters, array $allowed = ['id', 'name', 'created_at'], array $map = []): void
    {
        $requested = $filters['sort'] ?? 'id';
        $sort = in_array($requested, $allowed, true) ? $requested : 'id';
        if (isset($map[$sort])) {
            $sort = $map[$sort];
        }
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);
        if ($sort !== 'id') {
            $query->orderByDesc('id');
        }
    }

    /** @return array<string, mixed> */
    protected function sellerRow(Seller $seller): array
    {
        return [
            'id' => $seller->id,
            'status' => $seller->status,
            'shop_name' => $seller->shop_name,
            'shop_slug' => $seller->shop_slug,
            'first_name' => $seller->first_name,
            'last_name' => $seller->last_name,
            'profile_name' => $seller->profile_name,
            'email' => $seller->email,
            'phone' => $seller->phone,
            'created_at' => $seller->created_at?->toDateTimeString(),
            'reviewed_at' => $seller->reviewed_at?->toDateTimeString(),
            'customer' => [
                'id' => $seller->customer?->id,
                'name' => $seller->customer?->displayName(),
                'email' => $seller->customer?->email,
            ],
        ];
    }
}
