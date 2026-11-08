<?php

namespace App\Domain\Customer\Models;

use App\Domain\Store\Concerns\ShopOwned;
use App\Domain\Store\Sharing;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id', 'customer_id', 'name', 'email', 'order_number', 'body', 'status', 'replied_at',
])]
class SupportTicket extends Model
{
    use ShopOwned;

    public const STATUS_OPEN = 'open';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_CLOSED = 'closed';

    protected static string $shopFamily = Sharing::FAMILY_SETTINGS;

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class)->orderBy('id');
    }

    public function snippet(int $max = 80): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->body) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }
}
