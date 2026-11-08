<?php

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class AiAssistantLog extends Model
{
    protected $table = 'ai_assistant_logs';

    protected $fillable = [
        'topic',
        'admin_id',
        'admin_name',
        'admin_email',
        'context_type',
        'chat_json',
    ];

    protected function casts(): array
    {
        return [
            'chat_json' => 'array',
        ];
    }
}
