<?php

namespace App\Domain\Search;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\CommunityFeatures;
use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

class MeilisearchIndex
{
    public function enabled(): bool
    {
        return CommunityFeatures::meilisearch() && $this->host() !== '';
    }

    public function host(): string
    {
        return rtrim(trim((string) ShopSetting::get('search.meilisearch_host', env('MEILISEARCH_HOST', ''))), '/');
    }

    public function key(): string
    {
        return trim((string) ShopSetting::get('search.meilisearch_key', env('MEILISEARCH_KEY', '')));
    }

    public function indexName(): string
    {
        $name = trim((string) ShopSetting::get('search.meilisearch_index', 'products'));

        return $name !== '' ? $name : 'products';
    }

    /**
     * @return list<int>
     */
    public function searchIds(string $q, int $limit = 48): array
    {
        if (! $this->enabled() || trim($q) === '') {
            return [];
        }
        try {
            if (! $this->hostIsAllowed()) {
                return [];
            }
            $request = Http::timeout(4)->withHeaders($this->headers());
            if (app()->environment('production')) {
                $request = $request->withOptions(\App\Domain\Security\SafeUrl::outboundOptions($this->host(), false));
            }
            $response = $request->post($this->host().'/indexes/'.$this->indexName().'/search', [
                'q' => $q,
                'limit' => $limit,
                'attributesToRetrieve' => ['id'],
            ]);
            if (! $response->successful()) {
                return [];
            }
            $hits = $response->json('hits') ?? [];

            return collect($hits)->map(fn ($hit) => (int) ($hit['id'] ?? 0))->filter()->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{ok: bool, message: string, version?: string}
     */
    public function ping(): array
    {
        $host = $this->host();
        if ($host === '') {
            return ['ok' => false, 'message' => 'Meilisearch host is empty.'];
        }
        if (! $this->hostIsAllowed()) {
            return ['ok' => false, 'message' => 'Meilisearch host is not allowed.'];
        }
        try {
            $request = Http::timeout(4)->withHeaders($this->headers());
            if (app()->environment('production')) {
                $request = $request->withOptions(\App\Domain\Security\SafeUrl::outboundOptions($host, false));
            }
            $response = $request->get($host.'/health');
            if (! $response->successful()) {
                return ['ok' => false, 'message' => 'Health check failed (HTTP '.$response->status().').'];
            }
            $status = (string) ($response->json('status') ?? 'unknown');

            return [
                'ok' => $status === 'available' || $status === 'unknown',
                'message' => $status === 'available' ? 'Meilisearch is reachable.' : 'Reached host; status='.$status,
                'version' => (string) ($response->json('version') ?? ''),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Meilisearch: '.$e->getMessage()];
        }
    }

    public function upsert(Product $product): void
    {
        $this->upsertMany([$product]);
    }

    /**
     * @param  list<Product>  $products
     */
    public function upsertMany(array $products): void
    {
        if (! $this->enabled() || $products === [] || ! $this->hostIsAllowed()) {
            return;
        }
        $payload = [];
        foreach ($products as $product) {
            $product->loadMissing('translations');
            $payload[] = [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->translations->pluck('name')->filter()->implode(' '),
                'description' => $product->translations
                    ->map(fn ($t) => trim(((string) ($t->short_description ?? '')).' '.((string) ($t->description ?? ''))))
                    ->filter()
                    ->implode(' '),
                'meta_keywords' => $product->translations->pluck('meta_keywords')->filter()->implode(' '),
                'is_enabled' => (bool) $product->is_enabled,
            ];
        }
        try {
            $request = Http::timeout(15)->withHeaders($this->headers());
            if (app()->environment('production')) {
                $request = $request->withOptions(\App\Domain\Security\SafeUrl::outboundOptions($this->host(), false));
            }
            $request->post($this->host().'/indexes/'.$this->indexName().'/documents', $payload);
        } catch (Throwable) {
            // MySQL search remains the fallback.
        }
    }

    public function delete(int $productId): void
    {
        if (! $this->enabled() || $productId <= 0 || ! $this->hostIsAllowed()) {
            return;
        }
        try {
            $request = Http::timeout(8)->withHeaders($this->headers());
            if (app()->environment('production')) {
                $request = $request->withOptions(\App\Domain\Security\SafeUrl::outboundOptions($this->host(), false));
            }
            $request->delete($this->host().'/indexes/'.$this->indexName().'/documents/'.$productId);
        } catch (Throwable) {
            // Ignore — document may already be gone.
        }
    }

    protected function hostIsAllowed(): bool
    {
        $host = $this->host();
        if ($host === '') {
            return false;
        }
        if (app()->environment('production')) {
            return \App\Domain\Security\SafeUrl::isSafe($host, false);
        }
        $parts = parse_url($host);

        return is_array($parts)
            && ! empty($parts['scheme'])
            && ! empty($parts['host'])
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->key() !== '') {
            $headers['Authorization'] = 'Bearer '.$this->key();
        }

        return $headers;
    }
}
