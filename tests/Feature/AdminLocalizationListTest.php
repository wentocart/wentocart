<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLocalizationListTest extends TestCase
{
    use RefreshDatabase;

    public function test_international_lists_accept_core_column_filters_and_sorting(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->firstOrFail(), 'admin');

        $this->get('/admin/localization/countries?sort=regions&dir=desc&name=India&regions_min=0')
            ->assertOk();
        $this->get('/admin/localization/currencies?sort=rate&dir=desc&code=USD&rate_min=0')
            ->assertOk();
        $this->get('/admin/localization/languages?sort=code&dir=asc&name=English&id_min=1')
            ->assertOk();
        $this->get('/admin/tax')->assertOk();
    }
}
