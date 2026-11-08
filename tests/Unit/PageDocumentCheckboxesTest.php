<?php

namespace Tests\Unit;

use App\Domain\Plugin\PageDocument;
use Tests\TestCase;

class PageDocumentCheckboxesTest extends TestCase
{
    public function test_checkboxes_field_keeps_option_values_and_drops_unknown_ids(): void
    {
        $fields = PageDocument::fields([
            [
                'name' => 'checked_order_statuses',
                'type' => 'checkboxes',
                'label' => 'Order statuses',
                'default' => ['processing', 'injected', 'shipped'],
                'options' => [
                    ['value' => 'processing', 'label' => 'Processing'],
                    ['value' => 'shipped', 'label' => 'Shipped'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                ],
            ],
        ]);

        $this->assertSame('checkboxes', $fields[0]['type']);
        $this->assertSame(['processing', 'shipped'], $fields[0]['default']);

        $saved = PageDocument::sanitizeSettingsPayload($fields, [
            'checked_order_statuses' => ['shipped', 'hacked', 'processing', 'shipped'],
        ]);

        $this->assertSame(['shipped', 'processing'], $saved['checked_order_statuses']);
    }

    public function test_unchecked_checkboxes_persist_as_empty_list(): void
    {
        $fields = PageDocument::fields([
            [
                'name' => 'flags',
                'type' => 'checkboxes',
                'label' => 'Flags',
                'options' => [
                    ['value' => 'a', 'label' => 'A'],
                    ['value' => 'b', 'label' => 'B'],
                ],
            ],
        ]);

        $saved = PageDocument::sanitizeSettingsPayload($fields, []);

        $this->assertSame([], $saved['flags']);
    }
}
