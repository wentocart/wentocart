<?php

namespace Tests\Feature;

use App\Domain\Ai\Providers\GeminiProvider;
use Illuminate\Support\Facades\Http;

class AdminGraphqlWave350Test extends MarketplaceTestCase
{
    public function test_gemini_provider_accepts_inline_reference_images(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => '{"name":"Gem Mug"}']],
                    ],
                ]],
            ], 200),
        ]);

        $provider = new GeminiProvider('test-gemini-key', 'gemini-2.0-flash');
        $out = $provider->complete('Write listing', [
            'images' => [[
                'mime' => 'image/png',
                'base64' => base64_encode('pngbytes'),
            ]],
        ]);
        $this->assertStringContainsString('Gem Mug', $out);
        Http::assertSent(function ($request) {
            $parts = data_get($request->data(), 'contents.0.parts');
            if (! is_array($parts) || count($parts) < 2) {
                return false;
            }

            return isset($parts[1]['inline_data']['data']);
        });
    }
}
