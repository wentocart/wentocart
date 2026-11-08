<?php

namespace Tests\Unit;

use App\Support\HexColor;
use PHPUnit\Framework\TestCase;

class HexColorTest extends TestCase
{
    public function test_named_tones_map_to_hex(): void
    {
        $this->assertSame('#7C3AED', HexColor::parse('primary'));
        $this->assertSame('#16A34A', HexColor::parse('success'));
        $this->assertSame('#737373', HexColor::parse('neutral'));
    }

    public function test_hex_is_normalized(): void
    {
        $this->assertSame('#0284C7', HexColor::parse('#0284c7'));
        $this->assertSame('#112233', HexColor::parse('#123'));
        $this->assertTrue(HexColor::isValid('#abc'));
        $this->assertFalse(HexColor::isValid('not-a-color'));
    }
}
