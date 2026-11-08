<?php

namespace App\Domain\Seo;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Localization\Money;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;

/**
 * Google Merchant Center product RSS feed (/feeds/google-merchant.xml).
 * Feed validity ≠ automatic approval — merchants must still validate in GMC.
 */
final class GoogleMerchantFeed
{
    public const CHUNK = 2500;

    public function __construct(
        protected UrlRewriteService $urls,
    ) {}

    public function xml(?string $locale = null, int $page = 1): string
    {
        if (! ShopSetting::bool('seo.merchant_feed', true)) {
            abort(404);
        }

        $root = rtrim($this->shopRoot(), '/');
        $locale = $locale ?: app()->getLocale();
        $pathMode = StorefrontHreflang::localePathMode();
        $currency = Money::defaultCode();
        $page = max(1, $page);

        $query = Product::query()
            ->enabled()
            ->with([
                'translations',
                'brand.translations',
                'images',
                'categories.translations',
                'categories.parent.translations',
            ])
            ->orderBy('id');

        $total = (clone $query)->count();
        $products = $query->forPage($page, self::CHUNK)->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'."\n<channel>\n";
        $xml .= '  <title>'.e((string) config('wento.name', config('app.name'))).'</title>'."\n";
        $xml .= '  <link>'.e($root.'/').'</link>'."\n";
        $xml .= '  <description>'.e('Product feed for Google Merchant Center. Validate in GMC before advertising. Page '.$page.' of '.max(1, (int) ceil($total / self::CHUNK)).'.').'</description>'."\n";
        if ($total > self::CHUNK) {
            $pages = (int) ceil($total / self::CHUNK);
            for ($i = 1; $i <= $pages; $i++) {
                if ($i === $page) {
                    continue;
                }
                $xml .= '  <atom:link xmlns:atom="http://www.w3.org/2005/Atom" rel="related" href="'.e($root.'/feeds/google-merchant.xml?page='.$i.($locale ? '&hl='.urlencode($locale) : '')).'"/>'."\n";
            }
        }

        foreach ($products as $product) {
            $t = $product->translation($locale);
            $title = trim((string) ($t?->name ?: $product->sku));
            if ($title === '') {
                continue;
            }

            $path = $this->urls->pathFor('product', (int) $product->id, $locale)
                ?: ($t?->slug ? ltrim((string) $t->slug, '/') : 'product/'.$product->id);
            $path = ltrim((string) $path, '/');
            $link = $pathMode
                ? $root.'/'.$locale.'/'.$path
                : $root.'/'.$path;

            $image = CatalogMediaUrl::asset($product->cover_path);
            $brand = $product->brand?->translation($locale)?->name ?: $product->brand?->name;
            $description = trim(strip_tags((string) ($t?->short_description ?: $t?->description ?: $title)));
            if (mb_strlen($description) > 5000) {
                $description = mb_substr($description, 0, 5000).'…';
            }

            $gtin = trim((string) ($product->gtin ?: $product->upc ?: $product->barcode ?: ''));
            $mpn = trim((string) ($product->mpn ?: ''));
            $hasIdentifier = $gtin !== '' || $mpn !== '' || (is_string($brand) && $brand !== '');

            $selling = (float) $product->sellingPrice();
            $regular = (float) $product->price;
            $compare = $product->compare_price ? (float) $product->compare_price : null;

            $xml .= "  <item>\n";
            $xml .= '    <g:id>'.e((string) $product->sku).'</g:id>'."\n";
            $xml .= '    <g:title>'.e($title).'</g:title>'."\n";
            $xml .= '    <g:description>'.e($description).'</g:description>'."\n";
            $xml .= '    <g:link>'.e($link).'</g:link>'."\n";
            $xml .= '    <g:image_link>'.e($image).'</g:image_link>'."\n";
            if ($product->relationLoaded('images')) {
                foreach ($product->images->take(8) as $img) {
                    $extra = CatalogMediaUrl::asset($img->path);
                    if ($extra && $extra !== $image) {
                        $xml .= '    <g:additional_image_link>'.e($extra).'</g:additional_image_link>'."\n";
                    }
                }
            }
            $xml .= '    <g:price>'.e(number_format($regular > 0 ? $regular : $selling, 2, '.', '')).' '.e($currency).'</g:price>'."\n";
            if (PricingSettings::salePricing() && $selling > 0 && (($compare && $compare > $selling) || ($regular > $selling))) {
                $xml .= '    <g:sale_price>'.e(number_format($selling, 2, '.', '')).' '.e($currency).'</g:sale_price>'."\n";
            }
            $xml .= '    <g:availability>'.($product->inStockFor(1) ? 'in_stock' : 'out_of_stock').'</g:availability>'."\n";
            $xml .= '    <g:condition>'.e((string) ($product->condition ?: 'new')).'</g:condition>'."\n";
            $productType = $this->productTypePath($product, $locale);
            if ($productType !== '') {
                $xml .= '    <g:product_type>'.e($productType).'</g:product_type>'."\n";
            }
            if (is_string($brand) && $brand !== '') {
                $xml .= '    <g:brand>'.e($brand).'</g:brand>'."\n";
            }
            if ($gtin !== '') {
                $xml .= '    <g:gtin>'.e($gtin).'</g:gtin>'."\n";
            }
            if ($mpn !== '') {
                $xml .= '    <g:mpn>'.e($mpn).'</g:mpn>'."\n";
            }
            if (! $hasIdentifier) {
                $xml .= '    <g:identifier_exists>false</g:identifier_exists>'."\n";
            }
            $shipping = $this->shippingRow();
            if ($shipping !== null) {
                $xml .= "    <g:shipping>\n";
                $xml .= '      <g:country>'.e($shipping['country']).'</g:country>'."\n";
                $xml .= '      <g:service>'.e($shipping['service']).'</g:service>'."\n";
                $xml .= '      <g:price>'.e($shipping['price']).'</g:price>'."\n";
                $xml .= "    </g:shipping>\n";
            }
            $xml .= "  </item>\n";
        }

        $xml .= "</channel>\n</rss>";

        return $xml;
    }

    protected function productTypePath(Product $product, string $locale): string
    {
        if (! $product->relationLoaded('categories')) {
            return '';
        }
        $primary = $product->categories->first();
        if (! $primary instanceof Category) {
            return '';
        }
        $names = [];
        $cursor = $primary;
        $guard = 0;
        while ($cursor && $guard < 8) {
            $label = $cursor->translation($locale)?->name;
            if (is_string($label) && $label !== '') {
                array_unshift($names, $label);
            }
            $cursor->loadMissing('parent.translations');
            $cursor = $cursor->parent;
            $guard++;
        }

        return implode(' > ', $names);
    }

    /**
     * Optional flat shipping from shop settings (GMC still needs account-level shipping for approval).
     *
     * @return array{country: string, service: string, price: string}|null
     */
    protected function shippingRow(): ?array
    {
        try {
            $country = strtoupper(trim((string) ShopSetting::get('seo.merchant_shipping_country', '')));
            $price = trim((string) ShopSetting::get('seo.merchant_shipping_price', ''));
        } catch (\Throwable) {
            return null;
        }
        if ($country === '' || $price === '' || ! is_numeric($price)) {
            return null;
        }
        $currency = Money::defaultCode();
        $service = trim((string) ShopSetting::get('seo.merchant_shipping_service', 'Standard')) ?: 'Standard';

        return [
            'country' => $country,
            'service' => $service,
            'price' => number_format((float) $price, 2, '.', '').' '.$currency,
        ];
    }

    protected function shopRoot(): string
    {
        $url = app(StoreContext::class)->store()?->primaryUrl()?->root();

        return is_string($url) && $url !== '' ? $url : (string) config('app.url');
    }
}
