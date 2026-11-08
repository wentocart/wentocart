<?php

namespace App\Domain\Tax\Models;

use App\Domain\Localization\Models\Country;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class TaxClass extends Model
{
    public function rules(): HasMany
    {
        return $this->hasMany(TaxRule::class);
    }
}
