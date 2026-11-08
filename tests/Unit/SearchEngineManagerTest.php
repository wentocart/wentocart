<?php

namespace Tests\Unit;

use App\Domain\Search\SearchEngineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchEngineManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_describes_engines_and_defaults_to_available_chain(): void
    {
        $this->seed();
        $manager = app(SearchEngineManager::class);
        $rows = $manager->describe();
        $names = array_column($rows, 'name');
        $this->assertContains('database', $names);
        $this->assertContains('meilisearch', $names);
        $this->assertContains('elasticsearch', $names);
        $this->assertContains('mysql_fulltext', $names);

        $preferred = $manager->preferred();
        $this->assertContains($preferred, SearchEngineManager::ENGINES);

        $resolved = $manager->searchIds('demo', 10);
        $this->assertArrayHasKey('engine', $resolved);
        $this->assertArrayHasKey('ids', $resolved);
        $this->assertIsArray($resolved['ids']);
    }

    public function test_database_engine_returns_empty_ids(): void
    {
        $manager = app(SearchEngineManager::class);
        $engine = $manager->engine('database');
        $this->assertTrue($engine->available());
        $this->assertSame([], $engine->searchIds('anything'));
    }
}
