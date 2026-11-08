<?php

namespace Tests\Feature;

use App\Domain\Ai\Providers\OpenAiProvider;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class AdminGraphqlWave345Test extends MarketplaceTestCase
{
    public function test_magic_ai_sends_reference_image_bytes_to_openai(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save([
            'ai_allow_reference_images' => true,
            'ai_max_reference_images' => 3,
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'name' => 'Vision Mug',
                            'short_description' => 'Speckled ceramic',
                            'description' => 'A handmade mug from photos.',
                            'meta_title' => 'Vision Mug',
                            'meta_keywords' => 'mug,ceramic',
                            'meta_description' => 'Photo-grounded listing',
                            'price_suggestion' => 24.5,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $provider = new OpenAiProvider('test-key', 'gpt-4o-mini');
        $out = $provider->complete('Describe this product', [
            'system' => 'JSON only',
            'images' => [[
                'url' => 'data:image/png;base64,'.base64_encode('fakepng'),
            ]],
        ]);
        $this->assertStringContainsString('Vision Mug', $out);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $content = $body['messages'][1]['content'] ?? null;

            return is_array($content)
                && ($content[0]['type'] ?? '') === 'text'
                && ($content[1]['type'] ?? '') === 'image_url';
        });

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave345 Seller',
            'email' => 'w345-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w345-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave345 Shop '.$suffix,
            'shop_slug' => 'w345-shop-'.$suffix,
            'profile_name' => 'Wave345 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0345',
            'email' => 'w345-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0345',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave345 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w345-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $blocked = $this->post('/api/v1/account/seller/ai/generate-product-content', [
            'description' => 'Handmade ceramic mug with speckled glaze for coffee lovers at home.',
            'reference_images' => [UploadedFile::fake()->image('ref.png', 40, 40)],
        ]);
        $blocked->assertStatus(422);
        $this->assertContains((string) ($blocked->json('error') ?? ''), ['ai_disabled', 'ai_unconfigured', 'ai_error']);
    }
}
