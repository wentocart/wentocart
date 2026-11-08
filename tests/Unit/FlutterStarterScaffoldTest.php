<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FlutterStarterScaffoldTest extends TestCase
{
    public function test_flutter_starter_files_exist(): void
    {
        $root = dirname(__DIR__, 2).'/mobile/flutter_starter';
        if (! is_dir($root)) {
            $this->markTestSkipped('The Flutter starter is not part of this source tree.');
        }
        $this->assertFileExists($root.'/pubspec.yaml');
        $this->assertFileExists($root.'/lib/main.dart');
        $this->assertFileExists($root.'/lib/wento_api.dart');
        $this->assertFileExists($root.'/README.md');

        $api = file_get_contents($root.'/lib/wento_api.dart');
        $this->assertIsString($api);
        $this->assertStringContainsString('/api/v1/graphql', $api);
        $this->assertStringContainsString('cartAdd', $api);
        $this->assertStringContainsString('wishlistToggle', $api);
        $this->assertStringContainsString('productReviews', $api);
        $this->assertStringContainsString('Authorization', $api);
        $this->assertStringContainsString('accessToken', $api);
    }
}
