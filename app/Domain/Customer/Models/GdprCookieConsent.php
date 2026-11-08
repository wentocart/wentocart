<?php

namespace App\Domain\Customer\Models;

use App\Domain\Customer\GdprSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GdprCookieConsent extends Model
{
    protected $fillable = [
        'customer_id',
        'strictly_necessary',
        'basic',
        'experience',
        'measurements',
        'targeting',
    ];

    protected function casts(): array
    {
        return [
            'strictly_necessary' => 'boolean',
            'basic' => 'boolean',
            'experience' => 'boolean',
            'measurements' => 'boolean',
            'targeting' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function () {
            GdprSchema::ensure();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, bool>
     */
    public function choices(): array
    {
        return [
            'strictly_necessary' => true,
            'basic' => (bool) $this->basic,
            'experience' => (bool) $this->experience,
            'measurements' => (bool) $this->measurements,
            'targeting' => (bool) $this->targeting,
        ];
    }
}
