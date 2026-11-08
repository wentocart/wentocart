<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallCliTest extends TestCase
{
    public function test_doctor_reports_this_php(): void
    {
        $this->artisan('wento:doctor')
            ->expectsOutputToContain('Using')
            ->expectsOutputToContain('PHP');
    }

    public function test_install_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('wento:install')
            ->expectsOutputToContain('wento:doctor')
            ->assertSuccessful();
    }
}
