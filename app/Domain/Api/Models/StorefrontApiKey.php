<?php

namespace App\Domain\Api\Models;

use App\Domain\Store\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StorefrontApiKey extends Model
{
    protected $table = 'storefront_api_keys';

    protected $fillable = [
        'store_id',
        'name',
        'key_prefix',
        'key_hash',
        'rate_limit',
        'is_enabled',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'rate_limit' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return array{model: self, plain: string}
     */
    public static function issue(string $name, ?int $storeId = null, ?int $rateLimit = 100): array
    {
        $secret = Str::lower(Str::random(32));
        $plain = 'pk_storefront_'.$secret;
        $row = self::query()->create([
            'store_id' => $storeId,
            'name' => mb_substr(trim($name), 0, 120),
            'key_prefix' => 'pk_storefront_'.substr($secret, 0, 8),
            'key_hash' => hash('sha256', $plain),
            'rate_limit' => $rateLimit !== null && $rateLimit > 0 ? min(5000, $rateLimit) : null,
            'is_enabled' => true,
        ]);

        return ['model' => $row, 'plain' => $plain];
    }

    public static function findEnabledByPlain(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, 'pk_storefront_')) {
            return null;
        }

        return self::query()
            ->where('key_hash', hash('sha256', $plain))
            ->where('is_enabled', true)
            ->first();
    }
}
