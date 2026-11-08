<?php

namespace Tests\Unit;

use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\Support\ExtensionUpload;
use Tests\TestCase;

class ExtensionUploadTest extends TestCase
{
    public function test_image_and_file_field_types_are_kept(): void
    {
        $fields = PageDocument::fields([
            ['name' => 'logo', 'type' => 'image', 'label' => 'Logo'],
            ['name' => 'manual', 'type' => 'file', 'label' => 'Manual'],
            ['name' => 'pack', 'type' => 'upload', 'label' => 'Pack'],
        ]);

        $this->assertSame('image', $fields[0]['type']);
        $this->assertSame('file', $fields[1]['type']);
        $this->assertSame('file', $fields[2]['type']);
        $this->assertNotEmpty($fields[0]['accept']);
    }

    public function test_foreign_paths_are_dropped(): void
    {
        $this->assertNull(ExtensionUpload::ownedRecord('acme', 'banner', [
            'path' => 'extensions/other/app/secret.jpg',
        ]));
        $this->assertSame([], ExtensionUpload::sanitizeRecord(['path' => '../.env']));
        $owned = ExtensionUpload::ownedRecord('acme', 'banner', [
            'path' => 'extensions/acme/banner/logo.jpg',
            'name' => 'Logo.jpg',
        ]);
        $this->assertSame('extensions/acme/banner/logo.jpg', $owned['path'] ?? null);
    }
}
