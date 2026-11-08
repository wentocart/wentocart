<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminGraphqlWave217Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_contact_us_rest_aliases(): void
    {
        $this->seed();
        Mail::fake();

        $res = $this->postJson('/api/v1/store/contact-us', [
            'name' => 'Wave217',
            'email' => 'wave217@example.test',
            'contact' => '+15551212',
            'message' => 'REST-shaped enquiry',
        ]);
        $res->assertCreated();
        $this->assertTrue((bool) $res->json('success'));
        $this->assertTrue((bool) $res->json('ok'));

        $ticket = SupportTicket::query()->latest('id')->first();
        $this->assertNotNull($ticket);
        $this->assertStringContainsString('Phone: +15551212', (string) $ticket->body);

        $alias = $this->postJson('/api/v1/contact-us', [
            'name' => 'Wave217b',
            'email' => 'wave217b@example.test',
            'message' => 'Alias path',
        ]);
        $alias->assertCreated()->assertJsonPath('success', true);

        $bad = $this->postJson('/api/v1/store/contact-us', [
            'email' => 'not-an-email',
        ]);
        $bad->assertStatus(400);
        $this->assertFalse((bool) $bad->json('success'));
        $this->assertNotEmpty((string) $bad->json('message'));
    }
}
