<?php

namespace Tests\Feature;

use App\Domain\Security\Honeypot;
use App\Domain\Security\Models\InboundWebhookEvent;
use App\Domain\Security\Models\SecurityAuditLog;
use App\Domain\Security\SecurityAuditLogger;
use App\Domain\Security\WebhookIdempotency;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SecurityTenOutOfTenTest extends TestCase
{
    use RefreshDatabase;

    public function test_honeypot_allows_legitimate_submissions(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/newsletter', [
            'email' => 'legit.buyer@example.com',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'legit.buyer@example.com',
        ]);
    }

    public function test_honeypot_blocks_bot_with_filled_trap_field(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/newsletter', [
            'email' => 'bot@spammer.org',
            Honeypot::TRAP_FIELD => 'http://spam-link-target.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('submission');

        $this->assertDatabaseMissing('newsletter_subscribers', [
            'email' => 'bot@spammer.org',
        ]);
    }

    public function test_honeypot_blocks_instantaneous_automated_submissions(): void
    {
        $this->seed();

        // Token stamped right now (0 elapsed time)
        $subSecondToken = Crypt::encryptString((string) microtime(true));

        $response = $this->postJson('/api/v1/newsletter', [
            'email' => 'instant.bot@spammer.org',
            Honeypot::TIME_FIELD => $subSecondToken,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('submission');

        $this->assertDatabaseMissing('newsletter_subscribers', [
            'email' => 'instant.bot@spammer.org',
        ]);
    }

    public function test_honeypot_allows_submission_with_natural_delay_token(): void
    {
        $this->seed();

        // Token stamped 2 seconds in the past
        $humanToken = Crypt::encryptString((string) (microtime(true) - 2.5));

        $response = $this->postJson('/api/v1/newsletter', [
            'email' => 'patient.human@example.com',
            Honeypot::TIME_FIELD => $humanToken,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'patient.human@example.com',
        ]);
    }

    public function test_inbound_webhook_idempotency_prevents_duplicate_processing(): void
    {
        $this->seed();

        $callCount = 0;
        $handler = function () use (&$callCount) {
            $callCount++;
            return ['ok' => true, 'processed_call' => $callCount];
        };

        $body = json_encode(['id' => 'evt_stripe_test_1010', 'type' => 'payment_intent.succeeded']);

        // First delivery
        $firstResult = WebhookIdempotency::run('stripe', $body, [], $handler);
        $this->assertSame(200, $firstResult['status']);
        $this->assertSame(1, $callCount);
        $this->assertSame(1, $firstResult['response']['processed_call']);

        $this->assertDatabaseHas('inbound_webhook_events', [
            'driver' => 'stripe',
            'event_id' => 'evt_stripe_test_1010',
            'status' => 'completed',
        ]);

        // Duplicate delivery (network retry / replay)
        $secondResult = WebhookIdempotency::run('stripe', $body, [], $handler);
        $this->assertSame(200, $secondResult['status']);
        // Crucial: Handler was NOT invoked a second time
        $this->assertSame(1, $callCount);
        $this->assertTrue($secondResult['response']['idempotent_replay'] ?? false);
        $this->assertSame(1, $secondResult['response']['processed_call']);
    }

    public function test_security_audit_logger_redacts_credentials(): void
    {
        $this->seed();

        SecurityAuditLogger::log('staff.password_reset', [
            'email' => 'admin@wentocart.test',
            'password' => 'superSecret123',
            'password_confirmation' => 'superSecret123',
            'token' => 'Bearer sensitive_api_token',
            'ip' => '127.0.0.1',
            'note' => 'Staff password updated via admin console',
        ]);

        $log = SecurityAuditLog::query()->where('action', 'staff.password_reset')->latest('id')->first();
        $this->assertNotNull($log);

        $payload = $log->payload;
        $this->assertSame('[REDACTED]', $payload['password']);
        $this->assertSame('[REDACTED]', $payload['password_confirmation']);
        $this->assertSame('[REDACTED]', $payload['token']);
        $this->assertSame('Staff password updated via admin console', $payload['note']);
        $this->assertSame('admin@wentocart.test', $payload['email']);
    }

    public function test_admin_auth_failed_creates_audit_trail_entry(): void
    {
        $this->seed();

        $this->post('/admin/login', [
            'email' => 'wrong.hacker@example.com',
            'password' => 'WrongPassword123!',
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'action' => 'admin.auth.failed',
        ]);

        $log = SecurityAuditLog::query()->where('action', 'admin.auth.failed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('wrong.hacker@example.com', $log->payload['email']);
        $this->assertSame('invalid_credentials', $log->payload['reason']);
    }

    public function test_admin_auth_success_creates_audit_trail_entry(): void
    {
        $this->seed();

        $admin = Admin::query()->first();
        if (! $admin) {
            $admin = Admin::factory()->create([
                'email' => 'audit.admin@example.com',
                'password' => bcrypt('AdminPass123!'),
                'is_enabled' => true,
            ]);
        } else {
            $admin->update([
                'password' => bcrypt('AdminPass123!'),
                'is_enabled' => true,
                'totp_confirmed_at' => null,
            ]);
        }

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'AdminPass123!',
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'action' => 'admin.auth.login',
            'actor_id' => $admin->id,
            'actor_email' => $admin->email,
        ]);
    }
}
