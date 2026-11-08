<?php

namespace Tests\Feature;

use Tests\TestCase;

class OctaneInstallTest extends TestCase
{
    public function test_octane_package_and_config_are_present(): void
    {
        $this->assertTrue(class_exists(\Laravel\Octane\Octane::class));
        $this->assertFileExists(config_path('octane.php'));
        $this->assertSame('frankenphp', config('octane.server'));
    }

    public function test_performance_report_exposes_octane_status(): void
    {
        $payload = \App\Domain\Settings\PerformanceReport::payload();
        $this->assertArrayHasKey('octane', $payload);
        $this->assertTrue($payload['octane']['installed']);
        $this->assertSame('frankenphp', $payload['octane']['server']);
        $this->assertArrayHasKey('stack', $payload);
    }
}
