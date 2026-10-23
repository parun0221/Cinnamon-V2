<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // Semua authenticated user boleh melihat produk
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, Product $product): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, Product $product): bool
    {
        return $actor->hasRole('admin');
    }
}
