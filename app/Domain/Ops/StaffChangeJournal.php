<?php

namespace App\Domain\Ops;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ops\Models\Operation;
use App\Domain\Order\Models\Order;
use App\Domain\Seo\SeoFixJournal;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffChangeJournal
{
    public const ABILITY = 'staff.entity.change';

    protected int $silent = 0;

    /** @var array<string, array<string, mixed>> */
    protected array $items = [];

    public function silence(): void
    {
        $this->silent++;
    }

    public function unsilence(): void
    {
        $this->silent = max(0, $this->silent - 1);
    }

    public function listening(): bool
    {
        if ($this->silent > 0) {
            return false;
        }
        if (app(SeoFixJournal::class)->isRecording()) {
            return false;
        }
        if (! Auth::guard('admin')->check()) {
            return false;
        }
        $method = strtoupper((string) request()->method());

        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function observe(Model $model, string $action): void
    {
        if (! $this->listening()) {
            return;
        }
        $watch = $this->watch()[$model::class] ?? null;
        if ($watch === null) {
            return;
        }

        $fields = $this->changedFields($model, $action, $watch['fields']);
        if ($fields['before'] === [] && $fields['after'] === []) {
            return;
        }

        $id = (int) ($model->getKey() ?: 0);
        if ($id < 1 && $action !== 'create') {
            return;
        }

        $key = $watch['entity'].':'.$id.':'.$action;
        $label = $this->label($model, $watch);
        $this->items[$key] = [
            'entity_type' => $watch['entity'],
            'entity_id' => $id,
            'action' => $action,
            'label' => Str::limit($label, 255, ''),
            'before' => [
                'table' => $watch['table'],
                'id' => $id,
                'fields' => $fields['before'],
            ],
            'after' => [
                'table' => $watch['table'],
                'id' => $id,
                'fields' => $fields['after'],
            ],
            'reversible' => $action === 'update',
        ];
    }

    public function flush(): ?Operation
    {
        $rows = array_values($this->items);
        $this->items = [];
        if ($rows === []) {
            return null;
        }

        $admin = Auth::guard('admin')->user();
        $reversible = collect($rows)->contains(fn (array $row) => $row['reversible']);
        $summary = $this->summary($rows);

        return DB::transaction(function () use ($rows, $admin, $reversible, $summary) {
            $operation = Operation::query()->create([
                'uuid' => (string) Str::uuid(),
                'store_id' => app(StoreContext::class)->id() ?: null,
                'ability' => self::ABILITY,
                'source' => 'admin',
                'actor_type' => $admin instanceof Admin ? 'admin' : 'system',
                'actor_id' => $admin instanceof Admin ? $admin->id : null,
                'status' => 'completed',
                'dry_run' => false,
                'reversible' => $reversible,
                'summary' => Str::limit($summary, 255, ''),
                'input' => ['source' => 'staff'],
                'result' => ['count' => count($rows)],
            ]);
            foreach ($rows as $row) {
                $operation->items()->create([
                    'entity_type' => $row['entity_type'],
                    'entity_id' => $row['entity_id'],
                    'action' => $row['action'],
                    'label' => $row['label'],
                    'before_state' => $row['before'],
                    'after_state' => $row['after'],
                    'reversible' => $row['reversible'],
                ]);
            }

            return $operation->load('items');
        });
    }

    /**
     * @return array<class-string<Model>, array{entity: string, table: string, fields: list<string>, label: list<string>}>
     */
    protected function watch(): array
    {
        return [
            Product::class => [
                'entity' => 'product',
                'table' => 'products',
                'fields' => [
                    'sku', 'type', 'price', 'compare_price', 'sale_price', 'sale_price_type',
                    'is_enabled', 'brand_id', 'tax_class_id', 'default_category_id', 'visibility',
                    'weight', 'sale_unit', 'sale_unit_code', 'min_qty', 'max_qty', 'qty_step',
                    'manage_stock', 'allow_backorder', 'available_for_order', 'show_price',
                ],
                'label' => ['sku'],
            ],
            ProductTranslation::class => [
                'entity' => 'product_translation',
                'table' => 'product_translations',
                'fields' => ['name', 'slug', 'meta_title', 'meta_description', 'meta_keywords', 'focus_keyword', 'canonical_url', 'robots'],
                'label' => ['name', 'slug'],
            ],
            Category::class => [
                'entity' => 'category',
                'table' => 'categories',
                'fields' => ['parent_id', 'is_enabled', 'position', 'image_path'],
                'label' => ['id'],
            ],
            Brand::class => [
                'entity' => 'brand',
                'table' => 'brands',
                'fields' => ['code', 'website', 'is_enabled', 'position', 'email', 'phone'],
                'label' => ['code'],
            ],
            CmsPage::class => [
                'entity' => 'cms_page',
                'table' => 'cms_pages',
                'fields' => ['is_enabled', 'status', 'is_visible', 'template', 'cms_category_id', 'robots', 'canonical_url'],
                'label' => ['id'],
            ],
            Customer::class => [
                'entity' => 'customer',
                'table' => 'customers',
                'fields' => ['name', 'first_name', 'last_name', 'email', 'phone', 'is_enabled', 'newsletter', 'customer_group_id'],
                'label' => ['email', 'name'],
            ],
            Order::class => [
                'entity' => 'order',
                'table' => 'orders',
                'fields' => ['status', 'payment_status', 'email', 'assigned_admin_id'],
                'label' => ['number'],
            ],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    protected function changedFields(Model $model, string $action, array $keys): array
    {
        $before = [];
        $after = [];
        foreach ($keys as $key) {
            if ($action === 'create') {
                $value = $this->scalarize($model->getAttribute($key));
                if ($value !== null && $value !== '') {
                    $after[$key] = $value;
                }

                continue;
            }
            if ($action === 'delete') {
                $value = $this->scalarize($model->getOriginal($key) ?? $model->getAttribute($key));
                if ($value !== null && $value !== '') {
                    $before[$key] = $value;
                }

                continue;
            }
            if (! $model->wasChanged($key)) {
                continue;
            }
            $before[$key] = $this->scalarize($model->getOriginal($key));
            $after[$key] = $this->scalarize($model->getAttribute($key));
        }

        return ['before' => $before, 'after' => $after];
    }

    protected function scalarize(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (is_string($value)) {
            return Str::limit($value, 2000, '');
        }

        return null;
    }

    /**
     * @param  array{label: list<string>}  $watch
     */
    protected function label(Model $model, array $watch): string
    {
        foreach ($watch['label'] as $key) {
            $value = trim((string) ($model->getAttribute($key) ?? $model->getOriginal($key) ?? ''));
            if ($value !== '' && $value !== '0') {
                return $value;
            }
        }

        return '#'.(string) $model->getKey();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function summary(array $rows): string
    {
        $count = count($rows);
        $first = $rows[0];
        $action = (string) $first['action'];
        $verb = $action === 'create' ? 'Created' : ($action === 'delete' ? 'Deleted' : 'Updated');
        if ($count === 1) {
            return $verb.' '.$first['entity_type'].' '.$first['label'];
        }

        return 'Staff changes · '.$count.' records';
    }
}
