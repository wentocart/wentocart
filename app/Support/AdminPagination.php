<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Shared admin list page-size resolution for Back Office paginators.
 */
final class AdminPagination
{
    /** @var list<int> */
    public const OPTIONS = [10, 20, 25, 50, 100];

    public static function perPage(Request $request, int $default = 10, int $min = 5, int $max = 100): int
    {
        $default = max($min, min($max, $default));
        if (! $request->filled('per_page')) {
            return $default;
        }

        return max($min, min($max, (int) $request->input('per_page')));
    }
}
