<?php

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class AiAssistantChat extends Model
{
    protected $table = 'ai_assistant_chats';

    protected $fillable = [
        'admin_id',
        'title',
        'context_json',
        'messages_json',
    ];

    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'messages_json' => 'array',
        ];
    }

    public function admin(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Admin::class, 'admin_id');
    }
}
