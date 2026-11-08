<?php

namespace App\Domain\Installer;

use App\Domain\Catalog\Models\Category;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\ShopStats;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\Models\Setting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MillionDataService
{
    public const SKU_PREFIX = 'SCALE-';

    public const EMAIL_DOMAIN = 'scale.load.test';

    public const ORDER_PREFIX = 'SC-';

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'status' => (string) Setting::getValue('million_store_status', 'idle'),
            'installed' => Setting::getValue('million_store_installed') === '1',
            'pid' => (string) Setting::getValue('million_store_pid', ''),
            'error' => (string) Setting::getValue('million_store_error', ''),
            'started_at' => (string) Setting::getValue('million_store_started_at', ''),
            'progress' => $this->progress(),
            'products' => ShopStats::estimate('products'),
            'customers' => ShopStats::estimate('customers'),
            'orders' => ShopStats::estimate('orders'),
            'reviews' => Schema::hasTable('reviews') ? ShopStats::estimate('reviews') : 0,
            'wishlists' => Schema::hasTable('wishlists') ? ShopStats::estimate('wishlists') : 0,
            'running' => $this->isRunning(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(): array
    {
        $path = $this->progressPath();
        if (! is_file($path)) {
            return ['phase' => 'idle'];
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return is_array($raw) ? $raw : ['phase' => 'idle'];
    }

    public function isRunning(): bool
    {
        if ((string) Setting::getValue('million_store_status', '') !== 'running') {
            return false;
        }
        $pid = (int) Setting::getValue('million_store_pid', '0');
        if ($pid < 1) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            exec('tasklist /FI "PID eq '.$pid.'" 2>NUL', $out);

            return collect($out)->contains(fn ($line) => str_contains((string) $line, (string) $pid));
        }

        return file_exists('/proc/'.$pid);
    }

    public function startInBackground(
        int $products = 1_000_000,
        int $customers = 1_000_000,
        int $orders = 1_000_000,
        int $reviews = 1_000_000,
        int $wishlists = 1_000_000,
    ): bool {
        if ($this->isRunning()) {
            return false;
        }

        Setting::setValue('million_store_status', 'running');
        Setting::setValue('million_store_started_at', now()->toIso8601String());
        Setting::setValue('million_store_error', '');

        $log = storage_path('logs/million-store.log');
        if (! is_dir(dirname($log))) {
            mkdir(dirname($log), 0755, true);
        }

        if (app()->runningUnitTests()) {
            Setting::setValue('million_store_status', 'queued');

            return true;
        }

        $cmd = sprintf(
            '%s %s wento:million-store --products=%d --customers=%d --orders=%d --reviews=%d --wishlists=%d --force',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            max(1, $products),
            max(1, $customers),
            max(1, $orders),
            max(0, $reviews),
            max(0, $wishlists),
        );

        if (PHP_OS_FAMILY === 'Windows') {
            $pid = (string) (proc_open('start /B '.$cmd.' >> '.escapeshellarg($log).' 2>&1', [], $pipes) ?: '');
            Setting::setValue('million_store_pid', $pid);

            return true;
        }

        $full = 'nohup '.$cmd.' >> '.escapeshellarg($log).' 2>&1 & echo $!';
        $pid = trim((string) shell_exec($full));
        Setting::setValue('million_store_pid', $pid);

        return $pid !== '';
    }

    /**
     * @param  callable(string, int, int):void|null  $progress
     * @return array{products: int, customers: int, orders: int, reviews: int, wishlists: int}
     */
    public function install(
        int $products = 1_000_000,
        int $customers = 1_000_000,
        int $orders = 1_000_000,
        int $reviews = 1_000_000,
        int $wishlists = 1_000_000,
        int $chunk = 1000,
        ?callable $progress = null,
    ): array {
        $chunk = max(50, min(1000, $chunk));
        Setting::setValue('million_store_status', 'running');
        Setting::setValue('million_store_error', '');

        DB::connection()->disableQueryLog();
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::statement('SET UNIQUE_CHECKS=0');
        }

        try {
            $this->seedCategories();
            $productIds = $this->seedProducts($products, $chunk, $progress);
            $customerIds = $this->seedCustomers($customers, $chunk, $progress);
            $this->seedOrders($orders, $chunk, $productIds, $customerIds, $progress);
            $this->seedReviews($reviews, $chunk, $productIds, $customerIds, $progress);
            $this->seedWishlists($wishlists, $chunk, $productIds, $customerIds, $progress);
        } catch (Throwable $e) {
            Setting::setValue('million_store_status', 'failed');
            Setting::setValue('million_store_error', $e->getMessage());
            $this->writeProgress(['phase' => 'failed', 'error' => $e->getMessage()]);
            if ($mysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
                DB::statement('SET UNIQUE_CHECKS=1');
            }
            throw $e;
        }

        if ($mysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            DB::statement('SET UNIQUE_CHECKS=1');
            foreach (['products', 'product_translations', 'category_product', 'customers', 'customer_addresses', 'orders', 'order_items', 'reviews', 'wishlists'] as $table) {
                if (Schema::hasTable($table)) {
                    try {
                        DB::select('ANALYZE TABLE `'.Schema::getConnection()->getTablePrefix().$table.'`');
                    } catch (Throwable) {
                    }
                }
            }
        }

        ShopStats::flushAll();
        Setting::setValue('million_store_installed', '1');
        Setting::setValue('million_store_status', 'done');
        $this->writeProgress(['phase' => 'done']);

        try {
            if (app(\App\Domain\Search\CatalogSearchIndex::class)->anyEnabled()) {
                dispatch(new \App\Jobs\ReindexCatalogSearch);
            }
        } catch (Throwable) {
        }

        return [
            'products' => ShopStats::estimate('products'),
            'customers' => ShopStats::estimate('customers'),
            'orders' => ShopStats::estimate('orders'),
            'reviews' => Schema::hasTable('reviews') ? ShopStats::estimate('reviews') : 0,
            'wishlists' => Schema::hasTable('wishlists') ? ShopStats::estimate('wishlists') : 0,
        ];
    }

    protected function seedCategories(): void
    {
        $locale = Language::defaultCode();
        if (Category::query()->exists()) {
            return;
        }
        $now = now()->toDateTimeString();
        $id = DB::table('categories')->insertGetId([
            'is_enabled' => true,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('category_translations')->insert([
            'category_id' => $id,
            'language_code' => $locale,
            'name' => 'Scale catalog',
            'slug' => 'scale-catalog',
        ]);
    }

    /**
     * @return array{first: int, count: int}
     */
    protected function seedProducts(int $target, int $chunk, ?callable $progress): array
    {
        $existing = $this->maxSeq('products', 'sku', self::SKU_PREFIX);
        $needed = max(0, $target - $existing);
        $locale = Language::defaultCode();
        $taxId = TaxClass::query()->value('id');
        $categoryIds = Category::query()->orderBy('id')->limit(24)->pluck('id')->all();
        if ($categoryIds === []) {
            $this->seedCategories();
            $categoryIds = Category::query()->orderBy('id')->limit(24)->pluck('id')->all();
        }
        $now = now()->toDateTimeString();
        $storeId = $this->defaultStoreId();

        $this->tick('products', $existing, $target);
        $progress && $progress('products', $existing, $target);

        if ($needed === 0) {
            $first = (int) DB::table('products')->where('sku', 'like', self::SKU_PREFIX.'%')->min('id');

            return ['first' => $first, 'count' => $existing];
        }

        $products = [];
        $translations = [];
        $pivots = [];
        $stores = [];
        $done = 0;

        for ($i = 0; $i < $needed; $i++) {
            $seq = $existing + $i + 1;
            $sku = self::SKU_PREFIX.str_pad((string) $seq, 7, '0', STR_PAD_LEFT);
            $price = 9.99 + (($seq * 37) % 9900);
            $qty = ($seq % 80) + 1;
            $enabled = ($seq % 17) !== 0;
            $catId = $categoryIds[$seq % count($categoryIds)];
            $products[] = $this->only('products', [
                'type' => 'simple',
                'sku' => $sku,
                'tax_class_id' => $taxId,
                'default_category_id' => $catId,
                'price' => $price,
                'compare_price' => $price + 10,
                'stock_qty' => $qty,
                'reserved_qty' => 0,
                'stock_status' => $qty > 0 ? 'in_stock' : 'out_of_stock',
                'manage_stock' => true,
                'min_qty' => null,
                'qty_step' => null,
                'sale_unit' => 'piece',
                'sale_unit_code' => 'pc',
                'visibility' => 'both',
                'available_for_order' => true,
                'show_price' => true,
                'is_enabled' => $enabled,
                'is_new' => ($seq % 40) === 0,
                'is_on_sale' => ($seq % 11) === 0,
                'is_best_seller' => ($seq % 23) === 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $translations[] = $this->only('product_translations', [
                'language_code' => $locale,
                'name' => 'Scale product '.$seq,
                'slug' => 'scale-product-'.$seq,
                'short_description' => 'Load-test product '.$seq,
                'description' => 'Generated row for catalog scale tests.',
                'meta_keywords' => 'scale,load,product',
            ]);
            $pivots[] = [
                'category_id' => $catId,
                'is_default' => true,
            ];
            if ($storeId > 0) {
                $stores[] = ['store_id' => $storeId, 'is_visible' => true];
            }

            if (count($products) === $chunk || $i === $needed - 1) {
                DB::table('products')->insert($products);
                $firstId = $this->firstIdAfterInsert('products', 'sku', $products[0]['sku']);
                foreach ($translations as $index => $row) {
                    $translations[$index]['product_id'] = $firstId + $index;
                    $pivots[$index]['product_id'] = $firstId + $index;
                    if (isset($stores[$index])) {
                        $stores[$index]['product_id'] = $firstId + $index;
                    }
                }
                DB::table('product_translations')->insert($translations);
                DB::table('category_product')->insert($pivots);
                if ($stores !== [] && Schema::hasTable('product_stores')) {
                    DB::table('product_stores')->insert($this->onlyRows('product_stores', $stores));
                }
                $done += count($products);
                $this->tick('products', $existing + $done, $target);
                $progress && $progress('products', $existing + $done, $target);
                $products = [];
                $translations = [];
                $pivots = [];
                $stores = [];
            }
        }

        $first = (int) DB::table('products')->where('sku', 'like', self::SKU_PREFIX.'%')->min('id');
        $count = (int) DB::table('products')->where('sku', 'like', self::SKU_PREFIX.'%')->count();

        return ['first' => $first, 'count' => $count];
    }

    /**
     * @return array{first: int, count: int}
     */
    protected function seedCustomers(int $target, int $chunk, ?callable $progress): array
    {
        $existing = $this->maxEmailSeq();
        $needed = max(0, $target - $existing);
        $password = Hash::make('password');
        $now = now()->toDateTimeString();
        $firstNames = ['Alex', 'Priya', 'Jordan', 'Mei', 'Omar', 'Sofia', 'Rahul', 'Elena', 'Noah', 'Aisha'];
        $lastNames = ['Shah', 'Chen', 'Patel', 'Garcia', 'Khan', 'Silva', 'Park', 'Rossi', 'Nguyen', 'Brown'];
        $storeId = $this->customerStoreId();

        $this->tick('customers', $existing, $target);
        $progress && $progress('customers', $existing, $target);

        if ($needed === 0) {
            $first = (int) DB::table('customers')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->min('id');

            return ['first' => $first, 'count' => $existing];
        }

        $rows = [];
        $addresses = [];
        $done = 0;

        for ($i = 0; $i < $needed; $i++) {
            $seq = $existing + $i + 1;
            $first = $firstNames[$seq % 10];
            $last = $lastNames[($seq * 3) % 10];
            $email = 'scale'.$seq.'@'.self::EMAIL_DOMAIN;
            $rows[] = $this->only('customers', [
                'name' => $first.' '.$last,
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => '90000'.str_pad((string) ($seq % 100000), 5, '0', STR_PAD_LEFT),
                'password' => $password,
                'is_enabled' => ($seq % 19) !== 0,
                'customer_group' => ($seq % 50) === 0 ? 'vip' : 'customer',
                'newsletter' => ($seq % 4) === 0,
                'store_id' => $storeId > 0 ? $storeId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $addresses[] = $this->only('customer_addresses', [
                'label' => 'Home',
                'first_name' => $first,
                'last_name' => $last,
                'phone' => '90000'.str_pad((string) ($seq % 100000), 5, '0', STR_PAD_LEFT),
                'line1' => $seq.' Scale Street',
                'city' => 'Loadville',
                'state' => 'CA',
                'postal_code' => str_pad((string) (10000 + ($seq % 89999)), 5, '0', STR_PAD_LEFT),
                'country_code' => 'US',
                'is_default_billing' => true,
                'is_default_shipping' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (count($rows) === $chunk || $i === $needed - 1) {
                DB::table('customers')->insert($rows);
                $firstId = $this->firstIdAfterInsert('customers', 'email', $rows[0]['email']);
                foreach ($addresses as $index => $address) {
                    $addresses[$index]['customer_id'] = $firstId + $index;
                }
                if (Schema::hasTable('customer_addresses')) {
                    DB::table('customer_addresses')->insert($addresses);
                }
                $done += count($rows);
                $this->tick('customers', $existing + $done, $target);
                $progress && $progress('customers', $existing + $done, $target);
                $rows = [];
                $addresses = [];
            }
        }

        $firstId = (int) DB::table('customers')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->min('id');
        $count = (int) DB::table('customers')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->count();

        return ['first' => $firstId, 'count' => $count];
    }

    /**
     * @param  array{first: int, count: int}  $products
     * @param  array{first: int, count: int}  $customers
     */
    protected function seedOrders(int $target, int $chunk, array $products, array $customers, ?callable $progress): void
    {
        $existing = $this->maxSeq('orders', 'number', self::ORDER_PREFIX);
        $needed = max(0, $target - $existing);
        $this->tick('orders', $existing, $target);
        $progress && $progress('orders', $existing, $target);
        if ($needed === 0 || $products['count'] < 1 || $customers['count'] < 1) {
            return;
        }

        $payId = PaymentMethod::query()->value('id');
        $shipId = ShippingMethod::query()->value('id');
        $currency = (string) (Setting::getValue('default_currency') ?: config('wento.default_currency', 'USD'));
        $statuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];
        $nowTs = time();
        $productFirst = $products['first'];
        $productCount = $products['count'];
        $customerFirst = $customers['first'];
        $customerCount = $customers['count'];

        $orders = [];
        $items = [];
        $done = 0;

        for ($i = 0; $i < $needed; $i++) {
            $seq = $existing + $i + 1;
            $status = $statuses[$seq % 6];
            $qty = ($seq % 4) + 1;
            $unit = 19 + (($seq * 41) % 880);
            $total = $qty * $unit;
            $created = date('Y-m-d H:i:s', $nowTs - ($seq % 864000));
            $custOffset = ($seq - 1) % $customerCount;
            $prodOffset = ($seq - 1) % $productCount;
            $customerId = $customerFirst + $custOffset;
            $productId = $productFirst + $prodOffset;
            $email = 'scale'.($custOffset + 1).'@'.self::EMAIL_DOMAIN;
            $address = json_encode([
                'first_name' => 'Scale',
                'last_name' => (string) $seq,
                'line1' => $seq.' Scale Street',
                'city' => 'Loadville',
                'country_code' => 'US',
            ]);
            $paymentStatus = in_array($status, ['pending', 'refunded'], true) ? 'pending' : ($status === 'cancelled' ? 'failed' : 'paid');

            $orders[] = $this->only('orders', [
                'number' => self::ORDER_PREFIX.str_pad((string) $seq, 9, '0', STR_PAD_LEFT),
                'customer_id' => $customerId,
                'email' => $email,
                'status' => $status,
                'currency_code' => $currency !== '' ? $currency : 'USD',
                'subtotal' => $total,
                'tax_total' => 0,
                'shipping_total' => 0,
                'discount_total' => 0,
                'grand_total' => $total,
                'payment_method_id' => $payId,
                'shipping_method_id' => $shipId,
                'payment_status' => $paymentStatus,
                'billing_address' => $address,
                'shipping_address' => $address,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
            $items[] = $this->only('order_items', [
                'product_id' => $productId,
                'sku' => self::SKU_PREFIX.str_pad((string) ($prodOffset + 1), 7, '0', STR_PAD_LEFT),
                'name' => 'Scale product '.($prodOffset + 1),
                'qty' => $qty,
                'unit_price' => $unit,
                'total' => $total,
                'created_at' => $created,
                'updated_at' => $created,
            ]);

            if (count($orders) === $chunk || $i === $needed - 1) {
                DB::table('orders')->insert($orders);
                $firstId = $this->firstIdAfterInsert('orders', 'number', $orders[0]['number']);
                foreach ($items as $index => $item) {
                    $items[$index]['order_id'] = $firstId + $index;
                }
                DB::table('order_items')->insert($items);
                $done += count($orders);
                $this->tick('orders', $existing + $done, $target);
                $progress && $progress('orders', $existing + $done, $target);
                $orders = [];
                $items = [];
            }
        }
    }

    /**
     * @param  array{first: int, count: int}  $products
     * @param  array{first: int, count: int}  $customers
     */
    protected function seedReviews(int $target, int $chunk, array $products, array $customers, ?callable $progress): void
    {
        if ($target < 1 || ! Schema::hasTable('reviews') || $products['count'] < 1 || $customers['count'] < 1) {
            return;
        }
        $existing = (int) DB::table('reviews')->where('title', 'Scale review')->count();
        $maxPairs = min($target, $products['count'] * $customers['count']);
        $needed = max(0, min($maxPairs, $target) - $existing);
        $this->tick('reviews', $existing, $target);
        $progress && $progress('reviews', $existing, $target);
        if ($needed === 0) {
            return;
        }

        $now = now()->toDateTimeString();
        $rows = [];
        $done = 0;
        $start = $existing;

        for ($i = 0; $i < $needed; $i++) {
            $seq = $start + $i;
            $customerId = $customers['first'] + ($seq % $customers['count']);
            $productId = $products['first'] + intdiv($seq, $customers['count']) % $products['count'];
            $rows[] = $this->only('reviews', [
                'product_id' => $productId,
                'customer_id' => $customerId,
                'rating' => ($seq % 5) + 1,
                'title' => 'Scale review',
                'body' => 'Generated review for load testing.',
                'is_approved' => true,
                'status' => 'approved',
                'is_verified_purchase' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (count($rows) === $chunk || $i === $needed - 1) {
                try {
                    DB::table('reviews')->insert($rows);
                } catch (Throwable) {
                    foreach ($rows as $row) {
                        try {
                            DB::table('reviews')->insert($row);
                        } catch (Throwable) {
                        }
                    }
                }
                $done += count($rows);
                $this->tick('reviews', $existing + $done, $target);
                $progress && $progress('reviews', $existing + $done, $target);
                $rows = [];
            }
        }
    }

    /**
     * @param  array{first: int, count: int}  $products
     * @param  array{first: int, count: int}  $customers
     */
    protected function seedWishlists(int $target, int $chunk, array $products, array $customers, ?callable $progress): void
    {
        if ($target < 1 || ! Schema::hasTable('wishlists') || $products['count'] < 1 || $customers['count'] < 1) {
            return;
        }
        $existing = (int) DB::table('wishlists')->count();
        $maxPairs = min($target, $products['count'] * $customers['count']);
        $needed = max(0, min($maxPairs, $target) - $existing);
        $this->tick('wishlists', $existing, $target);
        $progress && $progress('wishlists', $existing, $target);
        if ($needed === 0) {
            return;
        }

        $now = now()->toDateTimeString();
        $rows = [];
        $done = 0;
        $start = $existing;

        for ($i = 0; $i < $needed; $i++) {
            $seq = $start + $i;
            $rows[] = [
                'customer_id' => $customers['first'] + ($seq % $customers['count']),
                'product_id' => $products['first'] + (intdiv($seq, $customers['count']) % $products['count']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($rows) === $chunk || $i === $needed - 1) {
                try {
                    DB::table('wishlists')->insert($rows);
                } catch (Throwable) {
                    foreach ($rows as $row) {
                        try {
                            DB::table('wishlists')->insert($row);
                        } catch (Throwable) {
                        }
                    }
                }
                $done += count($rows);
                $this->tick('wishlists', $existing + $done, $target);
                $progress && $progress('wishlists', $existing + $done, $target);
                $rows = [];
            }
        }
    }

    protected function maxSeq(string $table, string $column, string $prefix): int
    {
        $value = DB::table($table)->where($column, 'like', $prefix.'%')->orderByDesc('id')->value($column);
        if (! is_string($value) || ! str_starts_with($value, $prefix)) {
            return 0;
        }

        return (int) ltrim(substr($value, strlen($prefix)), '0') ?: (int) substr($value, strlen($prefix));
    }

    protected function maxEmailSeq(): int
    {
        $email = DB::table('customers')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->orderByDesc('id')->value('email');
        if (! is_string($email) || ! preg_match('/^scale(\d+)@/', $email, $m)) {
            return 0;
        }

        return (int) $m[1];
    }

    protected function firstIdAfterInsert(string $table, string $column, string $value): int
    {
        return (int) DB::table($table)->where($column, $value)->value('id');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function only(string $table, array $row): array
    {
        static $cols = [];
        if (! isset($cols[$table])) {
            $cols[$table] = array_flip(Schema::getColumnListing($table));
        }

        return array_intersect_key($row, $cols[$table]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function onlyRows(string $table, array $rows): array
    {
        return array_map(fn (array $row) => $this->only($table, $row), $rows);
    }

    protected function defaultStoreId(): int
    {
        if (! Schema::hasTable('stores')) {
            return 0;
        }

        return (int) DB::table('stores')->orderBy('id')->value('id');
    }

    protected function customerStoreId(): int
    {
        if (! Schema::hasColumn('customers', 'store_id')) {
            return 0;
        }

        return $this->defaultStoreId();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function writeProgress(array $payload): void
    {
        $dir = dirname($this->progressPath());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->progressPath(), json_encode($payload + ['at' => now()->toIso8601String()]));
    }

    protected function tick(string $phase, int $done, int $target): void
    {
        $this->writeProgress([
            'phase' => $phase,
            'done' => $done,
            'target' => $target,
        ]);
    }

    protected function progressPath(): string
    {
        return storage_path('app/million-store-progress.json');
    }
}
