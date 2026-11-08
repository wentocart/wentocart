<?php

namespace Tests\Unit;

use App\Domain\Plugin\PageDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageDocumentUiTest extends TestCase
{
    #[Test]
    public function it_keeps_four_kpi_cards_on_the_page_document(): void
    {
        $clean = PageDocument::sanitize([
            'title' => 'Enquiries',
            'kpis' => [
                ['label' => 'Total', 'value' => '128', 'trend' => 4.2],
                ['label' => 'Open', 'value' => '12', 'trend' => -1],
                ['label' => 'Closed', 'value' => '116'],
                ['label' => 'Reply rate', 'value' => '91%', 'tone' => 'amber'],
                ['label' => 'Dropped', 'value' => '9'],
            ],
        ]);

        $this->assertCount(4, $clean['kpis']);
        $this->assertSame('Total', $clean['kpis'][0]['label']);
        $this->assertSame('128', $clean['kpis'][0]['value']);
        $this->assertSame(4.2, $clean['kpis'][0]['trend']);
        $this->assertSame('amber', $clean['kpis'][3]['tone']);
        $this->assertSame(0.0, $clean['kpis'][2]['trend']);
    }

    #[Test]
    public function it_lifts_kpis_from_the_list_document(): void
    {
        $clean = PageDocument::sanitize([
            'title' => 'Contacts',
            'list' => [
                'kpis' => [
                    ['label' => 'Total', 'value' => '3'],
                ],
                'columns' => [['key' => 'id', 'label' => 'ID']],
                'rows' => [['id' => '1']],
            ],
        ]);

        $this->assertSame('Total', $clean['kpis'][0]['label']);
    }

    #[Test]
    public function it_sanitizes_extension_primary_navigation(): void
    {
        $clean = PageDocument::sanitize([
            'nav' => [
                [
                    'id' => 'Configuration',
                    'label' => '<b>Configuration</b>',
                    'href' => '/admin/extensions/acme/payments',
                    'active' => true,
                ],
                [
                    'id' => 'transactions',
                    'label' => 'Transactions',
                    'label_key' => 'module.acme.payments.nav.transactions',
                    'href' => '/admin/modules/acme/payments/transactions',
                ],
                [
                    'id' => 'unsafe',
                    'label' => 'Unsafe',
                    'href' => 'https://example.test/steal',
                ],
            ],
        ]);

        $this->assertSame([
            [
                'id' => 'configuration',
                'label' => 'Configuration',
                'href' => '/admin/extensions/acme/payments',
                'active' => true,
            ],
            [
                'id' => 'transactions',
                'label' => 'Transactions',
                'href' => '/admin/modules/acme/payments/transactions',
                'active' => false,
                'label_key' => 'module.acme.payments.nav.transactions',
            ],
        ], $clean['nav']);
    }

    #[Test]
    public function it_keeps_button_href_and_variants(): void
    {
        $blocks = PageDocument::blocks([
            [
                'type' => 'button',
                'label' => 'Ask a question',
                'href' => '/m/acme/help/ask',
                'icon' => 'help',
            ],
            [
                'type' => 'button',
                'label' => 'Size guide',
                'href' => '/m/acme/help/size',
                'variant' => 'secondary',
            ],
            [
                'type' => 'link',
                'label' => 'Account',
                'href' => '/account',
                'variant' => 'ghost',
            ],
            [
                'type' => 'kpis',
                'items' => [
                    ['label' => 'Open', 'value' => '4'],
                ],
            ],
        ]);

        $this->assertSame('button', $blocks[0]['type']);
        $this->assertSame('/m/acme/help/ask', $blocks[0]['href']);
        $this->assertSame('primary', $blocks[0]['variant']);
        $this->assertSame('secondary', $blocks[1]['variant']);
        $this->assertSame('ghost', $blocks[2]['variant']);
        $this->assertSame('kpis', $blocks[3]['type']);
        $this->assertSame('Open', $blocks[3]['items'][0]['label']);
    }
}
