<?php

namespace App\Domain\Search;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Elasticsearch product index: search + write (ensure index, bulk upsert, delete).
 * HTTP only — no heavy PHP client. Parity with {@see MeilisearchIndex}.
 */
class ElasticsearchIndex
{
    protected bool $indexReady = false;

    public function enabled(): bool
    {
        return ShopSetting::bool('search.elasticsearch', false) && $this->host() !== '';
    }

    public function host(): string
    {
        return rtrim(trim((string) ShopSetting::get('search.elasticsearch_host', env('ELASTICSEARCH_HOST', ''))), '/');
    }

    public function indexName(): string
    {
        $name = trim((string) ShopSetting::get('search.elasticsearch_index', 'products'));

        return $name !== '' ? $name : 'products';
    }

    /**
     * @return list<int>
     */
    public function searchIds(string $q, int $limit = 48): array
    {
        if (! $this->enabled() || trim($q) === '' || ! $this->hostIsAllowed()) {
            return [];
        }
        try {
            $response = $this->http(4)->post($this->host().'/'.$this->indexName().'/_search', [
                'size' => $limit,
                '_source' => false,
                'query' => [
                    'multi_match' => [
                        'query' => $q,
                        'fields' => ['name^3', 'sku^2', 'description', 'meta_keywords'],
                    ],
                ],
            ]);
            if (! $response->successful()) {
                return [];
            }
            $hits = $response->json('hits.hits') ?? [];

            return collect($hits)
                ->map(fn ($hit) => (int) ($hit['_id'] ?? 0))
                ->filter()
                ->values()
                ->all();
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
            return ['ok' => false, 'message' => 'Elasticsearch host is empty.'];
        }
        if (! $this->hostIsAllowed()) {
            return ['ok' => false, 'message' => 'Elasticsearch host is not allowed.'];
        }
        try {
            $response = $this->http(4)->get($host);
            if (! $response->successful()) {
                return ['ok' => false, 'message' => 'Cluster ping failed (HTTP '.$response->status().').'];
            }
            $version = (string) ($response->json('version.number') ?? '');

            return [
                'ok' => true,
                'message' => $version !== ''
                    ? 'Elasticsearch is reachable (v'.$version.').'
                    : 'Elasticsearch is reachable.',
                'version' => $version,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Elasticsearch: '.$e->getMessage()];
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
        if (! $this->ensureIndex()) {
            return;
        }

        $lines = [];
        foreach ($products as $product) {
            $doc = $this->document($product);
            $lines[] = json_encode(['index' => ['_index' => $this->indexName(), '_id' => (string) $doc['id']]], JSON_UNESCAPED_UNICODE);
            $lines[] = json_encode($doc, JSON_UNESCAPED_UNICODE);
        }
        $body = implode("\n", $lines)."\n";

        try {
            $this->http(30)
                ->withBody($body, 'application/x-ndjson')
                ->post($this->host().'/_bulk');
        } catch (Throwable) {
            // MySQL / other engines remain the fallback.
        }
    }

    public function delete(int $productId): void
    {
        if (! $this->enabled() || $productId <= 0 || ! $this->hostIsAllowed()) {
            return;
        }
        try {
            $this->http(8)->delete($this->host().'/'.$this->indexName().'/_doc/'.$productId);
        } catch (Throwable) {
            // Ignore — document may already be gone.
        }
    }

    public function ensureIndex(): bool
    {
        if ($this->indexReady) {
            return true;
        }
        if (! $this->enabled() || ! $this->hostIsAllowed()) {
            return false;
        }

        try {
            $head = $this->http(4)->head($this->host().'/'.$this->indexName());
            if ($head->successful()) {
                $this->indexReady = true;

                return true;
            }
            // 404 → create; other errors abort write.
            if ($head->status() !== 404) {
                return false;
            }

            $created = $this->http(15)->put($this->host().'/'.$this->indexName(), [
                'settings' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
                'mappings' => [
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'sku' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                        'name' => ['type' => 'text'],
                        'description' => ['type' => 'text'],
                        'meta_keywords' => ['type' => 'text'],
                        'is_enabled' => ['type' => 'boolean'],
                    ],
                ],
            ]);
            if (! $created->successful() && $created->status() !== 400) {
                // 400 often means index already exists (race).
                return false;
            }
            $this->indexReady = true;

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{id: int, sku: string, name: string, description: string, meta_keywords: string, is_enabled: bool}
     */
    protected function document(Product $product): array
    {
        $product->loadMissing('translations');

        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => $product->translations->pluck('name')->filter()->implode(' '),
            'description' => $product->translations
                ->map(fn ($t) => trim(((string) ($t->short_description ?? '')).' '.((string) ($t->description ?? ''))))
                ->filter()
                ->implode(' '),
            'meta_keywords' => $product->translations->pluck('meta_keywords')->filter()->implode(' '),
            'is_enabled' => (bool) $product->is_enabled,
        ];
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

    protected function http(int $timeout)
    {
        $user = trim((string) ShopSetting::get('search.elasticsearch_user', env('ELASTICSEARCH_USER', '')));
        $pass = (string) ShopSetting::get('search.elasticsearch_pass', env('ELASTICSEARCH_PASS', ''));
        $request = Http::timeout($timeout)->acceptJson();
        if ($user !== '') {
            $request = $request->withBasicAuth($user, $pass);
        }
        if (app()->environment('production')) {
            $request = $request->withOptions(\App\Domain\Security\SafeUrl::outboundOptions($this->host(), false));
        }

        return $request;
    }
}
