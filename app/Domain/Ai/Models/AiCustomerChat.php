<?php

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class AiCustomerChat extends Model
{
    protected $table = 'ai_customer_chats';

    protected $fillable = [
        'customer_id',
        'visitor_token',
        'name',
        'email',
        'title',
        'accepted_terms',
        'terms_accepted_at',
        'messages_json',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'accepted_terms' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'messages_json' => 'array',
            'context_json' => 'array',
        ];
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Customer\Models\Customer::class, 'customer_id');
    }
}
