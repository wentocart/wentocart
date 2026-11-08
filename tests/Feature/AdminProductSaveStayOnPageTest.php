<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductSaveStayOnPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => 'Stay On Page Lamp',
            'sku' => 'STAY-SAVE-1',
            'price' => 25,
            'is_enabled' => true,
        ], $overrides));
    }

    public function test_creating_a_product_stays_on_the_edit_page_with_a_toast(): void
    {
        $response = $this->from('/admin/products/create')
            ->post('/admin/products', $this->payload());

        $this->assertRedirectToProductEdit($response, 'STAY-SAVE-1');
        $response->assertSessionHas('success', 'Product saved successfully.');
        $response->assertSessionHas('toast', true);
        $this->assertNotSame('/admin/products', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_updating_a_product_stays_on_the_same_edit_page_with_a_toast(): void
    {
        $this->post('/admin/products', $this->payload());
        $product = Product::query()->where('sku', 'STAY-SAVE-1')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';

        $response = $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'name' => 'Stay On Page Lamp Updated',
            'price' => 30,
        ]));

        $this->assertRedirectToProductEdit($response, $product);
        $response->assertSessionHas('success', 'Product saved successfully.');
        $response->assertSessionHas('toast', true);
    }

    public function test_gift_card_and_virtual_saves_also_stay_on_edit(): void
    {
        $gift = $this->from('/admin/products/create?type=gift_card')->post('/admin/products', $this->payload([
            'type' => 'gift_card',
            'sku' => 'STAY-GC-1',
            'gift_card' => [
                'amount_type' => 'custom',
                'min_amount' => 10,
                'max_amount' => 100,
                'default_amount' => 25,
                'amount_step' => 5,
                'delivery_method' => 'email',
                'validity_type' => 'never',
                'allow_online_redemption' => true,
                'allow_partial_redemption' => true,
                'usage_type' => 'multiple',
            ],
        ]));
        $this->assertRedirectToProductEdit($gift, 'STAY-GC-1');

        $virtual = $this->from('/admin/products/create?type=virtual')->post('/admin/products', $this->payload([
            'type' => 'virtual',
            'sku' => 'STAY-VIRT-1',
            'name' => 'Downloadable guide',
        ]));
        $this->assertRedirectToProductEdit($virtual, 'STAY-VIRT-1');
    }

    public function test_validation_errors_stay_on_the_edit_page(): void
    {
        $this->post('/admin/products', $this->payload());
        $product = Product::query()->where('sku', 'STAY-SAVE-1')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';

        $response = $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'sku' => '',
            'name' => '',
            'price' => -5,
        ]));

        $response->assertRedirect($edit);
        $response->assertSessionHasErrors();
        $this->assertSame($edit, parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_create_validation_errors_stay_on_the_create_page(): void
    {
        $response = $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'sku' => '',
            'name' => '',
        ]));

        $response->assertRedirect('/admin/products/create');
        $response->assertSessionHasErrors();
        $this->assertNull(Product::query()->where('sku', 'STAY-SAVE-1')->first());
    }

    public function test_server_errors_stay_on_the_edit_page_with_an_error_toast(): void
    {
        $this->post('/admin/products', $this->payload());
        $product = Product::query()->where('sku', 'STAY-SAVE-1')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';

        $this->mock(ProductService::class, function ($mock) {
            $mock->shouldReceive('save')->once()->andThrow(new \RuntimeException('forced save failure'));
        });

        $response = $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'name' => 'Will not persist',
        ]));

        $response->assertRedirect($edit);
        $response->assertSessionHas('error', 'Could not save the product.');
        $response->assertSessionHas('toast', true);
        $this->assertSame('Stay On Page Lamp', Product::query()->find($product->id)?->translation()?->name);
    }
}
