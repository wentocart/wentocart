<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Seo\GoogleMerchantFeed;
use App\Domain\Seo\SitemapBuilder;
use App\Domain\Settings\ShopTools;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function robots(): Response
    {
        $body = ShopTools::robotsTxt();
        $sitemap = rtrim($this->shopRoot(), '/').'/sitemap.xml';
        $body = str_replace('{sitemap}', $sitemap, $body);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(SitemapBuilder $builder): Response
    {
        $doc = $builder->document();

        return response($doc['xml'], 200, ['Content-Type' => $doc['content_type']]);
    }

    public function sitemapChunk(string $name, SitemapBuilder $builder): Response
    {
        if ($name === 'pages') {
            $doc = $builder->document('pages', 1);
        } elseif (preg_match('/^(product|category|cms|brand)-([0-9]+)$/', $name, $match)) {
            $doc = $builder->document($match[1], (int) $match[2]);
        } else {
            abort(404);
        }

        return response($doc['xml'], 200, ['Content-Type' => $doc['content_type']]);
    }

    public function googleMerchant(Request $request, GoogleMerchantFeed $feed): Response
    {
        $locale = $request->query('hl');
        if (! is_string($locale) || $locale === '') {
            $locale = app()->getLocale();
        }
        $page = max(1, (int) $request->query('page', 1));

        return response($feed->xml($locale, $page), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    protected function shopRoot(): string
    {
        $url = app(StoreContext::class)->store()?->primaryUrl()?->root();

        return is_string($url) && $url !== '' ? $url : (string) config('app.url');
    }
}
