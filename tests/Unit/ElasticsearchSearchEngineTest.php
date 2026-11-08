<?php

namespace Tests\Unit;

use App\Domain\Search\Engines\ElasticsearchSearchEngine;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElasticsearchSearchEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_unavailable_when_disabled(): void
    {
        $this->seed();
        ShopSetting::put('search.elasticsearch', false);
        $engine = app(ElasticsearchSearchEngine::class);
        $this->assertFalse($engine->available());
        $this->assertSame([], $engine->searchIds('shoes'));
    }

    public function test_parses_ids_from_es_hits(): void
    {
        $this->seed();
        ShopSetting::put('search.elasticsearch', true);
        ShopSetting::put('search.elasticsearch_host', 'http://127.0.0.1:9200');
        Http::fake([
            'http://127.0.0.1:9200/*' => Http::response([
                'hits' => [
                    'hits' => [
                        ['_id' => '11'],
                        ['_id' => '22'],
                    ],
                ],
            ], 200),
        ]);

        $engine = app(ElasticsearchSearchEngine::class);
        $this->assertTrue($engine->available());
        $this->assertSame([11, 22], $engine->searchIds('boots', 10));
    }
}
