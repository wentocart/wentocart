<?php

namespace App\Domain\Ops;

use App\Domain\Auth\PageCatalog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Models\Admin;

final class CommandIndex
{
    /**
     * @return array{
     *   pages: list<array{label: string, href: string, group: string}>,
     *   records: list<array{label: string, href: string, group: string}>,
     *   actions: list<array{label: string, href: string, group: string}>
     * }
     */
    public function search(string $q, Admin $admin): array
    {
        $q = trim($q);
        $slugs = $admin->permissionSlugs();
        $pages = [];
        foreach (PageCatalog::pages() as $page) {
            if (! empty($page['hidden'])) {
                continue;
            }
            if (! in_array('*', $slugs, true) && ! PageCatalog::grants($slugs, $page['slug'])) {
                continue;
            }
            if ($q !== '' && ! str_contains(strtolower($page['label'].' '.$page['href']), strtolower($q))) {
                continue;
            }
            $pages[] = [
                'label' => $page['label'],
                'label_key' => $page['label_key'] ?? ('admin.pages.'.$page['slug']),
                'href' => $page['href'],
                'group' => $page['group'],
                'group_key' => $page['group_key'] ?? PageCatalog::groupKey($page['group']),
            ];
            if (count($pages) >= 12) {
                break;
            }
        }

        $records = [];
        if ($q !== '') {
            if ($admin->hasPermission('catalog.products') || $admin->hasPermission('*')) {
                Product::query()
                    ->with('translations')
                    ->where(function ($query) use ($q) {
                        $query->where('sku', 'like', '%'.$q.'%')
                            ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$q.'%'));
                    })
                    ->limit(6)
                    ->get()
                    ->each(function (Product $product) use (&$records) {
                        $records[] = [
                            'label' => ($product->translation()?->name ?: $product->sku).' · '.$product->sku,
                            'href' => '/admin/products/'.$product->id.'/edit',
                            'group' => 'Products',
                            'group_key' => 'admin.jump.group_products',
                        ];
                    });
            }
            if ($admin->hasPermission('orders.manage') || $admin->hasPermission('*')) {
                Order::query()
                    ->where(function ($query) use ($q) {
                        $query->where('number', 'like', '%'.$q.'%')
                            ->orWhere('email', 'like', '%'.$q.'%');
                    })
                    ->limit(6)
                    ->get()
                    ->each(function (Order $order) use (&$records) {
                        $records[] = [
                            'label' => ($order->number ?: '#'.$order->id).' · '.$order->status,
                            'href' => '/admin/orders/'.$order->id,
                            'group' => 'Orders',
                            'group_key' => 'admin.jump.group_orders',
                        ];
                    });
            }
            if ($admin->hasPermission('customers.customers') || $admin->hasPermission('*')) {
                Customer::query()
                    ->where(function ($query) use ($q) {
                        $query->where('email', 'like', '%'.$q.'%')
                            ->orWhere('name', 'like', '%'.$q.'%');
                    })
                    ->limit(6)
                    ->get()
                    ->each(function (Customer $customer) use (&$records) {
                        $records[] = [
                            'label' => $customer->name.' · '.$customer->email,
                            'href' => '/admin/customers/'.$customer->id,
                            'group' => 'Customers',
                            'group_key' => 'admin.jump.group_customers',
                        ];
                    });
            }
        }

        return [
            'pages' => $pages,
            'records' => $records,
            'actions' => $this->actions($q, $admin),
        ];
    }

    /**
     * Curated Wento ops — not a dump of every menu item.
     *
     * @return list<array{label: string, href: string, group: string}>
     */
    protected function actions(string $q, Admin $admin): array
    {
        $catalog = [
            [
                'label' => 'Products',
                'label_key' => 'admin.jump.action_products',
                'href' => '/admin/products',
                'group' => 'Actions',
                'group_key' => 'admin.jump.group_actions',
                'permission' => 'catalog.products',
            ],
            [
                'label' => 'Compose order',
                'label_key' => 'admin.jump.action_compose_order',
                'href' => '/admin/orders/create',
                'group' => 'Actions',
                'group_key' => 'admin.jump.group_actions',
                'permission' => 'orders.manage',
            ],
            [
                'label' => 'Apps / Extensions',
                'label_key' => 'admin.jump.action_apps',
                'href' => '/admin/apps',
                'group' => 'Actions',
                'group_key' => 'admin.jump.group_actions',
                'permission' => 'apps.manage',
            ],
            [
                'label' => 'Performance',
                'label_key' => 'admin.jump.action_performance',
                'href' => '/admin/performance',
                'group' => 'Actions',
                'group_key' => 'admin.jump.group_actions',
                'permission' => 'settings.performance',
            ],
            [
                'label' => 'Search shortcut',
                'label_key' => 'admin.jump.action_search_shortcut',
                'href' => '/admin/general',
                'group' => 'Actions',
                'group_key' => 'admin.jump.group_actions',
                'permission' => 'settings.store',
            ],
            [
                'label' => 'My search shortcut',
                'label_key' => 'admin.jump.action_my_search_shortcut',
                'href' => '/admin/account/edit',
                'group' => 'Actions',
                'group_key' => 'admin.jump.group_actions',
                'permission' => null,
            ],
        ];

        $out = [];
        foreach ($catalog as $row) {
            $perm = $row['permission'] ?? null;
            if ($perm !== null && ! $admin->hasPermission($perm) && ! $admin->hasPermission('*')) {
                continue;
            }
            if ($q !== '' && ! str_contains(strtolower($row['label'].' '.$row['href']), strtolower($q))) {
                continue;
            }
            $out[] = [
                'label' => $row['label'],
                'label_key' => $row['label_key'],
                'href' => $row['href'],
                'group' => $row['group'],
                'group_key' => $row['group_key'],
            ];
        }

        return $out;
    }
}
