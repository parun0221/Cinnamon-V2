<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // Karyawan butuh memilih supplier saat menerima barang
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, Supplier $supplier): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, Supplier $supplier): bool
    {
        return $actor->hasRole('admin');
    }
}
