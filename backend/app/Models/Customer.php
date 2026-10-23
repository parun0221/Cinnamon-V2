<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'address', 'contact', 'email', 'is_active'])]
class Customer extends Model
{
    use HasUuids;

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
