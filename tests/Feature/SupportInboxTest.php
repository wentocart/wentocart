<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\SupportTicket;
use App\Domain\Customer\SupportSettings;
use App\Domain\Store\Models\Store;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_stores_a_ticket_and_emails_staff(): void
    {
        $this->seed();
        Mail::fake();
        Store::query()->first()?->update(['email' => 'merchant@example.test']);

        $this->postJson('/api/v1/contact', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'message' => 'Need help with an order.',
            'order_number' => 'INV-1',
        ])->assertCreated()->assertJsonPath('ok', true)->assertJsonPath('success', true);

        $ticket = SupportTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame('Ada', $ticket->name);
        $this->assertSame('ada@example.test', $ticket->email);
        $this->assertSame('INV-1', $ticket->order_number);
        $this->assertSame(SupportTicket::STATUS_OPEN, $ticket->status);

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('merchant@example.test')
            && str_contains($mail->emailSubject, 'Ada'));
    }

    public function test_staff_notify_emails_can_be_turned_off(): void
    {
        $this->seed();
        Mail::fake();
        Store::query()->first()?->update(['email' => 'merchant@example.test']);
        SupportSettings::save(['notify_email' => false, 'reply_email' => true, 'notify_addresses' => '']);

        $this->postJson('/api/v1/contact', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'message' => 'Hello',
        ])->assertCreated();

        $this->assertSame(1, SupportTicket::query()->count());
        Mail::assertNothingSent();
    }

    public function test_staff_notify_uses_configured_addresses(): void
    {
        $this->seed();
        Mail::fake();
        Store::query()->first()?->update(['email' => 'merchant@example.test']);
        SupportSettings::save([
            'notify_email' => true,
            'reply_email' => true,
            'notify_addresses' => "desk@example.test\nother@example.test",
        ]);

        $this->postJson('/api/v1/contact', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'message' => 'Hello',
        ])->assertCreated();

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('desk@example.test'));
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('other@example.test'));
        Mail::assertNotSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('merchant@example.test'));
    }

    public function test_admin_can_list_and_reply(): void
    {
        $this->seed();
        Mail::fake();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $ticket = SupportTicket::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'body' => 'Where is my order?',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $this->get('/admin/support')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Support')
                ->has('tickets.data', 1));

        $this->post("/admin/support/{$ticket->id}/reply", [
            'body' => 'It ships tomorrow.',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(SupportTicket::STATUS_REPLIED, $ticket->status);
        $this->assertSame('It ships tomorrow.', $ticket->replies()->first()?->body);

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('ada@example.test')
            && str_contains($mail->emailSubject, 'your message'));
    }

    public function test_admin_can_save_notify_settings(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->post('/admin/support/settings', [
            'notify_email' => 1,
            'reply_email' => 0,
            'notify_addresses' => 'help@example.test, desk@example.test',
        ])->assertRedirect();

        $this->assertTrue(SupportSettings::notifyEnabled());
        $this->assertFalse(SupportSettings::replyEmailEnabled());
        $this->assertSame(['help@example.test', 'desk@example.test'], SupportSettings::notifyEmails());
    }
}
