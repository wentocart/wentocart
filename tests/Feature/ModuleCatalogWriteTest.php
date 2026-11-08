<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\Support\ModuleCatalogWrite;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModuleCatalogWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        HookManager::flush();
    }

    public function test_create_update_delete_simple_product_through_module_api(): void
    {
        $writer = $this->writer(['catalog.write']);

        $id = $writer->createProduct([
            'name' => 'Extension Mug',
            'sku' => 'EXT-MUG-1',
            'price' => 9.99,
            'stock_qty' => 5,
            'is_enabled' => true,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $id,
            'sku' => 'EXT-MUG-1',
            'type' => 'simple',
        ]);

        $writer->updateProduct($id, [
            'name' => 'Extension Mug Updated',
            'price' => 11.5,
        ]);

        $product = Product::query()->findOrFail($id);
        $this->assertSame(11.5, (float) $product->price);
        $this->assertSame('Extension Mug Updated', $product->translation()?->name);

        $writer->deleteProduct($id);
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_catalog_paginate_and_search_for_rule_builders(): void
    {
        $api = $this->writer(['catalog.read', 'catalog.write']);
        $id = $api->createProduct([
            'name' => 'Buy Now Spotlight',
            'sku' => 'BUYNOW-SPOT-1',
            'price' => 19,
        ]);

        $hit = $api->paginate('BUYNOW-SPOT', 1, 10);
        $this->assertTrue(collect($hit['data'])->contains(fn ($row) => (int) $row['id'] === $id));
        $this->assertArrayHasKey('last_page', $hit);

        $many = $api->findMany([$id]);
        $this->assertCount(1, $many);
        $this->assertSame('BUYNOW-SPOT-1', $many[0]['sku']);
    }

    public function test_catalog_write_requires_capability(): void
    {
        $writer = $this->writer(['catalog.read']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('catalog.write');
        $writer->createProduct(['name' => 'Nope', 'price' => 1]);
    }

    public function test_non_simple_type_is_rejected(): void
    {
        $writer = $this->writer(['catalog.write']);

        try {
            $writer->createProduct(['name' => 'Bundle', 'price' => 1, 'type' => 'bundle']);
            $this->fail('non-simple type should be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
        }
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function writer(array $capabilities): ModuleCatalogWrite
    {
        $dir = storage_path('framework/testing/'.uniqid('catwrite_', true));
        mkdir($dir);
        file_put_contents($dir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'writer',
            'display_name' => 'Writer',
            'version' => '1.0.0',
            'author' => 'test',
            'type' => 'safe',
            'description' => 'test',
            'capabilities' => $capabilities,
        ]));

        $manifest = PluginManifest::fromArray(
            json_decode((string) file_get_contents($dir.'/app.json'), true, 512, JSON_THROW_ON_ERROR),
            $dir,
            false
        );

        return new ModuleCatalogWrite($manifest);
    }
}
