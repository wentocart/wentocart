<?php

namespace Tests\Unit;

use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\Support\ModuleRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageDocumentListTest extends TestCase
{
    #[Test]
    public function it_sanitizes_a_hosted_admin_list(): void
    {
        $clean = PageDocument::sanitize([
            'title' => 'Contacts',
            'list' => [
                'bulk' => 'catalog',
                'empty' => 'None yet',
                'create' => [
                    'href' => '/admin/modules/acme/crm/contacts?new=1',
                    'label' => 'Add contact',
                    'icon' => 'plus',
                ],
                'columns' => [
                    ['key' => 'id', 'label' => 'ID'],
                    ['key' => 'email', 'label' => 'Email'],
                ],
                'rows' => [
                    [
                        'id' => '12',
                        'email' => 'ada@example.com',
                        'href' => '/admin/modules/acme/crm/contacts?id=12',
                    ],
                    [
                        'id' => '<script>',
                        'email' => 'drop',
                    ],
                ],
            ],
        ]);

        $this->assertSame('id', $clean['list']['id_key']);
        $this->assertTrue($clean['list']['selectable']);
        $this->assertSame(['enable', 'disable', 'duplicate', 'delete'], array_column($clean['list']['bulk'], 'key'));
        $this->assertCount(1, $clean['list']['rows']);
        $this->assertSame('12', $clean['list']['rows'][0]['id']);
        $this->assertSame('/admin/modules/acme/crm/contacts?id=12', $clean['list']['rows'][0]['href']);
        $this->assertSame('Add contact', $clean['list']['create']['label']);
    }

    #[Test]
    public function it_sanitizes_a_hosted_storefront_list(): void
    {
        $clean = PageDocument::sanitize([
            'title' => 'Store credit list',
            'crumbs' => [
                ['name' => 'Home', 'href' => '/'],
                ['name' => 'Store credit list'],
                ['name' => 'javascript:alert(1)', 'href' => 'javascript:alert(1)'],
            ],
            'list' => [
                'search' => true,
                'per_page_options' => [10, 25, 99],
                'per_page' => 10,
                'page' => 1,
                'last_page' => 2,
                'total' => 12,
                'sort' => 'id',
                'dir' => 'desc',
                'columns' => [
                    ['key' => 'id', 'label' => 'ID', 'sort' => true],
                    ['key' => 'status', 'label' => 'Order status', 'badge' => true],
                ],
                'rows' => [[
                    'id' => '1',
                    'status' => 'Store Credit',
                    'status_tone' => 'danger',
                    'actions' => [
                        [
                            'label' => 'View',
                            'href' => '/m/acme/store-credit/credits?id=1',
                            'icon' => 'eye',
                            'variant' => 'ghost',
                        ],
                    ],
                ]],
            ],
        ]);

        $this->assertSame('Home', $clean['crumbs'][0]['name']);
        $this->assertSame('/', $clean['crumbs'][0]['href']);
        $this->assertCount(3, $clean['crumbs']);
        $this->assertArrayNotHasKey('href', $clean['crumbs'][2]);
        $this->assertTrue($clean['list']['search']);
        $this->assertSame([10, 25], $clean['list']['per_page_options']);
        $this->assertSame('id', $clean['list']['sort']);
        $this->assertSame('desc', $clean['list']['dir']);
        $this->assertTrue($clean['list']['columns'][0]['sort']);
        $this->assertTrue($clean['list']['columns'][1]['badge']);
        $this->assertSame('danger', $clean['list']['rows'][0]['status_tone']);
        $this->assertSame('View', $clean['list']['rows'][0]['actions'][0]['label']);
        $this->assertSame('/m/acme/store-credit/credits?id=1', $clean['list']['rows'][0]['actions'][0]['href']);
    }

    #[Test]
    public function module_request_keeps_posted_ids(): void
    {
        $request = ModuleRequest::capture('POST', [
            'list_action' => 'delete',
            'ids' => ['1', '2', '2', 'nope!'],
        ]);

        $this->assertSame('delete', PageDocument::listActionKey($request->string('list_action')));
        $this->assertSame(['1', '2'], PageDocument::selectedIds($request->all()['ids'] ?? []));
    }
}
