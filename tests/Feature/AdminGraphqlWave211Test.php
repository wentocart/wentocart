<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminGraphqlWave211Test extends TestCase
{
    use RefreshDatabase;

    public function test_integration_lifecycle_emails_and_signed_revoke(): void
    {
        $this->seed();
        Mail::fake();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/integrations', [
            'name' => 'Wave211 Mail Token',
            'neverExpires' => true,
            'permissionMode' => 'same_as_web',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);

        $plain = (string) $create->json('plain_token');
        Mail::assertSent(\App\Mail\ShopHtmlMail::class, function ($mail) use ($admin, $plain) {
            $body = (string) $mail->htmlBody;

            return $mail->hasTo($admin->email)
                && str_contains((string) $mail->emailSubject, 'generated')
                && str_contains($body, 'confirm revoke')
                && ($plain === '' || ! str_contains($body, $plain));
        });

        $confirmUrl = URL::temporarySignedRoute(
            'integrations.token.revoke.confirm',
            now()->addDays(7),
            ['token' => $id]
        );
        $page = $this->get($confirmUrl);
        $page->assertOk();
        $this->assertStringContainsString('Confirm token revoke', $page->getContent());
        $this->assertStringContainsString('Yes, revoke token', $page->getContent());

        // Prefetch/GET must not revoke
        $this->assertTrue(PersonalAccessToken::query()->whereKey($id)->exists());

        $submitUrl = URL::temporarySignedRoute(
            'integrations.token.revoke.submit',
            now()->addDays(7),
            ['token' => $id]
        );
        $revoked = $this->post($submitUrl);
        $revoked->assertOk();
        $this->assertStringContainsString('Token revoked', $revoked->getContent());
        $this->assertFalse(PersonalAccessToken::query()->whereKey($id)->exists());

        Mail::assertSent(\App\Mail\ShopHtmlMail::class, function ($mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && str_contains((string) $mail->emailSubject, 'revoked');
        });
    }
}
