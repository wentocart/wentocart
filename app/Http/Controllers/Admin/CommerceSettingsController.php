<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ReturnSettings;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Settings\ShopSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommerceSettingsController extends Controller
{
    public function editCart(): RedirectResponse
    {
        return redirect('/admin/order-settings?tab=cart');
    }

    public function updateCart(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cart_expiry_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'max_cart_items' => ['required', 'integer', 'min:1', 'max:500'],
            'coupons_enabled' => ['required', 'boolean'],
            'terms_required' => ['required', 'boolean'],
            'terms_cms_page_id' => ['nullable', 'integer', Rule::exists('cms_pages', 'id'), Rule::requiredIf(fn () => $request->boolean('terms_required'))],
            'stock_validation' => ['required', 'boolean'],
            'unavailable_items' => ['required', Rule::in(CartCheckoutSettings::UNAVAILABLE_OPTIONS)],
            'layout' => ['sometimes', Rule::in(CartCheckoutSettings::LAYOUT_OPTIONS)],
            'gift_wrap_enabled' => ['sometimes', 'boolean'],
            'gift_wrap_fee' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
        ], [
            'terms_cms_page_id.required' => 'Select the page customers must accept.',
        ]);

        ShopSetting::put('checkout.cart_expiry_minutes', (int) $data['cart_expiry_minutes']);
        ShopSetting::put('checkout.max_cart_items', (int) $data['max_cart_items']);
        ShopSetting::put('checkout.coupons_enabled', (bool) $data['coupons_enabled']);
        ShopSetting::put('checkout.terms_required', (bool) $data['terms_required']);
        if (array_key_exists('terms_cms_page_id', $data)) {
            ShopSetting::put('checkout.terms_cms_page_id', ! empty($data['terms_cms_page_id']) ? (int) $data['terms_cms_page_id'] : null);
        }
        ShopSetting::put('checkout.stock_validation', (bool) $data['stock_validation']);
        ShopSetting::put('checkout.unavailable_items', $data['unavailable_items']);
        if (array_key_exists('layout', $data)) {
            ShopSetting::put('checkout.layout', $data['layout']);
        }
        if (array_key_exists('gift_wrap_enabled', $data)) {
            ShopSetting::put('checkout.gift_wrap_enabled', (bool) $data['gift_wrap_enabled']);
        }
        if (array_key_exists('gift_wrap_fee', $data)) {
            ShopSetting::put('checkout.gift_wrap_fee', round((float) $data['gift_wrap_fee'], 2));
        }
        FileChangeLog::record('updated', 'checkout/settings');

        return back()->with('success', 'Cart and checkout settings saved.');
    }

    public function editInventory(): RedirectResponse
    {
        return redirect('/admin/product-settings?tab=inventory');
    }

    public function updateInventory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'allow_negative' => ['required', 'boolean'],
            'notifications' => ['required', 'boolean'],
            'scope' => ['required', Rule::in(array_merge(InventorySettings::SCOPE_OPTIONS, InventorySettings::LEGACY_SCOPE_OPTIONS))],
        ]);

        $scope = $data['scope'] === 'shop' ? 'global' : $data['scope'];

        ShopSetting::put('inventory.enabled', (bool) $data['enabled']);
        ShopSetting::put('inventory.allow_negative', (bool) $data['allow_negative']);
        ShopSetting::put('inventory.notifications', (bool) $data['notifications']);
        ShopSetting::put('inventory.scope', $scope);
        FileChangeLog::record('updated', 'inventory/settings');

        return back()->with('success', 'Inventory settings saved.');
    }

    public function editPricing(): RedirectResponse
    {
        return redirect('/admin/product-settings?tab=pricing');
    }

    public function updatePricing(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tax_inclusive_input' => ['required', 'boolean'],
            'storefront_display' => ['required', Rule::in(PricingSettings::DISPLAY_OPTIONS)],
            'decimal_precision' => ['required', 'integer', 'min:0', 'max:4'],
            'rounding' => ['required', Rule::in(PricingSettings::ROUNDING_OPTIONS)],
            'zero_price' => ['required', Rule::in(PricingSettings::ZERO_PRICE_OPTIONS)],
            'sale_enabled' => ['required', 'boolean'],
            'compare_at_enabled' => ['required', 'boolean'],
            'omnibus_enabled' => ['required', 'boolean'],
            'shipping_tax' => ['required', 'boolean'],
        ]);

        ShopSetting::put('pricing.tax_inclusive_input', (bool) $data['tax_inclusive_input']);
        ShopSetting::put('pricing.storefront_display', $data['storefront_display']);
        ShopSetting::put('pricing.decimal_precision', (int) $data['decimal_precision']);
        ShopSetting::put('pricing.rounding', $data['rounding']);
        ShopSetting::put('pricing.zero_price', $data['zero_price']);
        ShopSetting::put('pricing.sale_enabled', (bool) $data['sale_enabled']);
        ShopSetting::put('pricing.compare_at_enabled', (bool) $data['compare_at_enabled']);
        ShopSetting::put('commerce.omnibus_enabled', (bool) $data['omnibus_enabled']);
        ShopSetting::put('tax.shipping', (bool) $data['shipping_tax']);
        StorefrontCatalogCache::bump();
        FileChangeLog::record('updated', 'pricing/settings');

        return back()->with('success', 'Pricing settings saved.');
    }

    public function editReturns(): RedirectResponse
    {
        return redirect('/admin/order-settings?tab=returns');
    }

    public function updateReturns(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'window_days' => ['required', 'integer', 'min:0', 'max:365'],
            'reason_required' => ['required', 'boolean'],
            'approval' => ['required', Rule::in(ReturnSettings::APPROVAL_OPTIONS)],
            'partial' => ['required', 'boolean'],
            'refund_method' => ['required', Rule::in(array_merge(ReturnSettings::REFUND_OPTIONS, ['original', 'store_credit', 'configurable']))],
            'refund_shipping' => ['required', Rule::in(array_merge(ReturnSettings::SHIPPING_COST_OPTIONS, ['conditional']))],
            'restock' => ['required', Rule::in(ReturnSettings::RESTOCK_OPTIONS)],
            'restocking_fee' => ['required', 'boolean'],
            'restocking_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        ShopSetting::put('returns.enabled', (bool) $data['enabled']);
        ShopSetting::put('returns.window_days', (int) $data['window_days']);
        ShopSetting::put('returns.reason_required', (bool) $data['reason_required']);
        ShopSetting::put('returns.approval', $data['approval']);
        ShopSetting::put('returns.partial', (bool) $data['partial']);
        ShopSetting::put('returns.refund_method', ReturnSettings::canonicalRefundMethod((string) $data['refund_method']));
        ShopSetting::put('returns.refund_shipping', ReturnSettings::canonicalRefundShipping((string) $data['refund_shipping']));
        ShopSetting::put('returns.restock', $data['restock']);
        ShopSetting::put('returns.restocking_fee', (bool) $data['restocking_fee']);
        ShopSetting::put('returns.restocking_fee_percent', (float) ($data['restocking_fee_percent'] ?? 10));
        FileChangeLog::record('updated', 'returns/settings');

        return back()->with('success', 'Returns settings saved.');
    }

    public function editSearch(): RedirectResponse
    {
        return redirect('/admin/product-settings?tab=search');
    }

    public function updateSearch(Request $request): RedirectResponse
    {
        $request->merge([
            'synonyms' => $this->filledSearchRows($request->input('synonyms'), 'term'),
            'pins' => $this->filledSearchRows($request->input('pins'), 'query', 'product_id'),
            'redirects' => $this->filledSearchRows($request->input('redirects'), 'query', 'target_path'),
        ]);

        $data = $request->validate([
            'default_sort' => ['required', Rule::in(SearchCatalogSettings::SORT_OPTIONS)],
            'per_page' => ['required', 'integer', 'min:8', 'max:48'],
            'suggestions' => ['required', 'boolean'],
            'typo_tolerance' => ['required', 'boolean'],
            'recently_viewed' => ['required', 'boolean'],
            'wishlist_enabled' => ['required', 'boolean'],
            'synonyms' => ['nullable', 'array'],
            'synonyms.*.term' => ['required_with:synonyms', 'string', 'max:120'],
            'synonyms.*.aliases' => ['nullable', 'string', 'max:500'],
            'synonyms.*.is_enabled' => ['sometimes', 'boolean'],
            'pins' => ['nullable', 'array'],
            'pins.*.query' => ['required_with:pins', 'string', 'max:120'],
            'pins.*.product_id' => ['required_with:pins', 'integer', 'exists:products,id'],
            'pins.*.position' => ['nullable', 'integer', 'min:0'],
            'redirects' => ['nullable', 'array'],
            'redirects.*.query' => ['required_with:redirects', 'string', 'max:120'],
            'redirects.*.target_path' => ['required_with:redirects', 'string', 'max:255'],
            'redirects.*.is_enabled' => ['sometimes', 'boolean'],
            'engine' => ['nullable', 'string', Rule::in(\App\Domain\Search\SearchEngineManager::ENGINES)],
            'meilisearch' => ['sometimes', 'boolean'],
            'meilisearch_host' => ['nullable', 'string', 'max:190'],
            'meilisearch_key' => ['nullable', 'string', 'max:190'],
            'meilisearch_index' => ['nullable', 'string', 'max:64'],
            'elasticsearch' => ['sometimes', 'boolean'],
            'elasticsearch_host' => ['nullable', 'string', 'max:190'],
            'elasticsearch_user' => ['nullable', 'string', 'max:120'],
            'elasticsearch_pass' => ['nullable', 'string', 'max:190'],
            'elasticsearch_index' => ['nullable', 'string', 'max:64'],
        ]);

        ShopSetting::put('catalog.search.default_sort', $data['default_sort']);
        ShopSetting::put('products.list.storefront_per_page', (int) $data['per_page']);
        ShopSetting::put('catalog.search.suggestions', (bool) $data['suggestions']);
        ShopSetting::put('catalog.search.typo_tolerance', (bool) $data['typo_tolerance']);
        ShopSetting::put('catalog.search.recently_viewed', (bool) $data['recently_viewed']);
        ShopSetting::put('catalog.wishlist_enabled', (bool) $data['wishlist_enabled']);
        if (! empty($data['engine'])) {
            ShopSetting::put('search.engine', (string) $data['engine']);
        }
        if (array_key_exists('meilisearch', $data)) {
            ShopSetting::put('search.meilisearch', (bool) $data['meilisearch']);
        }
        if (array_key_exists('meilisearch_host', $data)) {
            $host = trim((string) $data['meilisearch_host']);
            if ($error = $this->validateSearchHost($host, 'meilisearch_host')) {
                return back()->withErrors(['meilisearch_host' => $error]);
            }
            ShopSetting::put('search.meilisearch_host', $host);
        }
        if (array_key_exists('meilisearch_key', $data)) {
            ShopSetting::put('search.meilisearch_key', trim((string) $data['meilisearch_key']));
        }
        if (array_key_exists('meilisearch_index', $data)) {
            $index = trim((string) $data['meilisearch_index']);
            ShopSetting::put('search.meilisearch_index', $index !== '' ? $index : 'products');
        }
        if (array_key_exists('elasticsearch', $data)) {
            ShopSetting::put('search.elasticsearch', (bool) $data['elasticsearch']);
        }
        if (array_key_exists('elasticsearch_host', $data)) {
            $host = trim((string) $data['elasticsearch_host']);
            if ($error = $this->validateSearchHost($host, 'elasticsearch_host')) {
                return back()->withErrors(['elasticsearch_host' => $error]);
            }
            ShopSetting::put('search.elasticsearch_host', $host);
        }
        if (array_key_exists('elasticsearch_user', $data)) {
            ShopSetting::put('search.elasticsearch_user', trim((string) $data['elasticsearch_user']));
        }
        if (array_key_exists('elasticsearch_pass', $data)) {
            ShopSetting::put('search.elasticsearch_pass', (string) $data['elasticsearch_pass']);
        }
        if (array_key_exists('elasticsearch_index', $data)) {
            $index = trim((string) $data['elasticsearch_index']);
            ShopSetting::put('search.elasticsearch_index', $index !== '' ? $index : 'products');
        }
        $this->syncSearchSynonyms($data['synonyms'] ?? []);
        $this->syncSearchPins($data['pins'] ?? []);
        $this->syncSearchRedirects($data['redirects'] ?? []);
        FileChangeLog::record('updated', 'catalog/search-settings');

        return back()->with('success', 'Search and catalog settings saved.');
    }

    public function pingMeilisearch(): RedirectResponse
    {
        $result = app(\App\Domain\Search\MeilisearchIndex::class)->ping();

        return $result['ok']
            ? back()->with('success', $result['message'])
            : back()->withErrors(['meilisearch_host' => $result['message']]);
    }

    public function pingElasticsearch(): RedirectResponse
    {
        $result = app(\App\Domain\Search\ElasticsearchIndex::class)->ping();

        return $result['ok']
            ? back()->with('success', $result['message'])
            : back()->withErrors(['elasticsearch_host' => $result['message']]);
    }

    public function editReviews(): RedirectResponse
    {
        return redirect('/admin/customer-settings?tab=reviews');
    }

    public function updateReviews(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'who' => ['required', Rule::in(ReviewSettings::WHO_OPTIONS)],
            'moderation' => ['required', Rule::in(ReviewSettings::MODERATION_OPTIONS)],
            'merchant_replies' => ['required', 'boolean'],
            'media' => ['required', Rule::in(ReviewSettings::MEDIA_OPTIONS)],
            'min_length' => ['required', 'integer', 'min:0', 'max:2000'],
        ]);

        ShopSetting::put('reviews.enabled', (bool) $data['enabled']);
        ShopSetting::put('reviews.who', $data['who']);
        ShopSetting::put('reviews.moderation', $data['moderation']);
        ShopSetting::put('reviews.merchant_replies', (bool) $data['merchant_replies']);
        ShopSetting::put('reviews.media', $data['media']);
        ShopSetting::put('reviews.min_length', (int) $data['min_length']);
        FileChangeLog::record('updated', 'reviews/settings');

        return back()->with('success', 'Review settings saved.');
    }

    public function editDocuments(): RedirectResponse
    {
        return redirect('/admin/order-settings?tab=invoices');
    }

    public function updateDocuments(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'invoices_enabled' => ['required', 'boolean'],
            'invoice_format' => ['required', 'string', 'max:64', 'regex:/^[^\\{\\}]*\\{number\\}[^\\{\\}]*$/'],
            'invoice_start' => ['required', 'integer', 'min:1', 'max:999999999'],
            'invoice_create_statuses' => ['required', 'array', 'min:1'],
            'invoice_create_statuses.*' => ['required', 'string', Rule::in(array_column(\App\Domain\Settings\OrderSettings::statusChoices(), 'value'))],
            'invoice_auto_send' => ['required', 'boolean'],
            'credit_format' => ['required', 'string', 'max:64', 'regex:/^[^\\{\\}]*\\{number\\}[^\\{\\}]*$/'],
            'packing_slips' => ['required', 'boolean'],
            'order_pdf' => ['required', 'boolean'],
        ], [
            'invoice_format.regex' => 'Enter an editable invoice format that includes {number} once and no other braces. Example: INV-{number}.',
            'credit_format.regex' => 'Enter an editable credit-note format that includes {number} once and no other braces. Example: CN-{number}.',
        ]);

        ShopSetting::put('documents.invoices_enabled', (bool) $data['invoices_enabled']);
        ShopSetting::put('documents.invoice_format', trim($data['invoice_format']));
        ShopSetting::put('documents.invoice_start', (int) $data['invoice_start']);
        $statuses = DocumentCommerceSettings::normalizeCreateStatuses($data['invoice_create_statuses']);
        if ($statuses === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'invoice_create_statuses' => 'Choose at least one order status.',
            ]);
        }
        ShopSetting::put('documents.invoice_create_statuses', implode(',', $statuses));
        ShopSetting::put('documents.invoice_auto_send', (bool) $data['invoice_auto_send']);
        ShopSetting::put('documents.credit_format', trim($data['credit_format']));
        ShopSetting::put('documents.packing_slips', (bool) $data['packing_slips']);
        ShopSetting::put('documents.order_pdf', (bool) $data['order_pdf']);
        FileChangeLog::record('updated', 'documents/settings');

        return back()->with('success', 'Document settings saved.');
    }

    /**
     * Drop the blank starter rows the form always posts. A row counts only when
     * at least one of the given fields has a value, so listing toggles can save
     * without failing required_with on an empty synonym, pin, or redirect.
     *
     * @return list<array<string, mixed>>
     */
    private function filledSearchRows(mixed $rows, string ...$fields): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $filled = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hasValue = false;
            foreach ($fields as $field) {
                if (trim((string) ($row[$field] ?? '')) !== '') {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $filled[] = $row;
            }
        }

        return $filled;
    }

    /**
     * @param  list<array{term?: string, aliases?: string, is_enabled?: mixed}>  $rows
     */
    private function syncSearchSynonyms(array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            $term = trim((string) ($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $record = \App\Domain\Search\Models\SearchSynonym::query()->updateOrCreate(
                ['term' => $term],
                [
                    'aliases' => trim((string) ($row['aliases'] ?? '')),
                    'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                ]
            );
            $keep[] = $record->id;
        }
        \App\Domain\Search\Models\SearchSynonym::query()
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
        \Illuminate\Support\Facades\Cache::forget('search.synonyms');
    }

    /**
     * @param  list<array{query?: string, product_id?: mixed, position?: mixed}>  $rows
     */
    private function syncSearchPins(array $rows): void
    {
        $keep = [];
        $position = 0;
        foreach ($rows as $row) {
            $query = mb_strtolower(trim((string) ($row['query'] ?? '')));
            $productId = (int) ($row['product_id'] ?? 0);
            if ($query === '' || $productId < 1) {
                continue;
            }
            $record = \App\Domain\Search\Models\SearchPin::query()->updateOrCreate(
                ['query' => $query, 'product_id' => $productId],
                ['position' => (int) ($row['position'] ?? $position)],
            );
            $keep[] = $record->id;
            $position++;
        }
        \App\Domain\Search\Models\SearchPin::query()
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    /**
     * @param  list<array{query?: string, target_path?: string, is_enabled?: mixed}>  $rows
     */
    private function syncSearchRedirects(array $rows): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('search_redirects')) {
            return;
        }
        $keep = [];
        foreach ($rows as $row) {
            $query = mb_strtolower(trim((string) ($row['query'] ?? '')));
            $target = \App\Domain\Security\SafeRedirect::sanitize(trim((string) ($row['target_path'] ?? '')));
            if ($query === '' || $target === null || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
                continue;
            }
            $record = \App\Domain\Search\Models\SearchRedirect::query()->updateOrCreate(
                ['query' => $query],
                [
                    'target_path' => $target,
                    'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                ]
            );
            $keep[] = $record->id;
        }
        \App\Domain\Search\Models\SearchRedirect::query()
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    private function validateSearchHost(string $host, string $field): ?string
    {
        if ($host === '') {
            return null;
        }
        $parts = parse_url($host);
        $ok = is_array($parts)
            && ! empty($parts['scheme'])
            && ! empty($parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
        if (! $ok) {
            return 'Host must be a valid http(s) URL.';
        }
        if (app()->environment('production')) {
            try {
                \App\Domain\Security\SafeUrl::assertPublicHttp($host, false);
            } catch (\InvalidArgumentException) {
                return 'Host must be a public http(s) URL in production.';
            }
        }

        return null;
    }
}
