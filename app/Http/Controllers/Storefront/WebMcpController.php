<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Settings\SearchCatalogSettings;
use App\Http\Controllers\Controller;
use App\Support\WebBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Browser WebMCP tools for AI agents (Shop REST WebMCP parity + compare).
 * Tools only navigate or mutate wishlist / newsletter in the shopper's session — never place orders.
 */
class WebMcpController extends Controller
{
    public function product(Request $request, UrlRewriteService $urls): RedirectResponse
    {
        $query = trim((string) $request->query('query', ''));
        if ($query === '') {
            return redirect($this->shopPath('search'));
        }

        $product = $this->resolveProduct($query);
        if ($product) {
            $path = $urls->pathFor('product', $product->id, app()->getLocale())
                ?: optional($product->translation(app()->getLocale()))->slug
                ?: 'catalog/product/'.$product->id;

            return redirect('/'.ltrim((string) $path, '/'));
        }

        return redirect($this->shopPath('search').'?q='.rawurlencode($query));
    }

    public function addToWishlist(Request $request, UrlRewriteService $urls): RedirectResponse
    {
        $query = trim((string) $request->query('query', ''));
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return redirect($this->shopPath('login').'?redirect='.rawurlencode($this->shopPath('account/wishlist')));
        }
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return redirect($this->shopPath('account/wishlist'));
        }

        $product = $query !== '' ? $this->resolveProduct($query) : null;
        if (! $product) {
            return redirect($this->shopPath('search').($query !== '' ? '?q='.rawurlencode($query) : ''));
        }

        if (! $customer->wishlist()->where('product_id', $product->id)->exists()) {
            $customer->wishlist()->attach($product->id);
        }

        return redirect($this->shopPath('account/wishlist'));
    }

    public function subscribeNewsletter(Request $request): RedirectResponse
    {
        try {
            $data = $request->validate([
                'email' => ['required', 'email', 'max:190'],
            ]);
        } catch (ValidationException) {
            return redirect($this->shopPath(''))->withErrors(['email' => 'A valid email is required.']);
        }

        $email = strtolower(trim($data['email']));
        if (! NewsletterSubscriber::query()->where('email', $email)->exists()) {
            NewsletterSubscriber::query()->create([
                'email' => $email,
                'ip' => mb_substr((string) $request->ip(), 0, 45),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        }

        $customer = Auth::guard('customer')->user();
        if ($customer) {
            $customer->newsletter = true;
            $customer->save();
        }

        return redirect($this->shopPath(''))->with('status', 'subscribed');
    }

    protected function resolveProduct(string $query): ?Product
    {
        $locale = app()->getLocale();
        $path = trim($query, '/');
        $slug = Str::slug($query);

        $rewrite = UrlRewrite::query()
            ->where('language_code', $locale)
            ->where('entity_type', 'product')
            ->where(function ($q) use ($path, $query) {
                $q->where('request_path', $path)->orWhere('request_path', $query);
            })
            ->first();
        if ($rewrite) {
            $product = Product::query()->enabled()->find($rewrite->entity_id);
            if ($product) {
                return $product;
            }
        }

        $bySlug = Product::query()->enabled()
            ->whereHas('translations', function ($t) use ($locale, $path, $slug, $query) {
                $t->where('language_code', $locale)
                    ->where(function ($inner) use ($path, $slug, $query) {
                        $inner->where('slug', $path)
                            ->orWhere('slug', $slug)
                            ->orWhere('slug', $query)
                            ->orWhere('name', $query);
                    });
            })
            ->first();
        if ($bySlug) {
            return $bySlug;
        }

        $bySku = Product::query()->enabled()->where('sku', $query)->first();
        if ($bySku) {
            return $bySku;
        }

        return Product::query()->enabled()
            ->whereHas('translations', function ($t) use ($locale, $query) {
                $t->where('language_code', $locale)
                    ->where(function ($inner) use ($query) {
                        $inner->where('name', 'like', '%'.$query.'%')
                            ->orWhere('slug', 'like', '%'.$query.'%');
                    });
            })
            ->orderBy('id')
            ->first();
    }

    protected function shopPath(string $path): string
    {
        $base = rtrim(WebBase::path(), '/');
        $path = trim($path, '/');
        if ($path === '') {
            return $base === '' ? '/' : $base.'/';
        }

        return ($base === '' ? '' : $base).'/'.$path;
    }
}
