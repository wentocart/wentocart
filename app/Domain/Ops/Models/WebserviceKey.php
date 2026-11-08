<?php

namespace App\Domain\Ops\Models;

use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebserviceKey extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'settings';

    public const PREFIX = 'wntk_';

    protected $hidden = ['key_hash'];

    protected $fillable = [
        'store_id', 'name', 'key_prefix', 'key_hash', 'scopes', 'allowed_ips',
        'is_enabled', 'expires_at', 'last_used_at', 'last_ip', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'is_enabled' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return array{model: self, plain: string}
     */
    public static function issue(array $attributes, string $plain): array
    {
        $hash = self::hashKey($plain);
        $row = static::query()->create(array_merge($attributes, [
            'key_prefix' => substr($plain, 0, 12),
            'key_hash' => $hash,
        ]));

        return ['model' => $row, 'plain' => $plain];
    }

    public static function generatePlain(): string
    {
        return self::PREFIX.bin2hex(random_bytes(32));
    }

    public static function hashKey(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }

    public static function findByPlain(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) < 20) {
            return null;
        }
        $row = static::query()->where('key_hash', self::hashKey($plain))->first();
        if (! $row) {
            hash_equals(self::hashKey('missing'), self::hashKey($plain));

            return null;
        }

        return $row;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function allowsIp(?string $ip): bool
    {
        $allowed = array_values(array_filter(array_map('trim', $this->allowed_ips ?? [])));
        if ($allowed === []) {
            return true;
        }
        $ip = trim((string) $ip);
        if ($ip === '') {
            return false;
        }
        foreach ($allowed as $rule) {
            if (hash_equals($rule, $ip)) {
                return true;
            }
        }

        return false;
    }

    public function isUsable(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function touchUsage(?string $ip): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'last_ip' => $ip ? Str::limit($ip, 45, '') : $this->last_ip,
        ])->save();
    }
}
