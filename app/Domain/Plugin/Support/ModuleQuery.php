<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ModuleQuery
{
    /** @var array<string, list<string>> */
    protected array $tables = [
        // Extensions resolve labels/URLs themselves — this API only allowlists reads.
        Capabilities::CATALOG_READ => [
            'products', 'product_translations', 'categories', 'category_translations', 'brands', 'suppliers',
        ],
        Capabilities::CMS_READ => [
            'cms_pages', 'cms_page_translations', 'url_rewrites', 'languages',
            'menus', 'menu_items', 'menu_item_translations',
        ],
        Capabilities::CUSTOMERS_READ => ['customers', 'customer_addresses', 'customer_groups'],
        Capabilities::CARTS_READ => ['carts', 'cart_items'],
        Capabilities::ORDERS_READ => ['orders', 'order_items'],
    ];

    public function __construct(protected PluginManifest $manifest)
    {
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, array $columns, int $limit = 50): array
    {
        $this->assertTable($table);
        $clean = [];
        foreach ($columns as $column) {
            if (is_string($column) && preg_match('/^[a-z][a-z0-9_]{0,40}$/', $column)) {
                $clean[] = $column;
            }
        }
        if ($clean === []) {
            return [];
        }
        $limit = max(1, min(2000, $limit));

        return DB::table($table)->select($clean)->orderBy('id')->limit($limit)->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    protected function assertTable(string $table): void
    {
        $allowed = [];
        foreach ($this->tables as $capability => $tables) {
            if ($this->manifest->can($capability)) {
                $allowed = array_merge($allowed, $tables);
            }
        }
        if (! in_array($table, $allowed, true)) {
            throw new InvalidArgumentException('This app cannot query '.$table.'.');
        }
    }
}
