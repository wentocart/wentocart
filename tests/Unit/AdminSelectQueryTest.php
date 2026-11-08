<?php

namespace Tests\Unit;

use App\Domain\Settings\AdminSelectQuery;
use InvalidArgumentException;
use Tests\TestCase;

class AdminSelectQueryTest extends TestCase
{
    public function test_prepare_allows_select_and_caps_limit(): void
    {
        $this->assertSame('SELECT 1 LIMIT 100', AdminSelectQuery::prepare('SELECT 1'));
        $this->assertSame('SELECT * FROM products LIMIT 50', AdminSelectQuery::prepare('SELECT * FROM products LIMIT 50'));
        $this->assertSame('SELECT * FROM products LIMIT 100', AdminSelectQuery::prepare('SELECT * FROM products LIMIT 500'));
    }

    public function test_prepare_rejects_writes_and_multiple_statements(): void
    {
        foreach ([
            'DELETE FROM products',
            'INSERT INTO products (sku) VALUES ("x")',
            'UPDATE products SET sku = "x"',
            'DROP TABLE products',
            'SELECT 1; DROP TABLE products',
            'WITH x AS (SELECT 1) DELETE FROM products',
            'SELECT password FROM users',
            'SELECT remember_token FROM users',
            'SELECT * FROM information_schema.tables',
        ] as $sql) {
            try {
                AdminSelectQuery::prepare($sql);
                $this->fail('Expected rejection for: '.$sql);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
