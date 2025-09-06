<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Panggil seeder lain yang ingin dijalankan di sini
        $this->call([
            UserSeeder::class,
            ZoneSeeder::class,
            // Anda bisa tambahkan seeder lain di sini nanti, misal ZoneSeeder::class
        ]);
    }
}
