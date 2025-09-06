<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;


class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        User::updateOrCreate(
            ['username' => 'admin'], // \<-- Kondisi untuk mencari user
            [
            'name' => 'Admin Utama',
            'email' => 'admin@taksipos.test', // Email harus unik
            'role' => 'admin',
            'password' => Hash::make('123456'), // \<-- Password di-enkripsi
            'active' => true,
            ]
        );

        // 2. Membuat Akun CSO
        User::updateOrCreate(
            ['username' => 'cso1'],
            [
                'name' => 'CSO Pagi',
                'email' => 'cso1@taksipos.test',
                'role' => 'cso',
                'password' => Hash::make('123456'),
                'active' => true,
            ]
        );

        // Membuat Akun Driver 1
        $driver1 = User::updateOrCreate(
            ['username' => 'driver1'],
            [
                'name' => 'Budi Santoso',
                'email' => 'driver1@taksipos.test',
                'role' => 'driver',
                'password' => Hash::make('123456'),
                'active' => true,
            ]
        );
        // Buat profil driver yang terhubung dengannya
        $driver1->driverProfile()->updateOrCreate(
            ['user_id' => $driver1->id],
            [
                'car' => 'Toyota Avanza',
                'plate' => 'KT 1234 AB',
                'status' => 'available',
            ]
        );

        // Membuat Akun Driver 2
        $driver2 = User::updateOrCreate(
            ['username' => 'driver2'],
            [
                'name' => 'Andi Wijaya',
                'email' => 'driver2@taksipos.test',
                'role' => 'driver',
                'password' => Hash::make('123456'),
                'active' => true,
                        

            ]
            // ... data user driver 2
        );
        $driver2->driverProfile()->updateOrCreate(
            ['user_id' => $driver2->id],
            [
                'status' => 'available',
                'car' => 'Daihatsu Xenia',
                'plate' => 'KT 5678 CD',
            ]
        );
    }
}
