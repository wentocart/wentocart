<?php

namespace App\Domain\Dashboard\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'dashboard_id', 'title', 'chart_type', 'metric', 'range_days', 'col_span', 'position', 'options',
])]
class DashboardWidget extends Model
{
    protected function casts(): array
    {
        return [
            'range_days' => 'integer',
            'col_span' => 'integer',
            'position' => 'integer',
            'options' => 'array',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options ?? [], $key, $default);
    }
}
