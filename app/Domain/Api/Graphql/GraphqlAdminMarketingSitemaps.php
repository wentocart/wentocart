<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Marketing\Models\MarketingSitemap;
use App\Domain\Seo\SitemapBuilder;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\Store;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Storage;

/**
 */
final class GraphqlAdminMarketingSitemaps
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('settings.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters): array
    {
        self::assertManage();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = MarketingSitemap::query();

        $fileName = trim((string) ($filters['file_name'] ?? $filters['fileName'] ?? ''));
        if ($fileName !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('file_name', $op, '%'.$fileName.'%');
        }
        $storeId = (int) ($filters['store_id'] ?? $filters['storeId'] ?? 0);
        if ($storeId > 0) {
            $needle = (string) $storeId;
            $q->where(function ($inner) use ($needle) {
                $inner->whereJsonContains('store_ids', $needle)
                    ->orWhereJsonContains('store_ids', (int) $needle);
            });
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = $sort === 'file_name' || $sort === 'filename' ? 'file_name' : 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (MarketingSitemap $row) => self::map($row, false))->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertManage();
        $row = MarketingSitemap::query()->find($id);

        return $row ? self::map($row, true) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $fileName = trim((string) ($input['file_name'] ?? $input['fileName'] ?? ''));
        $path = trim((string) ($input['path'] ?? ''));
        $channels = collect($input['stores'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($fileName === '' || ! preg_match('/^[A-Za-z0-9._-]+\.xml$/', $fileName)) {
            throw new UserError('file_name must be a valid .xml file name.');
        }
        if ($path === '' || $path[0] !== '/' || ! str_ends_with($path, '/') || str_contains($path, '//')) {
            throw new UserError('path must start and end with / and contain no //.');
        }
        if ($channels === []) {
            throw new UserError('At least one channel is required.');
        }
        foreach ($channels as $storeId) {
            if (! Store::query()->whereKey($storeId)->exists()) {
                throw new UserError('Unknown store id: '.$storeId);
            }
        }
        $row = MarketingSitemap::query()->create([
            'file_name' => $fileName,
            'path' => $path,
            'store_ids' => array_map('strval', $channels),
            'generated_at' => null,
            'generated_files' => [],
        ]);

        return self::map($row, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = MarketingSitemap::query()->find($id);
        if (! $row) {
            throw new UserError('Sitemap not found.');
        }
        if (array_key_exists('file_name', $input) || array_key_exists('fileName', $input)) {
            $fileName = trim((string) ($input['file_name'] ?? $input['fileName'] ?? ''));
            if ($fileName === '' || ! preg_match('/^[A-Za-z0-9._-]+\.xml$/', $fileName)) {
                throw new UserError('file_name must be a valid .xml file name.');
            }
            $row->file_name = $fileName;
        }
        if (array_key_exists('path', $input) && $input['path'] !== null) {
            $path = trim((string) $input['path']);
            if ($path === '' || $path[0] !== '/' || ! str_ends_with($path, '/') || str_contains($path, '//')) {
                throw new UserError('path must start and end with / and contain no //.');
            }
            $row->path = $path;
        }
        if (array_key_exists('stores', $input) && $input['stores'] !== null) {
            $channels = collect($input['stores'])->map(fn ($cid) => (int) $cid)->filter()->unique()->values()->all();
            if ($channels === []) {
                throw new UserError('At least one channel is required.');
            }
            foreach ($channels as $storeId) {
                if (! Store::query()->whereKey($storeId)->exists()) {
                    throw new UserError('Unknown store id: '.$storeId);
                }
            }
            $row->store_ids = array_map('strval', $channels);
        }
        $row->save();

        return self::map($row->fresh() ?? $row, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = MarketingSitemap::query()->find($id);
        if (! $row) {
            throw new UserError('Sitemap not found.');
        }
        $snapshot = self::map($row, true);
        $row->delete();

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public static function generate(int $id): array
    {
        self::assertManage();
        $row = MarketingSitemap::query()->find($id);
        if (! $row) {
            throw new UserError('Sitemap not found.');
        }
        $channels = is_array($row->store_ids) ? array_values(array_map('intval', $row->store_ids)) : [];
        if ($channels === []) {
            throw new UserError('Sitemap covers no stores.');
        }
        $enabled = ShopSetting::bool('seo.sitemap_enabled', true);
        $files = [];
        $paths = [];
        $indexFile = null;
        if ($enabled) {
            $builder = app(SitemapBuilder::class);
            $doc = $builder->document();
            $xml = (string) ($doc['xml'] ?? '');
            $relative = trim((string) $row->path, '/');
            $dir = $relative === '' ? 'sitemaps' : 'sitemaps/'.$relative;
            foreach ($channels as $storeId) {
                $store = Store::query()->find($storeId);
                if (! $store) {
                    continue;
                }
                $channelDir = $dir.'/'.$storeId;
                $indexPath = $channelDir.'/'.$row->file_name;
                Storage::disk('public')->put($indexPath, $xml);
                $indexUrl = Storage::disk('public')->url($indexPath);
                $files[] = [
                    'store_id' => (string) $storeId,
                    'hostname' => (string) ($store->name ?? 'store-'.$storeId),
                    'index' => $indexPath,
                    'index_url' => $indexUrl,
                    'sitemaps' => [$indexPath],
                ];
                $paths[] = $indexPath;
                $indexFile = $indexFile ?? $indexPath;
            }
            $row->generated_at = now();
            $row->generated_files = $files;
            $row->save();
        }

        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'generatedAt' => optional($fresh->generated_at)?->toIso8601String(),
            'generated_at' => optional($fresh->generated_at)?->toIso8601String(),
            'indexFile' => $indexFile,
            'generatedSitemaps' => $paths,
            'generated_sitemaps' => $paths,
            'sitemap' => self::map($fresh, true),
            'message' => $enabled
                ? 'Sitemap generated for '.count($files).' channel(s).'
                : 'Sitemap generation is disabled in configuration; no files written.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(MarketingSitemap $row, bool $withGenerated = false): array
    {
        $channels = is_array($row->store_ids) ? array_values(array_map('intval', $row->store_ids)) : [];
        $files = is_array($row->generated_files) ? $row->generated_files : [];
        $urls = [];
        foreach ($files as $file) {
            if (is_array($file) && ! empty($file['index_url'])) {
                $urls[] = (string) $file['index_url'];
            }
        }
        $path = (string) $row->path;
        if ($path === '' || $path[0] !== '/') {
            $path = '/'.$path;
        }
        if (! str_ends_with($path, '/')) {
            $path .= '/';
        }

        $payload = [
            'id' => (int) $row->id,

            'fileName' => (string) $row->file_name,
            'file_name' => (string) $row->file_name,
            'path' => $path,
            'stores' => $channels,
            'urls' => $urls,
            'generatedAt' => optional($row->generated_at)?->toIso8601String(),
            'generated_at' => optional($row->generated_at)?->toIso8601String(),
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
        if ($withGenerated) {
            $payload['generatedFiles'] = $files;
            $payload['generated_files'] = $files;
            $payload['indexFile'] = $files[0]['index'] ?? null;
            $payload['generatedSitemaps'] = array_values(array_filter(array_map(
                fn ($f) => is_array($f) ? ($f['index'] ?? null) : null,
                $files
            )));
        }

        return $payload;
    }
}
