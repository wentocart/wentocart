<?php

namespace App\Events;

use App\Domain\Notify\Models\AdminNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Optional websocket path for the admin bell (broadcast parity).
 * SSE remains the zero-broker default; this fires when BROADCAST_CONNECTION is not null.
 */
class AdminInboxUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AdminNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.inbox')];
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'href' => $this->notification->href,
            'tone' => $this->notification->tone,
            'at' => optional($this->notification->created_at)?->toIso8601String(),
        ];
    }

    public function broadcastWhen(): bool
    {
        $driver = (string) config('broadcasting.default', 'null');

        return $driver !== '' && $driver !== 'null';
    }
}
