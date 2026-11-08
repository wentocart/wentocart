<?php

namespace Tests\Unit;

use App\Domain\Plugin\ExtensionCatalog;
use App\Domain\Plugin\ExtensionTables;
use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\Support\ModuleBuses;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExtensionAuthorSurfaceTest extends TestCase
{
    public function test_color_and_date_fields_stay_their_own_types(): void
    {
        $fields = PageDocument::fields([
            ['name' => 'swatch', 'type' => 'color', 'label' => 'Swatch'],
            ['name' => 'starts', 'type' => 'date', 'label' => 'Starts'],
        ]);

        $this->assertSame('color', $fields[0]['type']);
        $this->assertSame('date', $fields[1]['type']);
    }

    public function test_hosted_list_rows_keep_view_delete_and_duplicate(): void
    {
        $list = PageDocument::list([
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'name', 'label' => 'Name'],
            ],
            'rows' => [[
                'id' => '4',
                'name' => 'Auction',
                'href' => '/admin/modules/acme/auction/edit?id=4',
                'view_href' => '/admin/modules/acme/auction/view?id=4',
                'delete' => true,
                'duplicate' => true,
            ]],
        ]);

        $row = $list['rows'][0];
        $this->assertSame('/admin/modules/acme/auction/view?id=4', $row['view_href']);
        $this->assertTrue($row['delete']);
        $this->assertTrue($row['duplicate']);
    }

    public function test_save_response_can_ask_for_a_reload(): void
    {
        $doc = PageDocument::sanitize(['title' => 'Saved', 'reload' => true]);

        $this->assertTrue($doc['reload']);
    }

    public function test_new_storefront_slots_are_published(): void
    {
        foreach ([
            'storefront.order.detail',
            'storefront.cart.line',
            'storefront.product.below_trust',
            'storefront.product.buybox',
            'admin.order.actions',
        ] as $id) {
            $this->assertTrue(ExtensionCatalog::hasView($id), $id);
        }
    }

    public function test_buybox_flags_merge_from_slot_callbacks(): void
    {
        $flags = ModuleBuses::productBuybox(['id' => 1]);
        $this->assertFalse($flags['hide_price']);
        $this->assertFalse($flags['hide_qty']);
        $this->assertFalse($flags['hide_cart']);
    }

    public function test_uninstall_drops_only_the_declared_extension_table(): void
    {
        $table = ExtensionTables::physicalName('acme', 'donate', 'gifts');
        $this->assertSame('x_acme_donate_gifts', $table);

        Schema::create($table, function ($blueprint) {
            $blueprint->id();
        });
        $this->assertTrue(Schema::hasTable($table));

        ExtensionTables::drop('acme', 'donate', ['gifts', 'not-a-table!']);
        $this->assertFalse(Schema::hasTable($table));
    }
}
