<?php

namespace App\Domain\Search;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Database\DbGrammar;
use App\Domain\Localization\Models\Language;
use App\Domain\Search\Models\SearchPin;
use App\Domain\Search\Models\SearchSynonym;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\SearchCatalogSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogSearch
{
    public function products(string $q, string $locale = 'en', int $perPage = 12, ?callable $constrain = null): LengthAwarePaginatorContract
    {
        $q = trim($q);
        $query = ProductSettings::applyListingStockFilter(
            Product::query()
                ->enabled()
                ->visibleIn('search')
                ->with(['translations', 'images', 'brand.translations'])
        );
        $query = app(CatalogPresenter::class)->withRatings($query);
        if ($constrain) {
            $constrain($query);
        }

        if ($q === '') {
            return $query->paginate($perPage);
        }

        $phrases = $this->expand($q);
        $default = Language::defaultCode();
        $typo = SearchCatalogSettings::typoTolerance();

        // Engine-agnostic id lookup (Meilisearch → MySQL FULLTEXT → database/LIKE).
        $resolved = app(SearchEngineManager::class)->searchIds($q, max(24, $perPage * 3));
        $ids = collect($resolved['ids']);

        if ($ids->isNotEmpty()) {
            $results = $query->whereIn('id', $ids)->paginate($perPage);
        } else {
            $likeQuery = clone $query;
            $results = $this->applyLikeSearch($likeQuery, $phrases, $locale, $default)->paginate($perPage);
            if ($typo && $results->total() === 0) {
                $fuzzyIds = $this->typoProductIds($q, $locale, $default);
                if ($fuzzyIds !== []) {
                    $results = $query->whereIn('id', $fuzzyIds)->paginate($perPage);
                }
            }
        }

        return $this->applyPins($results, $q, $locale, $perPage);
    }

    /**
     * Synonym expansion. Cached as plain arrays — Eloquent collections become
     * __PHP_Incomplete_Class when cache.serializable_classes is false.
     *
     * @return list<string>
     */
    public function expand(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        if (! Schema::hasTable('search_synonyms')) {
            return [$q];
        }

        $needle = mb_strtolower($q);
        $out = [$q];
        foreach ($this->synonymRows() as $row) {
            $term = trim((string) ($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            /** @var list<string> $aliases */
            $aliases = $row['aliases'] ?? [];
            $keys = array_merge([mb_strtolower($term)], $aliases);
            if (! in_array($needle, $keys, true)) {
                continue;
            }
            $out[] = $term;
            foreach ($aliases as $alias) {
                $out[] = $alias;
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $out))));
    }

    /**
     * Split a phrase into searchable word tokens (case-folded).
     *
     * @return list<string>
     */
    public function tokens(string $phrase): array
    {
        $phrase = mb_strtolower(trim($phrase));
        if ($phrase === '') {
            return [];
        }
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $part) => trim($part), $parts),
            static fn (string $part) => $part !== ''
        )));
    }

    /**
     * Compact alphanumeric form so "tshirt" matches "T-Shirt".
     */
    public function compactToken(string $token): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $token));
    }

    /**
     * @param  list<string>  $phrases
     */
    protected function booleanQuery(array $phrases): string
    {
        $groups = [];
        foreach ($phrases as $phrase) {
            $tokens = $this->tokens($phrase);
            if ($tokens === []) {
                continue;
            }
            $parts = [];
            foreach ($tokens as $token) {
                $escaped = $this->escapeBooleanToken($token);
                if ($escaped === '') {
                    continue;
                }
                $parts[] = '+'.$escaped.'*';
            }
            if ($parts !== []) {
                $groups[] = '('.implode(' ', $parts).')';
            }
        }

        return implode(' ', $groups);
    }

    protected function escapeBooleanToken(string $token): string
    {
        // Strip MySQL boolean operators so user input cannot break AGAINST().
        return preg_replace('/[+\-><()~*\"\\\\@]+/', '', $token) ?? '';
    }

    /**
     * @param  list<string>  $phrases
     */
    protected function applyLikeSearch(Builder $query, array $phrases, string $locale, string $default): Builder
    {
        $locales = array_values(array_unique([$locale, $default]));
        $op = DbGrammar::likeOperator();

        return $query->where(function (Builder $builder) use ($phrases, $locales, $op) {
            foreach ($phrases as $phrase) {
                $tokens = $this->tokens($phrase);
                if ($tokens === []) {
                    continue;
                }

                $builder->orWhere(function (Builder $group) use ($tokens, $phrase, $locales, $op) {
                    $group->where(function (Builder $sku) use ($tokens, $phrase, $op) {
                        $sku->where('sku', $phrase);
                        foreach ($tokens as $token) {
                            $sku->orWhere('sku', $op, PrefixQuery::contains($token));
                            $compact = $this->compactToken($token);
                            if ($compact !== '' && $compact !== mb_strtolower($token)) {
                                $sku->orWhere('sku', $op, PrefixQuery::contains($compact));
                            }
                        }
                    })->orWhereHas('translations', function ($t) use ($tokens, $locales, $op) {
                        $t->whereIn('language_code', $locales);
                        foreach ($tokens as $token) {
                            $like = PrefixQuery::contains($token);
                            $compact = $this->compactToken($token);
                            $t->where(function ($name) use ($like, $compact, $op) {
                                $name->where('name', $op, $like);
                                if ($compact !== '') {
                                    $name->orWhereRaw(
                                        "REPLACE(REPLACE(REPLACE(LOWER(name), '-', ''), ' ', ''), '_', '') {$op} ?",
                                        ['%'.$compact.'%']
                                    );
                                }
                            });
                        }
                    });
                });
            }
        });
    }

    /**
     * Product ids whose name is one or two edits from the query.
     * Used only after an exact search misses and typo tolerance is on.
     *
     * @return list<int>
     */
    protected function typoProductIds(string $q, string $locale, string $default): array
    {
        $tokens = array_values(array_filter(
            $this->tokens($q),
            fn (string $token) => mb_strlen($token) >= 5
        ));
        if ($tokens === []) {
            return [];
        }

        $needles = [];
        foreach ($tokens as $token) {
            $length = mb_strlen($token);
            for ($i = 0; $i <= $length - 3; $i += 2) {
                $needles[] = mb_substr($token, $i, 3);
                if (count($needles) >= 8) {
                    break 2;
                }
            }
        }
        $needles = array_values(array_unique($needles));
        if ($needles === []) {
            return [];
        }

        $locales = array_values(array_unique([$locale, $default]));
        $op = DbGrammar::likeOperator();
        $rows = DB::table('product_translations')
            ->whereIn('language_code', $locales)
            ->where(function ($query) use ($needles, $op) {
                foreach ($needles as $needle) {
                    $query->orWhere('name', $op, PrefixQuery::contains($needle));
                }
            })
            ->limit(300)
            ->get(['product_id', 'name']);

        $ids = [];
        foreach ($rows as $row) {
            $words = $this->tokens((string) $row->name);
            foreach ($tokens as $token) {
                $limit = mb_strlen($token) >= 8 ? 2 : 1;
                foreach ($words as $word) {
                    $compact = $this->compactToken($word);
                    if ($compact === '' || abs(mb_strlen($compact) - mb_strlen($token)) > $limit) {
                        continue;
                    }
                    if (levenshtein($token, $compact) <= $limit) {
                        $ids[] = (int) $row->product_id;
                        continue 3;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{term: string, aliases: list<string>}>
     */
    protected function synonymRows(): array
    {
        try {
            $cached = Cache::get('search.synonyms');
            if (is_array($cached)) {
                return $cached;
            }
            Cache::forget('search.synonyms');
        } catch (\Throwable) {
            Cache::forget('search.synonyms');
        }

        $rows = SearchSynonym::query()
            ->where('is_enabled', true)
            ->get()
            ->map(fn (SearchSynonym $row) => [
                'term' => (string) $row->term,
                'aliases' => $row->aliasList(),
            ])
            ->values()
            ->all();

        Cache::put('search.synonyms', $rows, 300);

        return $rows;
    }

    protected function applyPins(LengthAwarePaginatorContract $results, string $q, string $locale, int $perPage): LengthAwarePaginatorContract
    {
        if ((int) $results->currentPage() !== 1 || ! Schema::hasTable('search_pins')) {
            return $results;
        }

        $pinIds = SearchPin::query()
            ->whereRaw('LOWER(query) = ?', [mb_strtolower($q)])
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('product_id');
        if ($pinIds->isEmpty()) {
            return $results;
        }

        $pinned = ProductSettings::applyListingStockFilter(
            app(CatalogPresenter::class)->withRatings(
                Product::query()->enabled()->visibleIn('search')->with(['translations', 'images', 'brand.translations'])
            )
        )->whereIn('id', $pinIds)->get()->sortBy(fn (Product $p) => $pinIds->search($p->id))->values();

        if ($pinned->isEmpty()) {
            return $results;
        }

        $rest = $results->getCollection()->reject(fn (Product $p) => $pinIds->contains($p->id))->values();
        $collection = $pinned->concat($rest)->take($perPage)->values();
        $added = 0;
        if ($results->total() <= $perPage) {
            $already = $results->getCollection()->pluck('id');
            $added = $pinned->reject(fn (Product $p) => $already->contains($p->id))->count();
        }
        if ($added === 0) {
            $results->setCollection($collection);

            return $results;
        }

        return new LengthAwarePaginator(
            $collection,
            $results->total() + $added,
            $results->perPage(),
            $results->currentPage(),
            ['path' => $results->path(), 'pageName' => $results->getPageName()]
        );
    }
}
