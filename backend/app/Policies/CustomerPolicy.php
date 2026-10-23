<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, Customer $customer): bool
    {
        return $actor->hasRole('admin');
    }
}
