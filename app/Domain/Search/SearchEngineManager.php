<?php

namespace App\Domain\Search;

use App\Domain\Search\Contracts\SearchEngine;
use App\Domain\Search\Engines\DatabaseSearchEngine;
use App\Domain\Search\Engines\ElasticsearchSearchEngine;
use App\Domain\Search\Engines\MeilisearchSearchEngine;
use App\Domain\Search\Engines\MysqlFulltextSearchEngine;
use App\Domain\Settings\ShopSetting;

/**
 * Engine-agnostic product search routing (SearchEngineManager parity+).
 * Prefer configured engine; else Meilisearch → Elasticsearch → MySQL FULLTEXT → database/LIKE.
 */
final class SearchEngineManager
{
    /** @var list<string> */
    public const ENGINES = ['meilisearch', 'elasticsearch', 'mysql_fulltext', 'database'];

    public function __construct(
        private MeilisearchIndex $meilisearch,
        private ElasticsearchIndex $elasticsearch,
    ) {}

    public function preferred(): string
    {
        $configured = strtolower(trim((string) ShopSetting::get('search.engine', '')));
        if (in_array($configured, self::ENGINES, true)) {
            return $configured;
        }
        if ($this->meilisearch->enabled()) {
            return 'meilisearch';
        }
        if ($this->elasticsearch->enabled()) {
            return 'elasticsearch';
        }
        if ((new MysqlFulltextSearchEngine)->available()) {
            return 'mysql_fulltext';
        }

        return 'database';
    }

    public function engine(?string $name = null): SearchEngine
    {
        $name = $name ?? $this->preferred();

        return match ($name) {
            'meilisearch' => new MeilisearchSearchEngine($this->meilisearch),
            'elasticsearch' => new ElasticsearchSearchEngine($this->elasticsearch),
            'mysql_fulltext' => new MysqlFulltextSearchEngine,
            default => new DatabaseSearchEngine,
        };
    }

    /**
     * Resolve product ids: try preferred engine, then fall back through the chain.
     *
     * @return array{engine: string, ids: list<int>}
     */
    public function searchIds(string $q, int $limit = 48): array
    {
        $chain = array_values(array_unique(array_merge([$this->preferred()], self::ENGINES)));

        foreach ($chain as $name) {
            $engine = $this->engine($name);
            if (! $engine->available()) {
                continue;
            }
            $ids = $engine->searchIds($q, $limit);
            if ($ids !== [] || $name === 'database') {
                return ['engine' => $engine->name(), 'ids' => $ids];
            }
        }

        return ['engine' => 'database', 'ids' => []];
    }

    /**
     * @return list<array{name: string, available: bool, preferred: bool}>
     */
    public function describe(): array
    {
        $preferred = $this->preferred();
        $out = [];
        foreach (self::ENGINES as $name) {
            $engine = $this->engine($name);
            $out[] = [
                'name' => $engine->name(),
                'available' => $engine->available(),
                'preferred' => $name === $preferred,
            ];
        }

        return $out;
    }
}
