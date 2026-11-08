<?php

namespace App\Domain\Ops\Models;

use App\Domain\Store\Concerns\ShopOwned;
use App\Domain\Ops\CommerceTopics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Automation extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'settings';

    protected $fillable = [
        'store_id', 'name', 'is_enabled', 'event', 'condition_path', 'condition_equals',
        'ability', 'action_input',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'action_input' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => CommerceTopics::flushListeners());
        static::deleted(fn () => CommerceTopics::flushListeners());
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
