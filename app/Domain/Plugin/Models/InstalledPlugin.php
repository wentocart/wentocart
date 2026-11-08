<?php

namespace App\Domain\Plugin\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'vendor', 'name', 'display_name', 'version', 'author', 'description',
    'type', 'capabilities', 'hooks', 'is_bundled', 'is_enabled', 'path', 'checksum',
    'health_status', 'last_error',
])]
class InstalledPlugin extends Model
{
    protected $table = 'plugins';

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'hooks' => 'array',
            'is_bundled' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function idKey(): string
    {
        return $this->vendor.'/'.$this->name;
    }

    public function settings(): HasMany
    {
        return $this->hasMany(PluginSetting::class, 'plugin_id');
    }
}
