<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // Semua authenticated user boleh melihat
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, Category $category): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, Category $category): bool
    {
        return $actor->hasRole('admin');
    }
}
