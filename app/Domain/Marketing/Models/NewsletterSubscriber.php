<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'ip', 'user_agent', 'is_subscribed'])]
class NewsletterSubscriber extends Model
{
    protected $table = 'newsletter_subscribers';

    protected function casts(): array
    {
        return [
            'is_subscribed' => 'boolean',
        ];
    }
}
