<?php

namespace Tests\Unit;

use App\Domain\Database\DbGrammar;
use Tests\TestCase;

class DbGrammarTest extends TestCase
{
    public function test_like_operator_matches_driver(): void
    {
        $op = DbGrammar::likeOperator();
        if (DbGrammar::isPostgres()) {
            $this->assertSame('ilike', $op);
        } else {
            $this->assertSame('like', $op);
        }
    }

    public function test_concat_fragment_is_driver_aware(): void
    {
        $sql = DbGrammar::concat("'a'", "'b'");
        if (DbGrammar::isPostgres()) {
            $this->assertStringContainsString('||', $sql);
        } else {
            $this->assertStringStartsWith('CONCAT(', $sql);
        }
    }
}
