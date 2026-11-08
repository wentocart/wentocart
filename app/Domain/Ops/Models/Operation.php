<?php

namespace App\Domain\Ops\Models;

use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operation extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'settings';

    protected $fillable = [
        'uuid', 'store_id', 'ability', 'source', 'actor_type', 'actor_id',
        'status', 'dry_run', 'reversible', 'summary', 'input', 'result',
        'parent_id', 'reason', 'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'reversible' => 'boolean',
            'input' => 'array',
            'result' => 'array',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OperationItem::class);
    }

    public function canRollback(): bool
    {
        return $this->status === 'completed'
            && $this->reversible
            && $this->rolled_back_at === null
            && ! $this->dry_run;
    }
}
