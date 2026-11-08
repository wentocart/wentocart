<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Localization\Models\Language;
use App\Domain\Plugin\ActionCatalog;
use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\ExtensionCatalog;
use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\PluginManifest;
use App\Support\Hooks\HookManager;
use InvalidArgumentException;

class ModuleBuses
{
    /** @var array<string, list<callable>> */
    protected static array $lists = [];

    /** @var array<string, list<callable>> */
    protected static array $slots = [];

    /** @var list<array<string, mixed>> */
    protected static array $menus = [];

    /** @var array<string, array{class: string, vendor: string, name: string}> */
    protected static array $adminPages = [];

    /** @var array<string, array{class: string, vendor: string, name: string, auth: bool}> */
    protected static array $frontPages = [];

    /** @var list<array{manifest: PluginManifest, callback: callable}> */
    protected static array $checkoutAdjusters = [];

    /** @var list<array{manifest: PluginManifest, callback: callable}> */
    protected static array $paymentFilters = [];

    /** @var list<array{manifest: PluginManifest, callback: callable}> */
    protected static array $shippingFilters = [];

    /** @var list<array{manifest: PluginManifest, callback: callable}> */
    protected static array $loginResolvers = [];

    /** @var list<array{manifest: PluginManifest, callback: callable}> */
    protected static array $registerConfigurers = [];

    /** @var list<array{manifest: PluginManifest, callback: callable}> */
    protected static array $registerListeners = [];

    /** @var list<array{manifest: PluginManifest, callback: callable}> */
    protected static array $scheduleTicks = [];

    public static function flush(): void
    {
        self::$lists = [];
        self::$slots = [];
        self::$menus = [];
        self::$adminPages = [];
        self::$frontPages = [];
        self::$checkoutAdjusters = [];
        self::$paymentFilters = [];
        self::$shippingFilters = [];
        self::$loginResolvers = [];
        self::$registerConfigurers = [];
        self::$registerListeners = [];
        self::$scheduleTicks = [];
    }

    public static function extendList(PluginManifest $manifest, string $list, callable $callback): void
    {
        if (! $manifest->can('list.extend')) {
            throw new InvalidArgumentException('This app did not declare list.extend.');
        }
        if (! ExtensionCatalog::hasList($list)) {
            throw new InvalidArgumentException('Unknown list '.$list.'.');
        }
        self::$lists[$list][] = $callback;
    }

    /**
     * @return array{columns: list<array<string, mixed>>}
     */
    public static function applyList(string $list, object $query): array
    {
        $definition = ExtensionCatalog::lists()[$list] ?? null;
        if (! $definition) {
            return ['columns' => []];
        }
        $draft = new ListDraft($definition);
        foreach (self::$lists[$list] ?? [] as $callback) {
            $callback($draft);
        }
        $appliedJoins = [];
        foreach ($draft->joins() as $key) {
            $join = $definition['joins'][$key] ?? null;
            if (! $join || isset($appliedJoins[$key])) {
                continue;
            }
            $appliedJoins[$key] = true;
            $wheres = is_array($join['wheres'] ?? null) ? $join['wheres'] : [];
            if ($wheres === []) {
                $query->leftJoin($join['table'], $join['first'], '=', $join['second']);

                continue;
            }
            $query->leftJoin($join['table'], function ($builder) use ($join, $wheres) {
                $builder->on($join['first'], '=', $join['second']);
                foreach ($wheres as $column => $value) {
                    if (! is_string($column) || ! preg_match('/^[a-z_]+\.[a-z_]+$/', $column)) {
                        continue;
                    }
                    if ($value === '@locale') {
                        $builder->where($column, Language::defaultCode());
                    } elseif (is_string($value) && preg_match('/^[a-z0-9_-]+$/', $value)) {
                        $builder->where($column, $value);
                    }
                }
            });
        }
        $selects = [];
        foreach ($draft->selects() as $select) {
            $expr = $select['expr'];
            $alias = $select['alias'];
            if (! in_array($expr, $definition['selects'], true)) {
                continue;
            }
            $selects[] = $expr.' as '.$alias;
        }
        if ($selects !== []) {
            $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;
            if (empty($base->columns)) {
                $query->addSelect($definition['table'].'.*');
            }
            foreach ($selects as $select) {
                $query->addSelect($select);
            }
        }

        return ['columns' => $draft->columns()];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, string>
     */
    public static function rowValues(object $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $column) {
            $alias = $column['alias'] ?? $column['key'];
            $raw = $row->getAttribute($alias);
            $values[$column['key']] = PageDocument::plain(is_scalar($raw) || $raw === null ? (string) ($raw ?? '') : '');
        }

        return $values;
    }

    public static function slot(PluginManifest $manifest, string $area, callable $callback): void
    {
        if (! ExtensionCatalog::hasView($area)) {
            throw new InvalidArgumentException('Unknown slot '.$area.'.');
        }
        $ok = match (true) {
            str_starts_with($area, 'admin.') => $manifest->can('view.slot'),
            $area === 'storefront.account.menu' => $manifest->can('account.menu') || $manifest->can('view.slot'),
            str_starts_with($area, 'storefront.') => $manifest->can('storefront.slot') || $manifest->can('view.slot'),
            default => false,
        };
        if (! $ok) {
            throw new InvalidArgumentException('This app did not declare a slot capability for '.$area.'.');
        }
        self::$slots[$area][] = $callback;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public static function collectSlots(string $area, array $context = []): array
    {
        $blocks = [];
        foreach (self::$slots[$area] ?? [] as $callback) {
            $result = $callback($context);
            if (is_array($result)) {
                foreach ($result as $block) {
                    if (is_array($block)) {
                        $blocks[] = $block;
                    }
                }
            }
        }

        return PageDocument::blocks($blocks);
    }

    /**
     * Product buy box visibility. An extension returns hide flags, not blocks.
     *
     * @param  array<string, mixed>  $context
     * @return array{hide_price: bool, hide_qty: bool, hide_cart: bool}
     */
    public static function productBuybox(array $context): array
    {
        $flags = [
            'hide_price' => false,
            'hide_qty' => false,
            'hide_cart' => false,
        ];
        foreach (self::$slots['storefront.product.buybox'] ?? [] as $callback) {
            $result = $callback($context);
            if (! is_array($result)) {
                continue;
            }
            $rows = array_is_list($result) ? $result : [$result];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (array_keys($flags) as $key) {
                    if (($row[$key] ?? false) === true) {
                        $flags[$key] = true;
                    }
                }
            }
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public static function menu(PluginManifest $manifest, array $group): void
    {
        if (! $manifest->can('admin.menu')) {
            throw new InvalidArgumentException('This app did not declare admin.menu.');
        }
        $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($group['id'] ?? $manifest->name))) ?? $manifest->name;
        $items = [];
        foreach ((array) ($group['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $page = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($item['page'] ?? ''))) ?? '';
            if ($page === '') {
                continue;
            }
            $itemLabel = PageDocument::plain((string) ($item['label'] ?? $page));
            $itemKey = isset($item['label_key'])
                ? PageDocument::plain((string) $item['label_key'])
                : 'admin.module_nav.'.$manifest->vendor.'.'.$manifest->name.'.'.$page;
            $items[] = [
                'href' => '/admin/modules/'.$manifest->vendor.'/'.$manifest->name.'/'.$page,
                'label' => $itemLabel,
                'label_key' => $itemKey !== '' ? $itemKey : null,
                'permission' => '*',
            ];
        }
        if ($items === []) {
            return;
        }
        $groupLabel = PageDocument::plain((string) ($group['label'] ?? $manifest->displayName));
        $groupKey = isset($group['label_key'])
            ? PageDocument::plain((string) $group['label_key'])
            : 'admin.module_nav.'.$manifest->vendor.'.'.$manifest->name;
        self::$menus[] = [
            'id' => 'mod-'.$manifest->vendor.'-'.$id,
            'label' => $groupLabel,
            'label_key' => $groupKey !== '' ? $groupKey : null,
            'icon' => PageDocument::iconName($group['icon'] ?? 'package', 'package'),
            'items' => $items,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function menus(): array
    {
        return self::$menus;
    }

    public static function adminPage(PluginManifest $manifest, string $page, string $class): void
    {
        if (! $manifest->can('admin.pages')) {
            throw new InvalidArgumentException('This app did not declare admin.pages.');
        }
        $page = preg_replace('/[^a-z0-9-]/', '', strtolower($page)) ?? '';
        self::assertOwnClass($manifest, $class);
        self::$adminPages[$manifest->vendor.'/'.$manifest->name.'/'.$page] = [
            'class' => $class,
            'vendor' => $manifest->vendor,
            'name' => $manifest->name,
        ];
    }

    public static function frontPage(PluginManifest $manifest, string $page, string $class, bool $auth = true): void
    {
        if (! $manifest->can('storefront.pages')) {
            throw new InvalidArgumentException('This app did not declare storefront.pages.');
        }
        $page = preg_replace('/[^a-z0-9-]/', '', strtolower($page)) ?? '';
        self::assertOwnClass($manifest, $class);
        self::$frontPages[$manifest->vendor.'/'.$manifest->name.'/'.$page] = [
            'class' => $class,
            'vendor' => $manifest->vendor,
            'name' => $manifest->name,
            'auth' => $auth,
        ];
    }

    /**
     * @return array{class: string, vendor: string, name: string}|null
     */
    public static function findAdminPage(string $vendor, string $name, string $page): ?array
    {
        return self::$adminPages[$vendor.'/'.$name.'/'.$page] ?? null;
    }

    /**
     * Prefer /configure when registered; otherwise the first screen page.
     * File downloads and one-shot order actions are not a Configure destination.
     */
    public static function primaryAdminHref(string $vendor, string $name): ?string
    {
        $vendor = strtolower($vendor);
        $name = strtolower($name);
        $prefix = $vendor.'/'.$name.'/';
        $skip = ['download-log', 'capture', 'void'];
        $first = null;
        foreach (array_keys(self::$adminPages) as $key) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }
            $page = substr($key, strlen($prefix));
            if ($page === '' || in_array($page, $skip, true)) {
                continue;
            }
            $href = '/admin/modules/'.$vendor.'/'.$name.'/'.$page;
            if ($page === 'configure') {
                return $href;
            }
            $first ??= $href;
        }

        return $first;
    }

    /**
     * @return array{class: string, vendor: string, name: string, auth: bool}|null
     */
    public static function findFrontPage(string $vendor, string $name, string $page): ?array
    {
        return self::$frontPages[$vendor.'/'.$name.'/'.$page] ?? null;
    }

    public static function before(PluginManifest $manifest, string $action, callable $callback): void
    {
        self::listenAction($manifest, $action, 'before', $callback);
    }

    public static function after(PluginManifest $manifest, string $action, callable $callback): void
    {
        self::listenAction($manifest, $action, 'after', $callback);
    }

    /**
     * Register a checkout totals adjuster (fees / due-today). Requires checkout.adjust.
     */
    public static function checkoutAdjust(PluginManifest $manifest, callable $callback): void
    {
        if (! $manifest->can(Capabilities::CHECKOUT_ADJUST)) {
            throw new InvalidArgumentException('This app did not declare checkout.adjust.');
        }
        self::$checkoutAdjusters[] = [
            'manifest' => $manifest,
            'callback' => $callback,
        ];
    }

    /**
     * @param  array<string, mixed>  $context  subtotal, discount, tax, shipping, grand_total, cart, customer, cart_meta
     * @return array{fees: float, credits: float, due_now: ?float, adjustments: list<array{code: string, label: string, type: string, amount: float}>}
     */
    public static function collectCheckoutAdjustments(array $context): array
    {
        $baseGrand = round((float) ($context['grand_total'] ?? 0), 2);
        $adjustments = [];
        $feeTotal = 0.0;
        $creditTotal = 0.0;
        $dueNow = null;

        foreach (self::$checkoutAdjusters as $entry) {
            $result = ($entry['callback'])($context);
            if (! is_array($result)) {
                continue;
            }
            foreach ($result as $row) {
                if (! is_array($row) || count($adjustments) >= 10) {
                    break;
                }
                $type = (string) ($row['type'] ?? '');
                if (! in_array($type, ['fee', 'due_now', 'credit'], true)) {
                    continue;
                }
                $code = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($row['code'] ?? ''))) ?? '';
                if ($code === '' || strlen($code) > 48) {
                    continue;
                }
                $label = PageDocument::plain((string) ($row['label'] ?? $code));
                if ($label === '') {
                    $label = $code;
                }
                $amount = round((float) ($row['amount'] ?? 0), 2);
                if ($amount < 0) {
                    continue;
                }
                if ($type === 'fee') {
                    if ($amount > $baseGrand && $baseGrand > 0) {
                        $amount = $baseGrand;
                    }
                    if ($amount <= 0) {
                        continue;
                    }
                    $feeTotal = round($feeTotal + $amount, 2);
                    $adjustments[] = [
                        'code' => $code,
                        'label' => $label,
                        'type' => 'fee',
                        'amount' => $amount,
                    ];
                } elseif ($type === 'credit') {
                    $cap = round($baseGrand + $feeTotal - $creditTotal, 2);
                    if ($amount > $cap) {
                        $amount = max(0, $cap);
                    }
                    if ($amount <= 0) {
                        continue;
                    }
                    $creditTotal = round($creditTotal + $amount, 2);
                    $adjustments[] = [
                        'code' => $code,
                        'label' => $label,
                        'type' => 'credit',
                        'amount' => $amount,
                    ];
                } else {
                    $dueNow = $amount;
                    $adjustments[] = [
                        'code' => $code,
                        'label' => $label,
                        'type' => 'due_now',
                        'amount' => $amount,
                    ];
                }
            }
        }

        $grandWithFees = round($baseGrand + $feeTotal - $creditTotal, 2);
        if ($dueNow !== null) {
            if ($dueNow <= 0) {
                $dueNow = null;
            } elseif ($dueNow > $grandWithFees) {
                $dueNow = $grandWithFees;
            } else {
                $dueNow = round($dueNow, 2);
            }
        }

        return [
            'fees' => $feeTotal,
            'credits' => $creditTotal,
            'due_now' => $dueNow,
            'adjustments' => $adjustments,
        ];
    }

    public static function filterPayments(PluginManifest $manifest, callable $callback): void
    {
        if (! $manifest->can(Capabilities::CHECKOUT_PAYMENT_FILTER)) {
            throw new InvalidArgumentException('This app did not declare checkout.payment.filter.');
        }
        self::$paymentFilters[] = ['manifest' => $manifest, 'callback' => $callback];
    }

    public static function filterShipping(PluginManifest $manifest, callable $callback): void
    {
        if (! $manifest->can(Capabilities::CHECKOUT_SHIPPING_FILTER)) {
            throw new InvalidArgumentException('This app did not declare checkout.shipping.filter.');
        }
        self::$shippingFilters[] = ['manifest' => $manifest, 'callback' => $callback];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Domain\Payment\Models\PaymentMethod>  $methods
     * @param  array<string, mixed>  $context
     * @return \Illuminate\Support\Collection<int, \App\Domain\Payment\Models\PaymentMethod>
     */
    public static function applyPaymentFilters($methods, array $context)
    {
        $hide = self::collectHideIds(self::$paymentFilters, $context, $methods);

        return $methods->reject(fn ($m) => in_array((int) $m->id, $hide, true))->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Domain\Shipping\Models\ShippingMethod>  $methods
     * @param  array<string, mixed>  $context
     * @return \Illuminate\Support\Collection<int, \App\Domain\Shipping\Models\ShippingMethod>
     */
    public static function applyShippingFilters($methods, array $context)
    {
        $hide = self::collectHideIds(self::$shippingFilters, $context, $methods);

        return $methods->reject(fn ($m) => in_array((int) $m->id, $hide, true))->values();
    }

    /**
     * @param  list<array{manifest: PluginManifest, callback: callable}>  $filters
     * @param  array<string, mixed>  $context
     * @param  \Illuminate\Support\Collection<int, object>  $methods
     * @return list<int>
     */
    protected static function collectHideIds(array $filters, array $context, $methods): array
    {
        $hide = [];
        $methodPayload = $methods->map(static fn ($m) => [
            'id' => (int) $m->id,
            'code' => (string) ($m->code ?? ''),
            'name' => (string) ($m->name ?? ''),
            'driver' => (string) ($m->driver ?? ''),
            'type' => (string) ($m->type ?? ''),
        ])->values()->all();

        foreach ($filters as $entry) {
            $result = ($entry['callback'])(array_merge($context, ['methods' => $methodPayload]));
            if (! is_array($result)) {
                continue;
            }
            foreach ((array) ($result['hide_ids'] ?? []) as $id) {
                $hide[] = (int) $id;
            }
            foreach ((array) ($result['hide_codes'] ?? []) as $code) {
                $code = strtolower(trim((string) $code));
                foreach ($methodPayload as $row) {
                    if (strtolower($row['code']) === $code) {
                        $hide[] = $row['id'];
                    }
                }
            }
        }

        return array_values(array_unique($hide));
    }

    public static function resolveLogin(PluginManifest $manifest, callable $callback): void
    {
        if (! $manifest->can(Capabilities::AUTH_LOGIN_RESOLVE)) {
            throw new InvalidArgumentException('This app did not declare auth.login.resolve.');
        }
        self::$loginResolvers[] = ['manifest' => $manifest, 'callback' => $callback];
    }

    /**
     * Resolve an alternate login (username) to a customer email, or null.
     */
    public static function resolveCustomerLogin(string $identifier): ?string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        foreach (self::$loginResolvers as $entry) {
            $result = ($entry['callback'])($identifier);
            if (! is_string($result)) {
                continue;
            }
            $email = trim($result);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    public static function configureRegister(PluginManifest $manifest, callable $callback): void
    {
        if (! $manifest->can(Capabilities::AUTH_REGISTER)) {
            throw new InvalidArgumentException('This app did not declare auth.register.');
        }
        self::$registerConfigurers[] = ['manifest' => $manifest, 'callback' => $callback];
    }

    public static function onRegistered(PluginManifest $manifest, callable $callback): void
    {
        if (! $manifest->can(Capabilities::AUTH_REGISTER)) {
            throw new InvalidArgumentException('This app did not declare auth.register.');
        }
        self::$registerListeners[] = ['manifest' => $manifest, 'callback' => $callback];
    }

    /**
     * @return array{is_enabled: bool, auto_login: bool}
     */
    public static function registerConfig(): array
    {
        $config = ['is_enabled' => true, 'auto_login' => true];
        foreach (self::$registerConfigurers as $entry) {
            $result = ($entry['callback'])();
            if (! is_array($result)) {
                continue;
            }
            if (array_key_exists('is_enabled', $result)) {
                $config['is_enabled'] = (bool) $result['is_enabled'];
            }
            if (array_key_exists('auto_login', $result)) {
                $config['auto_login'] = (bool) $result['auto_login'];
            }
        }

        return $config;
    }

    /**
     * @param  array<string, string>  $extra
     */
    public static function fireRegistered(int $customerId, array $extra = []): void
    {
        foreach (self::$registerListeners as $entry) {
            ($entry['callback'])($customerId, $extra);
        }
    }

    public static function scheduleTick(PluginManifest $manifest, callable $callback): void
    {
        if (! $manifest->can(Capabilities::SCHEDULE_TICK)) {
            throw new InvalidArgumentException('This app did not declare schedule.tick.');
        }
        self::$scheduleTicks[] = ['manifest' => $manifest, 'callback' => $callback];
    }

    /**
     * Run all registered schedule.tick callbacks. Returns how many ran.
     * One failing extension must not stop the rest.
     */
    public static function runScheduleTicks(): int
    {
        $ran = 0;
        foreach (self::$scheduleTicks as $entry) {
            try {
                ($entry['callback'])();
                $ran++;
            } catch (\Throwable) {
                // Keep ticking other extensions.
            }
        }

        return $ran;
    }

    protected static function listenAction(PluginManifest $manifest, string $action, string $when, callable $callback): void
    {
        if (! $manifest->can('hook.listen')) {
            throw new InvalidArgumentException('This app did not declare hook.listen.');
        }
        if (! ActionCatalog::allows($action)) {
            throw new InvalidArgumentException('Unknown action '.$action.'.');
        }
        $hook = 'action.'.$action.'.'.$when;
        if (! in_array($hook, $manifest->hooks, true)) {
            throw new InvalidArgumentException('This app cannot listen to '.$hook.'.');
        }
        HookManager::listen($hook, $callback);
    }

    protected static function assertOwnClass(PluginManifest $manifest, string $class): void
    {
        $prefix = 'Modules\\'.$manifest::studly($manifest->vendor).'\\'.$manifest::studly($manifest->name).'\\';
        if (! str_starts_with($class, $prefix) || str_contains($class, '..')) {
            throw new InvalidArgumentException('Module controllers must live in '.$prefix);
        }
    }
}
