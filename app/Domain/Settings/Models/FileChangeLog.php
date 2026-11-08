<?php

namespace App\Domain\Settings\Models;

use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

#[Fillable(['store_id', 'admin_id', 'action', 'path'])]
class FileChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public static function record(string $action, string $path): void
    {
        try {
            $storeId = app(StoreContext::class)->id();
            static::query()->create([
                'store_id' => $storeId > 0 ? $storeId : null,
                'admin_id' => Auth::guard('admin')->id(),
                'action' => $action,
                'path' => $path,
            ]);
        } catch (Throwable) {
            // Logging must not break the write that triggered it.
        }
    }
}
