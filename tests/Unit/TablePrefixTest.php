<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TablePrefixTest extends TestCase
{
    public function test_normalizes_letters_to_uppercase_with_underscore(): void
    {
        $this->assertSame('WBL_', wento_db_prefix('WBL'));
        $this->assertSame('WBL_', wento_db_prefix('wbl'));
        $this->assertSame('WBL_', wento_db_prefix('WBL_'));
        $this->assertSame('NEW_', wento_db_prefix('new_'));
        $this->assertSame('NEW_', wento_db_prefix('new'));
        $this->assertSame('TIGER_', wento_db_prefix('tiger_'));
        $this->assertSame('TIGERRRR_', wento_db_prefix('tigerrrr_____'));
        $this->assertSame('TIA_2_', wento_db_prefix('tia_2_'));
        $this->assertSame('LO_AS_AS_', wento_db_prefix('Lo_as_as_'));
        $this->assertSame('', wento_db_prefix(''));
        $this->assertTrue(wento_db_prefix_valid('tiger_'));
        $this->assertTrue(wento_db_prefix_valid('Lo_as_as_'));
        $this->assertFalse(wento_db_prefix_valid('tiger-shop'));
        $this->assertFalse(wento_db_prefix_valid('_new'));
        $this->assertStringContainsString('delhi-110032', wento_plain_text('delhi-110032 <b>x</b>'));
        $this->assertSame('x', wento_plain_text('<b>x</b>'));
    }
}
