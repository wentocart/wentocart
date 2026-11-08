<?php

namespace App\Domain\Ops;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebserviceQuery
{
    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function includes(Request $request, array $allowed): array
    {
        $raw = $request->query('include', '');
        $parts = is_array($raw)
            ? $raw
            : (preg_split('/[,\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $out = [];
        foreach ($parts as $part) {
            $part = strtolower(trim((string) $part));
            if ($part !== '' && in_array($part, $allowed, true)) {
                $out[$part] = $part;
            }
        }

        return array_values($out);
    }

    public static function applySince(Request $request, Builder $query): void
    {
        $since = trim((string) $request->query('updated_since', ''));
        if ($since === '') {
            return;
        }
        $request->validate(['updated_since' => ['date']]);
        $query->where('updated_at', '>=', $since);
    }

    /**
     * @param  callable(mixed): array<string, mixed>  $mapper
     */
    public static function paginate(Request $request, Builder $query, callable $mapper): JsonResponse
    {
        self::applySince($request, $query);
        $page = max(1, (int) $request->query('page', 1));
        $per = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginator = $query->paginate($per, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())->map($mapper)->values()->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
