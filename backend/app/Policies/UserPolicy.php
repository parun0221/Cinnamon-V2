<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->hasRole('admin') && $actor->id !== $user->id;
    }
}
