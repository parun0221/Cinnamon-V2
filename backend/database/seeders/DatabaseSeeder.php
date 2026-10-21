<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@cinnamon.test',
        ]);

        User::factory()->karyawan()->create([
            'name' => 'Karyawan',
            'email' => 'karyawan@cinnamon.test',
        ]);
    }
}
