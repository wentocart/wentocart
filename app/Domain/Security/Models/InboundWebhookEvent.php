<?php

namespace App\Domain\Security\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['driver', 'event_id', 'payload_hash', 'status', 'http_status', 'response_payload', 'error_message'])]
class InboundWebhookEvent extends Model
{
    protected $table = 'inbound_webhook_events';

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'response_payload' => 'array',
        ];
    }
}
