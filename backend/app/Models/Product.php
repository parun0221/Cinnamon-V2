<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id',
    'sku',
    'name',
    'unit',
    'purchase_price',
    'selling_price',
    'ready_stock',
    'pending_stock',
    'minimum_stock',
    'image_path',
    'is_active',
])]
class Product extends Model
{
    use HasUuids;

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'ready_stock' => 'decimal:3',
        'pending_stock' => 'decimal:3',
        'minimum_stock' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
