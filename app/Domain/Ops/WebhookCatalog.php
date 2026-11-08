<?php

namespace App\Domain\Ops;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\Review;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Promo\Models\DiscountRule;

/**
 * Built-in shop events. Admin cannot invent names — they only turn rows on or off.
 * Add a row here, then fire CommerceTopics::notify() (or map the model below).
 * New rows default to off until the merchant enables them (`open` => false).
 */
final class WebhookCatalog
{
    /**
     * @return list<array{id: string, group: string, label: string, hint: string, data: list<string>, open: bool}>
     */
    public static function events(): array
    {
        return [
            self::event('order.placed', 'Orders', 'Order placed', 'When a customer places an order', ['Order', 'Customer'], true),
            self::event('order.updated', 'Orders', 'Order updated', 'When an order’s status or totals change', ['Order'], true),
            self::event('order.paid', 'Orders', 'Order paid', 'When payment is marked paid', ['Order'], true),
            self::event('order.refunded', 'Orders', 'Order refunded', 'When an order is refunded', ['Order'], true),
            self::event('order.cancelled', 'Orders', 'Order cancelled', 'When an order is cancelled', ['Order'], true),
            self::event('return.created', 'Orders', 'Return requested', 'When a customer asks to return items', ['Return', 'Order'], true),
            self::event('return.updated', 'Orders', 'Return updated', 'When a return is approved, received, or refunded', ['Return'], true),

            self::event('product.created', 'Catalog', 'Product created', 'When you add a product', ['Product'], true),
            self::event('product.updated', 'Catalog', 'Product updated', 'When a product’s details change', ['Product'], true),
            self::event('product.deleted', 'Catalog', 'Product deleted', 'When a product is removed', ['Product'], true),
            self::event('stock.updated', 'Catalog', 'Stock updated', 'When quantity on hand changes', ['Stock', 'Product'], true),
            self::event('category.created', 'Catalog', 'Category created', 'When you add a category', ['Category'], true),
            self::event('category.updated', 'Catalog', 'Category updated', 'When a category changes', ['Category'], true),
            self::event('category.deleted', 'Catalog', 'Category deleted', 'When a category is removed', ['Category'], true),
            self::event('brand.created', 'Catalog', 'Brand created', 'When you add a brand', ['Brand'], true),
            self::event('brand.updated', 'Catalog', 'Brand updated', 'When a brand changes', ['Brand'], true),
            self::event('brand.deleted', 'Catalog', 'Brand deleted', 'When a brand is removed', ['Brand'], true),
            self::event('tag.created', 'Catalog', 'Tag created', 'When you add a tag', ['Tag'], true),
            self::event('tag.updated', 'Catalog', 'Tag updated', 'When a tag changes', ['Tag'], true),
            self::event('tag.deleted', 'Catalog', 'Tag deleted', 'When a tag is removed', ['Tag'], true),
            self::event('supplier.created', 'Catalog', 'Supplier created', 'When you add a supplier', ['Supplier'], true),
            self::event('supplier.updated', 'Catalog', 'Supplier updated', 'When a supplier changes', ['Supplier'], true),
            self::event('supplier.deleted', 'Catalog', 'Supplier deleted', 'When a supplier is removed', ['Supplier'], true),

            self::event('customer.created', 'Customers', 'Customer created', 'When a customer account is created', ['Customer'], true),
            self::event('customer.updated', 'Customers', 'Customer updated', 'When a customer’s details change', ['Customer'], true),
            self::event('customer.deleted', 'Customers', 'Customer deleted', 'When a customer is removed', ['Customer'], true),
            self::event('address.created', 'Customers', 'Address saved', 'When a customer address is added', ['Address'], false),
            self::event('address.updated', 'Customers', 'Address updated', 'When a customer address changes', ['Address'], false),
            self::event('address.deleted', 'Customers', 'Address deleted', 'When a customer address is removed', ['Address'], false),
            self::event('review.created', 'Customers', 'Review posted', 'When a product review is submitted', ['Review', 'Product'], true),
            self::event('review.updated', 'Customers', 'Review updated', 'When a review is approved or changed', ['Review'], true),

            self::event('cms.page.created', 'Content', 'Page created', 'When a store page is added', ['Page'], true),
            self::event('cms.page.updated', 'Content', 'Page updated', 'When a store page changes', ['Page'], true),
            self::event('cms.page.deleted', 'Content', 'Page deleted', 'When a store page is removed', ['Page'], true),

            self::event('discount.created', 'Marketing', 'Discount created', 'When a discount or coupon is added', ['Discount'], true),
            self::event('discount.updated', 'Marketing', 'Discount updated', 'When a discount or coupon changes', ['Discount'], true),
            self::event('discount.deleted', 'Marketing', 'Discount deleted', 'When a discount or coupon is removed', ['Discount'], true),

            self::event('cart.created', 'Checkout', 'Cart started', 'When a new cart is created (can be frequent)', ['Cart'], false),
            self::event('cart.deleted', 'Checkout', 'Cart removed', 'When a cart is cleared or deleted', ['Cart'], false),

            self::event('ops.ping', 'System', 'Test ping', 'A test message you send from this page', ['Test'], true),
        ];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(fn (array $row) => $row['id'], self::events());
    }

    /**
     * @return array{id: string, group: string, label: string, hint: string, data: list<string>, open: bool}|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::events() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Model class => action (created|updated|deleted) => event id.
     *
     * @return array<class-string, array<string, string>>
     */
    public static function models(): array
    {
        return [
            Category::class => ['created' => 'category.created', 'updated' => 'category.updated', 'deleted' => 'category.deleted'],
            Brand::class => ['created' => 'brand.created', 'updated' => 'brand.updated', 'deleted' => 'brand.deleted'],
            Tag::class => ['created' => 'tag.created', 'updated' => 'tag.updated', 'deleted' => 'tag.deleted'],
            Supplier::class => ['created' => 'supplier.created', 'updated' => 'supplier.updated', 'deleted' => 'supplier.deleted'],
            CmsPage::class => ['created' => 'cms.page.created', 'updated' => 'cms.page.updated', 'deleted' => 'cms.page.deleted'],
            Review::class => ['created' => 'review.created', 'updated' => 'review.updated'],
            CustomerAddress::class => ['created' => 'address.created', 'updated' => 'address.updated', 'deleted' => 'address.deleted'],
            ShopReturn::class => ['created' => 'return.created', 'updated' => 'return.updated'],
            DiscountRule::class => ['created' => 'discount.created', 'updated' => 'discount.updated', 'deleted' => 'discount.deleted'],
            Cart::class => ['created' => 'cart.created', 'deleted' => 'cart.deleted'],
        ];
    }

    public static function eventFor(string $modelClass, string $action): ?string
    {
        return self::models()[$modelClass][$action] ?? null;
    }

    /**
     * @return list<array{id: string, label: string, hint: string}>
     */
    public static function scopes(): array
    {
        return [
            ['id' => 'shop.read', 'label' => 'Shop (read)', 'hint' => 'Shop, languages, currencies, countries, shipping methods, CMS'],
            ['id' => 'catalog.read', 'label' => 'Catalog (read)', 'hint' => 'Products, stock, categories, brands, tags, features'],
            ['id' => 'catalog.write', 'label' => 'Catalog (write)', 'hint' => 'Create or update products'],
            ['id' => 'orders.read', 'label' => 'Orders (read)', 'hint' => 'Orders and line items'],
            ['id' => 'orders.write', 'label' => 'Orders (write)', 'hint' => 'Change order status'],
            ['id' => 'customers.read', 'label' => 'Customers (read)', 'hint' => 'Customers, addresses, groups (no passwords)'],
            ['id' => 'customers.write', 'label' => 'Customers (write)', 'hint' => 'Create or update customers'],
            ['id' => 'webhooks.manage', 'label' => 'Webhooks (manage)', 'hint' => 'Register outbound endpoints'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function scopeIds(): array
    {
        return array_map(fn (array $row) => $row['id'], self::scopes());
    }

    /**
     * @param  list<string>  $data
     * @return array{id: string, group: string, label: string, hint: string, data: list<string>, open: bool}
     */
    protected static function event(string $id, string $group, string $label, string $hint, array $data, bool $open): array
    {
        return compact('id', 'group', 'label', 'hint', 'data', 'open');
    }
}
