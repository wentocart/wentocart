<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['subject', 'html_body', 'status', 'sent_count', 'sent_at', 'marketing_event_id', 'customer_group_code'])]
class NewsletterCampaign extends Model
{
    protected function casts(): array
    {
        return [
            'sent_count' => 'integer',
            'sent_at' => 'datetime',
            'marketing_event_id' => 'integer',
        ];
    }
}
