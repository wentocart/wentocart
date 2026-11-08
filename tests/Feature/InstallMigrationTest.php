<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_dumps_include_catalog_columns(): void
    {
        $mysql = File::get(database_path('schema/mysql-schema.sql'));
        $sqlite = File::get(database_path('schema/sqlite-schema.sql'));

        foreach ([$mysql, $sqlite] as $sql) {
            $this->assertStringContainsString('product_variants', $sql);
            $this->assertStringContainsString('cover_path', $sql);
            $this->assertStringContainsString('virtual_uri', $sql);
        }
    }

    public function test_sqlite_schema_dump_creates_catalog_tables(): void
    {
        $this->assertTrue(
            Schema::hasTable('product_variants'),
            'product_variants should exist after loading sqlite-schema.sql'
        );
        $this->assertTrue(Schema::hasColumn('product_variants', 'cover_image_id'));
        $this->assertTrue(Schema::hasColumn('categories', 'cover_path'));
        $this->assertTrue(Schema::hasColumn('shop_urls', 'virtual_uri'));
    }
}
