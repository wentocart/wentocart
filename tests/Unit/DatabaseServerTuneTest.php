<?php

namespace Tests\Unit;

use App\Domain\Installer\DatabaseServerTune;
use PHPUnit\Framework\TestCase;

class DatabaseServerTuneTest extends TestCase
{
    public function test_xampp_default_cache_is_raised_without_passing_the_file_limit(): void
    {
        $targets = DatabaseServerTune::cacheTargets(64, 400, 1214);

        $this->assertSame(800, $targets['table_open_cache']);
        $this->assertSame(2000, $targets['table_definition_cache']);
    }

    public function test_existing_larger_cache_is_kept(): void
    {
        $targets = DatabaseServerTune::cacheTargets(2000, 3000, 8192);

        $this->assertSame(2000, $targets['table_open_cache']);
        $this->assertSame(3000, $targets['table_definition_cache']);
    }

    public function test_tiny_file_limit_is_not_exceeded(): void
    {
        $targets = DatabaseServerTune::cacheTargets(64, 400, 200);

        $this->assertSame(64, $targets['table_open_cache']);
    }

    public function test_xampp_config_snippet_is_updated_in_place(): void
    {
        $cnf = "[mysqld]\ntable_open_cache=64\nsort_buffer_size=512K\n";
        $updated = DatabaseServerTune::withRaisedCache($cnf);

        $this->assertStringContainsString("table_open_cache=800\n", $updated);
        $this->assertStringContainsString("table_definition_cache=2000\n", $updated);
        $this->assertStringContainsString('sort_buffer_size=512K', $updated);
        $this->assertStringContainsString("innodb_buffer_pool_size=128M\n", $updated);
        $this->assertStringContainsString("innodb_flush_log_at_trx_commit=2\n", $updated);
        $this->assertStringNotContainsString('table_open_cache=64', $updated);
    }
}
