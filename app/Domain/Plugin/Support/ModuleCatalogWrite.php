<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Localization\Models\Language;
use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Allowlisted catalog writes for extensions. Always goes through ProductService
 * so core validation and action hooks stay in force. No raw DB access.
 */
class ModuleCatalogWrite
{
    /** @var list<string> */
    protected array $allowed = [
        'name', 'sku', 'price', 'sale_price', 'sale_price_type', 'compare_price',
        'stock_qty', 'is_enabled', 'short_description', 'description', 'type',
        'category_ids', 'brand_id', 'tax_class_id', 'default_category_id', 'weight',
    ];

    public function __construct(protected PluginManifest $manifest) {}

    /**
     * Typeahead / dropdown search for products (id, sku, name).
     * Finds a product by name/sku even if it is far down the catalog.
     *
     * @return list<array{id: int, sku: string, name: string, label: string}>
     */
    public function search(string $query = '', int $limit = 25): array
    {
        return $this->paginate($query, 1, $limit)['data'];
    }

    /**
     * Paginated product picker (search + page). Limited columns only.
     *
     * @return array{
     *   data: list<array{id: int, sku: string, name: string, label: string}>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   last_page: int
     * }
     */
    public function paginate(string $query = '', int $page = 1, int $perPage = 20): array
    {
        $this->assertCanRead();
        $perPage = max(1, min(50, $perPage));
        $page = max(1, min(10000, $page));
        $q = trim(strip_tags($query));
        $locale = Language::defaultCode();

        $builder = Product::query()
            ->with(['translations' => fn ($t) => $t->where('language_code', $locale)])
            ->when($q !== '', function ($builder) use ($q, $locale) {
                $like = '%'.$q.'%';
                $builder->where(function ($inner) use ($like, $locale) {
                    $inner->where('sku', 'like', $like)
                        ->orWhereHas('translations', function ($t) use ($like, $locale) {
                            $t->where('language_code', $locale)->where('name', 'like', $like);
                        });
                });
            })
            ->orderBy('id');

        $total = (clone $builder)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $data = $builder
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Product $product) => $this->serializeProduct($product))
            ->values()
            ->all();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Load specific products by id (saved “Buy now on these SKUs” rules).
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, sku: string, name: string, label: string}>
     */
    public function findMany(array $ids): array
    {
        $this->assertCanRead();
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && count($clean) < 100) {
                $clean[] = $id;
            }
        }
        if ($clean === []) {
            return [];
        }
        $locale = Language::defaultCode();

        return Product::query()
            ->with(['translations' => fn ($t) => $t->where('language_code', $locale)])
            ->whereIn('id', $clean)
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product) => $this->serializeProduct($product))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, sku: string, name: string, label: string}
     */
    protected function serializeProduct(Product $product): array
    {
        $name = (string) ($product->translation()?->name ?? $product->sku);

        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => $name,
            'label' => $name.' ('.$product->sku.')',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createProduct(array $input): int
    {
        $this->assertCanWrite();
        $payload = $this->normalize($input, forUpdate: false);
        $product = app(ProductService::class)->save($payload);

        return (int) $product->id;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateProduct(int $id, array $input): void
    {
        $this->assertCanWrite();
        $product = Product::query()->find($id);
        if (! $product) {
            throw ValidationException::withMessages(['id' => 'Product '.$id.' was not found.']);
        }
        $payload = $this->normalize($input, forUpdate: true, existing: $product);
        app(ProductService::class)->save($payload, $product);
    }

    public function deleteProduct(int $id): void
    {
        $this->assertCanWrite();
        $product = Product::query()->find($id);
        if (! $product) {
            throw ValidationException::withMessages(['id' => 'Product '.$id.' was not found.']);
        }
        app(ProductService::class)->delete($product);
    }

    protected function assertCanWrite(): void
    {
        if (! $this->manifest->can(Capabilities::CATALOG_WRITE)) {
            throw new InvalidArgumentException('This app did not declare catalog.write.');
        }
    }

    protected function assertCanRead(): void
    {
        if (! $this->manifest->can(Capabilities::CATALOG_READ) && ! $this->manifest->can(Capabilities::CATALOG_WRITE)) {
            throw new InvalidArgumentException('This app did not declare catalog.read or catalog.write.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalize(array $input, bool $forUpdate, ?Product $existing = null): array
    {
        $clean = [];
        foreach ($this->allowed as $key) {
            if (array_key_exists($key, $input)) {
                $clean[$key] = $input[$key];
            }
        }

        if (array_key_exists('type', $clean) && $clean['type'] !== 'simple' && $clean['type'] !== null && $clean['type'] !== '') {
            throw ValidationException::withMessages(['type' => 'Extensions may only create or update simple products through catalog.write.']);
        }

        $name = trim((string) ($clean['name'] ?? ($existing?->translation()?->name ?? 'Product')));
        $price = $clean['price'] ?? $existing?->price;
        if ($price === null || $price === '') {
            throw ValidationException::withMessages(['price' => 'price is required.']);
        }
        if (! is_numeric($price) || (float) $price < 0) {
            throw ValidationException::withMessages(['price' => 'price must be a number >= 0.']);
        }

        $sku = trim((string) ($clean['sku'] ?? ''));
        if ($sku === '') {
            $sku = $existing?->sku
                ?? Str::upper(Str::slug($name !== '' ? $name : 'product').'-'.Str::lower(Str::random(4)));
        }

        $locale = Language::defaultCode();
        $short = array_key_exists('short_description', $clean)
            ? (string) $clean['short_description']
            : (string) ($existing?->translation($locale)?->short_description ?? '');
        $description = array_key_exists('description', $clean)
            ? (string) $clean['description']
            : (string) ($existing?->translation($locale)?->description ?? '');

        $productRow = [
            'type' => 'simple',
            'sku' => $sku,
            'price' => (float) $price,
            'stock_qty' => (int) ($clean['stock_qty'] ?? $existing?->stock_qty ?? 0),
            'is_enabled' => array_key_exists('is_enabled', $clean)
                ? (bool) $clean['is_enabled']
                : (bool) ($existing?->is_enabled ?? true),
            'visibility' => $existing?->visibility ?? 'both',
            'available_for_order' => $existing?->available_for_order ?? true,
            'show_price' => $existing?->show_price ?? true,
            'manage_stock' => $existing?->manage_stock ?? true,
        ];

        if (array_key_exists('sale_price', $clean)) {
            $sale = $clean['sale_price'];
            if ($sale === null || $sale === '') {
                $productRow['sale_price'] = null;
            } elseif (! is_numeric($sale) || (float) $sale < 0) {
                throw ValidationException::withMessages(['sale_price' => 'sale_price must be a number >= 0.']);
            } else {
                $productRow['sale_price'] = (float) $sale;
            }
        }

        if (array_key_exists('sale_price_type', $clean)) {
            $type = $clean['sale_price_type'];
            if ($type === null || $type === '') {
                $productRow['sale_price_type'] = null;
            } elseif (! in_array($type, ['fixed', 'percent'], true)) {
                throw ValidationException::withMessages(['sale_price_type' => 'sale_price_type must be fixed or percent.']);
            } else {
                $productRow['sale_price_type'] = $type;
            }
        }

        if (array_key_exists('compare_price', $clean)) {
            $compare = $clean['compare_price'];
            if ($compare === null || $compare === '') {
                $productRow['compare_price'] = null;
            } elseif (! is_numeric($compare) || (float) $compare < 0) {
                throw ValidationException::withMessages(['compare_price' => 'compare_price must be a number >= 0.']);
            } else {
                $productRow['compare_price'] = (float) $compare;
            }
        }

        foreach (['brand_id', 'tax_class_id', 'default_category_id'] as $fk) {
            if (! array_key_exists($fk, $clean)) {
                continue;
            }
            $raw = $clean[$fk];
            if ($raw === null || $raw === '') {
                $productRow[$fk] = null;
            } else {
                $id = (int) $raw;
                if ($id <= 0) {
                    throw ValidationException::withMessages([$fk => $fk.' must be a positive id.']);
                }
                $productRow[$fk] = $id;
            }
        }

        if (array_key_exists('weight', $clean)) {
            $weight = $clean['weight'];
            if ($weight === null || $weight === '') {
                $productRow['weight'] = null;
            } elseif (! is_numeric($weight) || (float) $weight < 0) {
                throw ValidationException::withMessages(['weight' => 'weight must be a number >= 0.']);
            } else {
                $productRow['weight'] = (float) $weight;
            }
        }

        $categoryIds = $existing ? $existing->categories()->pluck('categories.id')->all() : [];
        if (array_key_exists('category_ids', $clean) && is_array($clean['category_ids'])) {
            $categoryIds = [];
            foreach ($clean['category_ids'] as $cid) {
                if (count($categoryIds) >= 20) {
                    break;
                }
                $id = (int) $cid;
                if ($id > 0) {
                    $categoryIds[] = $id;
                }
            }
            $categoryIds = array_values(array_unique($categoryIds));
            if ($categoryIds !== []) {
                $found = \App\Domain\Catalog\Models\Category::query()->whereIn('id', $categoryIds)->pluck('id')->all();
                if (count($found) !== count($categoryIds)) {
                    throw ValidationException::withMessages(['category_ids' => 'One or more categories were not found.']);
                }
            }
        }

        if ($forUpdate && $existing) {
            foreach (['sale_price', 'sale_price_type', 'compare_price', 'cost_price', 'brand_id', 'tax_class_id', 'default_category_id', 'weight'] as $keep) {
                if (array_key_exists($keep, $productRow)) {
                    continue;
                }
                if ($existing->{$keep} !== null) {
                    $productRow[$keep] = $existing->{$keep};
                }
            }
        }

        return [
            'product' => $productRow,
            'translations' => [
                $locale => [
                    'name' => $name !== '' ? $name : ($existing?->translation($locale)?->name ?? 'Product'),
                    'slug' => $existing?->translation($locale)?->slug,
                    'short_description' => $short,
                    'description' => $description,
                ],
            ],
            'category_ids' => $categoryIds,
        ];
    }
}
