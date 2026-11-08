<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_shell_is_ok(): void
    {
        $this->seed();

        $this->get('/')->assertOk()->assertSee('WentoCart', false);
        $this->get('/demo-cotton-tee')->assertOk()->assertSee('Demo Cotton Tee', false);
        $this->get('/api/v1/catalog/home')->assertOk()->assertJsonStructure(['featured', 'blocks', 'category_shelves']);
        $this->get('/api/v1/catalog/search?q=Demo')->assertOk();
    }
}
