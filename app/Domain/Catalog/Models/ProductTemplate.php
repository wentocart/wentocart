<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'is_default', 'template_groups'])]
class ProductTemplate extends Model
{
    protected $table = 'product_templates';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'template_groups' => 'array',
        ];
    }
}
