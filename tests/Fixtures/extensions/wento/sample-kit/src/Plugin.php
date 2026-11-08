<?php

namespace Modules\Wento\SampleKit;

use App\Domain\Plugin\Support\BasePlugin;
use Modules\Wento\SampleKit\Http\ConfigurationController;
use Modules\Wento\SampleKit\Http\CustomerInformationController;
use Modules\Wento\SampleKit\Http\EmployeeSettingsController;
use Modules\Wento\SampleKit\Http\StorefrontBoardController;
use Modules\Wento\SampleKit\Http\StorefrontCreditsController;

class Plugin extends BasePlugin
{
    public function boot(): void
    {
        $this->menu()->group([
            'id' => 'account-manager',
            'label' => 'Account Manager',
            'icon' => 'users',
            'items' => [
                ['page' => 'configuration', 'label' => 'Configuration'],
                ['page' => 'employee-settings', 'label' => 'Employee Settings'],
                ['page' => 'customer-information', 'label' => 'Customer Information'],
            ],
        ]);

        $this->pages()->admin('configuration', ConfigurationController::class);
        $this->pages()->admin('employee-settings', EmployeeSettingsController::class);
        $this->pages()->admin('customer-information', CustomerInformationController::class);
        $this->pages()->front('board', StorefrontBoardController::class, true);
        $this->pages()->front('credits', StorefrontCreditsController::class, true);

        $this->lists()->extend('catalog', function ($list) {
            $list->join('suppliers')
                ->select('suppliers.name', 'supplier')
                ->column('supplier', ['label' => 'Supplier', 'after' => 'name']);
        });

        $this->lists()->extend('customers.customers', function ($list) {
            $list->column('notes', ['label' => 'Notes', 'after' => 'email']);
        });

        $this->slots()->on('admin.product.edit', function (array $ctx) {
            return [[
                'type' => 'card',
                'title' => 'Sample kit',
                'text' => 'This block is a view slot on the product page. SKU '.($ctx['sku'] ?? ''),
            ]];
        });

        $this->slots()->on('admin.order.show.after_payment', function (array $ctx) {
            return [[
                'type' => 'table',
                'columns' => [
                    ['key' => 'order', 'label' => 'Order'],
                    ['key' => 'note', 'label' => 'Note'],
                ],
                'rows' => [[
                    'order' => (string) ($ctx['number'] ?? ''),
                    'note' => 'Sample kit slot after payment.',
                ]],
            ]];
        });

        $this->slots()->on('admin.customer.show', function () {
            return [['type' => 'text', 'text' => 'Sample kit slot on the customer view.']];
        });

        $this->slots()->on('storefront.header.top', function () {
            return [['type' => 'text', 'text' => 'Sample kit header slot']];
        });

        $this->slots()->on('storefront.footer', function () {
            return [['type' => 'text', 'text' => 'Sample kit footer slot']];
        });

        $this->slots()->on('storefront.home.body', function () {
            return [['type' => 'card', 'title' => 'From a module', 'text' => 'Homepage body slot.']];
        });

        $this->slots()->on('storefront.product.after', function () {
            return [['type' => 'text', 'text' => 'Module block under the product.']];
        });

        $this->slots()->on('storefront.category.after', function () {
            return [['type' => 'text', 'text' => 'Module block on the category page.']];
        });

        $this->slots()->on('storefront.account.menu', function () {
            return [
                [
                    'type' => 'link',
                    'href' => '/m/wento/sample-kit/board',
                    'label' => 'Account board',
                    'icon' => 'user',
                ],
                [
                    'type' => 'link',
                    'href' => '/m/wento/sample-kit/credits',
                    'label' => 'Store credit list',
                    'icon' => 'tag',
                ],
            ];
        });

        $this->actions()->after('catalog.product.delete', function (array $payload) {
            $count = (int) $this->settings()->get('deleted_products', 0);
            $this->settings()->set('deleted_products', $count + 1);

            return $payload;
        });
    }

    public function adminSchema(): array
    {
        return [
            'title' => 'Sample kit',
            'hint' => 'Use the Account Manager menu for the hosted pages. This screen is the app overview.',
            'fields' => [
                [
                    'name' => 'enabled',
                    'type' => 'toggle',
                    'label' => 'Ready for this shop',
                ],
            ],
        ];
    }
}
