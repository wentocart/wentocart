<?php

namespace App\Domain\Plugin\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plugin_id', 'key', 'value'])]
class PluginSetting extends Model
{
    protected $table = 'plugin_settings';

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(InstalledPlugin::class, 'plugin_id');
    }
}
