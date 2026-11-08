<?php

namespace Tests\Unit;

use App\Domain\Plugin\PageDocument;
use Tests\TestCase;

class PageDocumentLocalizedTest extends TestCase
{
    public function test_localized_flag_and_type_alias_become_one_field(): void
    {
        $fields = PageDocument::fields([
            [
                'name' => 'headline',
                'type' => 'text',
                'localized' => true,
                'label' => 'Headline',
            ],
            [
                'name' => 'body',
                'type' => 'localized',
                'input' => 'textarea',
                'label' => 'Body',
            ],
        ]);

        $this->assertTrue($fields[0]['localized']);
        $this->assertSame('text', $fields[0]['type']);
        $this->assertTrue($fields[1]['localized']);
        $this->assertSame('textarea', $fields[1]['type']);
    }

    public function test_localized_payload_keeps_language_keys_not_three_fields(): void
    {
        $fields = PageDocument::fields([
            ['name' => 'headline', 'type' => 'text', 'localized' => true, 'label' => 'Headline'],
        ]);

        $code = PageDocument::enabledLanguageCodes()[0] ?? 'en';
        $saved = PageDocument::sanitizeSettingsPayload($fields, [
            'headline' => [
                $code => 'Hello',
                'notalang' => 'drop me',
            ],
        ]);

        $this->assertSame('Hello', $saved['headline'][$code] ?? null);
        $this->assertArrayNotHasKey('notalang', $saved['headline']);
    }

    public function test_plain_string_becomes_default_language_map(): void
    {
        $map = PageDocument::localizedMap('Welcome');
        $this->assertNotSame([], $map);
        $this->assertContains('Welcome', array_values($map));
        $this->assertSame('Welcome', PageDocument::pickLocalized($map, array_key_first($map)));
    }
}
