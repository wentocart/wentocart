<?php

namespace App\Domain\Settings\Models;

use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_code', 'recipient', 'is_enabled', 'store_id'])]
class EmailEventSetting extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'email';
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public static function enabled(string $event, string $recipient): bool
    {
        $row = static::query()
            ->where('event_code', $event)
            ->where('recipient', $recipient)
            ->first();

        return $row ? (bool) $row->is_enabled : true;
    }
}
