<?php

namespace Tests\Unit;

use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\Support\FormFeedback;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormFeedbackTest extends TestCase
{
    #[Test]
    public function it_normalizes_field_maps_and_message_lists(): void
    {
        $bag = FormFeedback::normalize([
            'office_name' => ['Office name is required.'],
            0 => 'Please fix the form.',
        ]);

        $this->assertSame(['Office name is required.', 'Please fix the form.'], $bag['messages']);
        $this->assertSame(['office_name' => 'Office name is required.'], $bag['fields']);
    }

    #[Test]
    public function sanitize_keeps_errors_and_submitted_values(): void
    {
        $document = FormFeedback::apply([
            'title' => 'Configuration',
            'fields' => [
                ['name' => 'office_name', 'type' => 'text', 'label' => 'Office name'],
            ],
            'blocks' => [],
        ], [
            'office_name' => 'Office name is required.',
        ], [
            'office_name' => 'Acme <b>Ltd</b>',
            'ignored' => 'drop me',
        ], 'bottom');

        $clean = PageDocument::sanitize($document);

        $this->assertSame(['Office name is required.'], $clean['errors']);
        $this->assertSame(['office_name' => 'Office name is required.'], $clean['field_errors']);
        $this->assertSame('bottom', $clean['error_placement']);
        $this->assertSame('Acme Ltd', $clean['values']['office_name']);
        $this->assertArrayNotHasKey('ignored', $clean['values']);
    }
}
