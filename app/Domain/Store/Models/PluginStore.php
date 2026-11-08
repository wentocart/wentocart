<?php

namespace App\Domain\Store\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'plugin', 'is_enabled'])]
class PluginStore extends Model
{
    protected $table = 'plugin_store';

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
