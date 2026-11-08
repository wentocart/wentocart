<?php

namespace App\Domain\Customer\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_question_id', 'admin_id', 'customer_id', 'body', 'is_official', 'is_approved',
])]
class ProductAnswer extends Model
{
    protected function casts(): array
    {
        return [
            'is_official' => 'boolean',
            'is_approved' => 'boolean',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class, 'product_question_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
