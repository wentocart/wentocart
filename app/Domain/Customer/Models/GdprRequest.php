<?php

namespace App\Domain\Customer\Models;

use App\Domain\Customer\GdprSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GdprRequest extends Model
{
    protected $fillable = ['customer_id', 'reference', 'type', 'status', 'message', 'requested_changes'];

    protected $casts = [
        'requested_changes' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function () {
            GdprSchema::ensure();
        });
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        GdprSchema::ensure();

        return parent::resolveRouteBinding($value, $field);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function typeLabel(): string
    {
        return $this->type === 'delete' ? 'Delete' : 'Edit';
    }

    /**
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    public function changeRows(): array
    {
        $changes = is_array($this->requested_changes) ? $this->requested_changes : [];
        $rows = [];
        foreach ($changes as $key => $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $rows[] = [
                'key' => (string) $key,
                'label' => self::fieldLabel((string) $key),
                'from' => self::displayValue((string) $key, (string) ($pair['from'] ?? '')),
                'to' => self::displayValue((string) $key, (string) ($pair['to'] ?? '')),
            ];
        }

        return $rows;
    }

    public function changeSummary(): string
    {
        if ($this->type === 'delete') {
            return 'Delete the account';
        }
        $labels = array_column($this->changeRows(), 'label');

        return $labels === [] ? '' : implode(', ', $labels);
    }

    public static function fieldLabel(string $key): string
    {
        return match ($key) {
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'email' => 'Email',
            'phone' => 'Phone',
            'date_of_birth' => 'Date of birth',
            'gender' => 'Gender',
            default => $key,
        };
    }

    public static function displayValue(string $key, string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($key !== 'gender') {
            return $value;
        }

        return match ($value) {
            'female' => 'Female',
            'male' => 'Male',
            'other' => 'Other',
            default => $value,
        };
    }
}
