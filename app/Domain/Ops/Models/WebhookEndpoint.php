<?php

namespace App\Domain\Ops\Models;

use App\Domain\Ops\WebhookCatalog;
use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class WebhookEndpoint extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'settings';

    public const SECRET_PREFIX = 'wths_';

    public const FAIL_DISABLE_AFTER = 25;

    protected $hidden = ['secret', 'secret_ciphertext'];

    protected $fillable = [
        'store_id', 'name', 'url', 'secret', 'secret_ciphertext', 'secret_prefix', 'event_pattern', 'events',
        'is_enabled', 'last_status', 'last_error', 'last_sent_at', 'consecutive_failures', 'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'events' => 'array',
            'last_sent_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('ops.listener_map'));
        static::deleted(fn () => Cache::forget('ops.listener_map'));
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX.bin2hex(random_bytes(32));
    }

    public function setPlainSecret(string $plain): void
    {
        $this->secret_ciphertext = Crypt::encryptString($plain);
        $this->secret_prefix = substr($plain, 0, 8);
        $this->secret = null;
    }

    public function plainSecret(): ?string
    {
        if (filled($this->secret_ciphertext)) {
            try {
                return Crypt::decryptString((string) $this->secret_ciphertext);
            } catch (\Throwable) {
                return null;
            }
        }
        $legacy = trim((string) $this->secret);

        return $legacy !== '' ? $legacy : null;
    }

    /**
     * @return list<string>
     */
    public function subscribedEvents(): array
    {
        $events = $this->events;
        if (is_array($events) && $events !== []) {
            return array_values(array_unique(array_map('strval', $events)));
        }
        $pattern = trim((string) $this->event_pattern);

        return $pattern === '' ? ['*'] : [$pattern];
    }

    public function matches(string $event): bool
    {
        foreach ($this->subscribedEvents() as $pattern) {
            if ($pattern === '*' || $pattern === $event) {
                return true;
            }
            if (str_ends_with($pattern, '*') && str_starts_with($event, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    public static function normalizeEvents(array $events): array
    {
        $allowed = WebhookCatalog::ids();
        $out = [];
        foreach ($events as $event) {
            $event = trim((string) $event);
            if ($event === '*') {
                return ['*'];
            }
            if (in_array($event, $allowed, true)) {
                $out[$event] = $event;
            }
        }

        return array_values($out);
    }
}
