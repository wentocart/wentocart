<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttachmentService;

/**
 */
final class GraphqlProductDownloadables
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function links(int $productId, array $args): array
    {
        return self::connection($productId, AttachmentService::ROLE_PAID, 'dl', $args);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function samples(int $productId, array $args): array
    {
        return self::connection($productId, AttachmentService::ROLE_DOCUMENT, 'ds', $args);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    protected static function connection(int $productId, string $role, string $prefix, array $args): array
    {
        $empty = [
            'edges' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'totalCount' => 0,
        ];
        if ($productId < 1) {
            return $empty;
        }
        $product = Product::query()->find($productId);
        if (! $product) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^'.$prefix.':(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = $product->attachments()->wherePivot('role', $role)->orderBy('attachments.id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('attachments.id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $locale = app()->getLocale();
        $edges = $slice->map(function ($att) use ($prefix, $locale, $product, $role) {
            $title = (string) ($att->name ?: $att->original_filename ?: 'Download');
            $url = null;
            if (is_string($att->storage_path ?? null) && $att->storage_path !== '') {
                $url = '/storage/'.ltrim((string) $att->storage_path, '/');
            }

            return [
                'cursor' => base64_encode($prefix.':'.$att->id),
                'node' => [
                    'id' => (string) $att->id,

                    'price' => $role === AttachmentService::ROLE_PAID ? (float) ($product->price ?? 0) : 0.0,
                    'formattedPrice' => null,
                    'type' => $role === AttachmentService::ROLE_PAID ? 'file' : 'sample',
                    'file' => $att->original_filename ?? $att->stored_filename,
                    'fileUrl' => $url,
                    'url' => $url,
                    'sampleFile' => null,
                    'sampleFileUrl' => null,
                    'sampleUrl' => null,
                    'translation' => [
                        'locale' => $locale,
                        'title' => $title,
                    ],
                ],
            ];
        })->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode($prefix.':'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }
}
