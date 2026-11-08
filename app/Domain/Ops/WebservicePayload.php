<?php

namespace App\Domain\Ops;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\ShippingMethod;

final class WebservicePayload
{
    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public static function product(Product $product, array $includes = []): array
    {
        $product->loadMissing('translations');
        $translation = $product->translation();
        $row = [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'type' => (string) $product->type,
            'barcode' => $product->barcode,
            'brand_id' => $product->brand_id ? (int) $product->brand_id : null,
            'default_category_id' => $product->default_category_id ? (int) $product->default_category_id : null,
            'price' => (float) $product->price,
            'compare_price' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'stock_qty' => (float) $product->stock_qty,
            'reserved_qty' => (float) $product->reserved_qty,
            'stock_status' => (string) $product->stock_status,
            'manage_stock' => (bool) $product->manage_stock,
            'weight' => $product->weight !== null ? (float) $product->weight : null,
            'is_enabled' => (bool) $product->is_enabled,
            'visibility' => (string) $product->visibility,
            'available_for_order' => (bool) $product->available_for_order,
            'name' => (string) ($translation?->name ?: $product->sku),
            'slug' => (string) ($translation?->slug ?: ''),
            'cover_url' => CatalogMediaUrl::asset($product->cover_path, DefaultImage::MD),
            'updated_at' => optional($product->updated_at)?->toIso8601String(),
        ];
        if (in_array('images', $includes, true)) {
            $product->loadMissing('images');
            $row['images'] = $product->images->map(fn ($image) => [
                'id' => (int) $image->id,
                'url' => CatalogMediaUrl::asset($image->path, DefaultImage::MD),
                'alt' => $image->alt,
                'position' => (int) $image->position,
            ])->all();
        }
        if (in_array('categories', $includes, true)) {
            $product->loadMissing('categories.translations');
            $row['categories'] = $product->categories->map(fn (Category $category) => self::category($category))->all();
        }
        if (in_array('brand', $includes, true)) {
            $product->loadMissing('brand.translations');
            $row['brand'] = $product->brand ? self::brand($product->brand) : null;
        }
        if (in_array('tags', $includes, true)) {
            $product->loadMissing('tags.translations');
            $row['tags'] = $product->tags->map(fn (Tag $tag) => self::tag($tag))->all();
        }
        if (in_array('features', $includes, true)) {
            $product->loadMissing(['featureValues.translations', 'featureValues.feature.translations']);
            $row['features'] = $product->featureValues->map(function ($value) {
                $feature = $value->feature;

                return [
                    'feature_id' => $feature?->id ? (int) $feature->id : null,
                    'feature' => (string) ($feature?->translation()?->name ?: $feature?->code ?: ''),
                    'value_id' => (int) $value->id,
                    'value' => (string) ($value->translation()?->value ?: $value->code ?: ''),
                ];
            })->all();
        }
        if (in_array('related', $includes, true)) {
            $related = $product->relationsOfType('related')->with('translations')->limit(20)->get();
            $row['related'] = $related->map(fn (Product $item) => [
                'id' => (int) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) ($item->translation()?->name ?: $item->sku),
            ])->all();
        }
        if (in_array('translations', $includes, true)) {
            $row['translations'] = $product->translations->map(fn ($item) => [
                'language_code' => (string) $item->language_code,
                'name' => (string) $item->name,
                'slug' => (string) $item->slug,
            ])->all();
        }
        if (in_array('suppliers', $includes, true)) {
            $product->loadMissing('suppliers.translations');
            $row['suppliers'] = $product->suppliers->map(fn (Supplier $supplier) => [
                'id' => (int) $supplier->id,
                'name' => $supplier->displayName(),
                'reference' => $supplier->pivot->supplier_reference ?? null,
            ])->all();
        }

        return $row;
    }

    /**
     * @param  list<string>  $includes
     * @return list<string>
     */
    public static function productEager(array $includes): array
    {
        $with = ['translations'];
        if (in_array('images', $includes, true)) {
            $with[] = 'images';
        }
        if (in_array('categories', $includes, true)) {
            $with[] = 'categories.translations';
        }
        if (in_array('brand', $includes, true)) {
            $with[] = 'brand.translations';
        }
        if (in_array('tags', $includes, true)) {
            $with[] = 'tags.translations';
        }
        if (in_array('features', $includes, true)) {
            $with[] = 'featureValues.translations';
            $with[] = 'featureValues.feature.translations';
        }
        if (in_array('suppliers', $includes, true)) {
            $with[] = 'suppliers.translations';
        }

        return $with;
    }

    /**
     * @return array<string, mixed>
     */
    public static function stock(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'stock_qty' => (float) $product->stock_qty,
            'reserved_qty' => (float) $product->reserved_qty,
            'stock_status' => (string) $product->stock_status,
            'manage_stock' => (bool) $product->manage_stock,
            'updated_at' => optional($product->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public static function category(Category $category, array $includes = []): array
    {
        $category->loadMissing('translations');
        $translation = $category->translation();
        $row = [
            'id' => (int) $category->id,
            'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
            'is_enabled' => (bool) $category->is_enabled,
            'position' => (int) $category->position,
            'name' => (string) ($translation?->name ?: ''),
            'slug' => (string) ($translation?->slug ?: ''),
            'image_url' => $category->image_path ? CatalogMediaUrl::asset($category->image_path, DefaultImage::MD) : null,
        ];
        if (in_array('children', $includes, true)) {
            $category->loadMissing('children.translations');
            $row['children'] = $category->children->map(fn (Category $child) => self::category($child))->all();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(Brand $brand): array
    {
        $brand->loadMissing('translations');
        $translation = $brand->translation();

        return [
            'id' => (int) $brand->id,
            'code' => $brand->code,
            'is_enabled' => (bool) $brand->is_enabled,
            'name' => (string) ($translation?->name ?: $brand->code ?: ''),
            'slug' => (string) ($translation?->slug ?: ''),
            'logo_url' => $brand->logo_path ? CatalogMediaUrl::asset($brand->logo_path, DefaultImage::MD) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tag(Tag $tag): array
    {
        $tag->loadMissing('translations');
        $translation = $tag->translation();

        return [
            'id' => (int) $tag->id,
            'name' => (string) ($translation?->name ?: ''),
            'slug' => (string) ($translation?->slug ?: ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function supplier(Supplier $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'name' => $supplier->displayName(),
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'website' => $supplier->website,
            'is_enabled' => (bool) $supplier->is_enabled,
        ];
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public static function feature(Feature $feature, array $includes = []): array
    {
        $feature->loadMissing('translations');
        $row = [
            'id' => (int) $feature->id,
            'code' => (string) $feature->code,
            'type' => (string) $feature->type,
            'is_enabled' => (bool) $feature->is_enabled,
            'name' => (string) ($feature->translation()?->name ?: $feature->code),
        ];
        if (in_array('values', $includes, true)) {
            $feature->loadMissing('values.translations');
            $row['values'] = $feature->values->map(fn ($value) => [
                'id' => (int) $value->id,
                'code' => (string) $value->code,
                'value' => (string) ($value->translation()?->value ?: $value->code),
            ])->all();
        }

        return $row;
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public static function customer(Customer $customer, array $includes = []): array
    {
        $row = WebhookPayload::customer($customer);
        if (in_array('addresses', $includes, true)) {
            $customer->loadMissing('addresses');
            $row['addresses'] = $customer->addresses->map(fn (CustomerAddress $address) => self::address($address))->all();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function address(CustomerAddress $address): array
    {
        return [
            'id' => (int) $address->id,
            'customer_id' => (int) $address->customer_id,
            'label' => $address->label,
            'first_name' => $address->first_name,
            'last_name' => $address->last_name,
            'phone' => $address->phone,
            'company' => $address->company,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
            'country_code' => $address->country_code,
            'is_default_billing' => (bool) $address->is_default_billing,
            'is_default_shipping' => (bool) $address->is_default_shipping,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerGroup(CustomerGroup $group): array
    {
        return [
            'id' => (int) $group->id,
            'name' => (string) $group->name,
            'code' => (string) $group->code,
            'is_enabled' => (bool) $group->is_enabled,
            'discount_percent' => (float) $group->discount_percent,
        ];
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public static function order(Order $order, array $includes = []): array
    {
        $row = WebhookPayload::order($order);
        if (in_array('customer', $includes, true) && $order->customer_id) {
            $order->loadMissing('customer');
            $row['customer'] = $order->customer ? WebhookPayload::customer($order->customer) : null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function language(Language $language): array
    {
        return [
            'id' => (int) $language->id,
            'code' => (string) $language->code,
            'name' => (string) $language->name,
            'is_default' => (bool) $language->is_default,
            'is_enabled' => (bool) $language->is_enabled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function currency(Currency $currency): array
    {
        return [
            'id' => (int) $currency->id,
            'code' => (string) $currency->code,
            'name' => (string) $currency->name,
            'symbol' => (string) $currency->symbol,
            'decimal_places' => (int) $currency->decimal_places,
            'is_default' => (bool) $currency->is_default,
            'is_enabled' => (bool) $currency->is_enabled,
        ];
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public static function country(Country $country, array $includes = []): array
    {
        $row = [
            'id' => (int) $country->id,
            'code' => (string) $country->code,
            'iso3' => $country->iso3,
            'name' => (string) $country->name,
            'calling_code' => $country->calling_code,
            'has_regions' => (bool) $country->has_regions,
            'is_enabled' => (bool) $country->is_enabled,
            'is_default' => (bool) $country->is_default,
        ];
        if (in_array('regions', $includes, true)) {
            $country->loadMissing('regions');
            $row['regions'] = $country->regions->map(fn ($region) => [
                'id' => (int) $region->id,
                'code' => (string) $region->code,
                'name' => (string) $region->name,
                'is_enabled' => (bool) $region->is_enabled,
            ])->all();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function shippingMethod(ShippingMethod $method): array
    {
        return [
            'id' => (int) $method->id,
            'code' => (string) $method->code,
            'name' => (string) $method->name,
            'type' => (string) $method->type,
            'price' => (float) $method->price,
            'tax_class_id' => $method->tax_class_id ? (int) $method->tax_class_id : null,
            'is_enabled' => (bool) $method->is_enabled,
            'position' => (int) $method->position,
        ];
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public static function cmsPage(CmsPage $page, array $includes = []): array
    {
        $page->loadMissing('translations');
        $translation = $page->translation();
        $row = [
            'id' => (int) $page->id,
            'status' => (string) $page->status,
            'is_enabled' => (bool) $page->is_enabled,
            'title' => (string) ($translation?->title ?: ''),
            'slug' => (string) ($translation?->slug ?: ''),
            'short_description' => $translation?->short_description,
        ];
        if (in_array('body', $includes, true)) {
            $row['body'] = $translation?->body;
        }

        return $row;
    }
}
