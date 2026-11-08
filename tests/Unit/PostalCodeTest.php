<?php

namespace Tests\Unit;

use App\Domain\Localization\PostalCode;
use Tests\TestCase;

class PostalCodeTest extends TestCase
{
    public function test_united_states_requires_a_zip_code(): void
    {
        $spec = PostalCode::spec('US');
        $this->assertTrue($spec['required']);
        $this->assertNull(PostalCode::invalidMessage($spec, '78701'));
        $this->assertNull(PostalCode::invalidMessage($spec, '78701-1234'));
        $this->assertNotNull(PostalCode::invalidMessage($spec, '7870'));
    }

    public function test_india_requires_six_digits(): void
    {
        $spec = PostalCode::spec('IN');
        $this->assertNull(PostalCode::invalidMessage($spec, '110001'));
        $this->assertNotNull(PostalCode::invalidMessage($spec, '11000'));
    }

    public function test_countries_without_postal_codes_stay_optional(): void
    {
        $spec = PostalCode::spec('AE');
        $this->assertFalse($spec['required']);
        $this->assertNull(PostalCode::invalidMessage($spec, ''));
    }
}
