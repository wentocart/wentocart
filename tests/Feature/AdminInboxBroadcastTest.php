<?php

namespace Tests\Feature;

use App\Domain\Notify\AdminInbox;
use App\Events\AdminInboxUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AdminInboxBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_push_dispatches_broadcast_event(): void
    {
        $this->seed();
        Event::fake([AdminInboxUpdated::class]);

        AdminInbox::push('test.ping', 'Broadcast ping', 'hello', '/admin', 'info');

        Event::assertDispatched(AdminInboxUpdated::class, function (AdminInboxUpdated $event) {
            return $event->notification->type === 'test.ping'
                && $event->broadcastWhen() === false; // default BROADCAST_CONNECTION=null
        });
    }

    public function test_broadcast_when_enabled_for_log_driver(): void
    {
        $this->seed();
        config(['broadcasting.default' => 'log']);

        $event = new AdminInboxUpdated(
            \App\Domain\Notify\Models\AdminNotification::query()->create([
                'type' => 'test.enabled',
                'tone' => 'info',
                'title' => 'Enabled',
            ])
        );

        $this->assertTrue($event->broadcastWhen());
        $this->assertSame('inbox.updated', $event->broadcastAs());
        $this->assertNotEmpty($event->broadcastOn());
    }
}
