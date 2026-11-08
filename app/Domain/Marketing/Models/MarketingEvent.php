<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'date'])]
class MarketingEvent extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(NewsletterCampaign::class, 'marketing_event_id');
    }
}
