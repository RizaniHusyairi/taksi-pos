<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        // 1. AKUN ADMIN
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Admin Utama',
                'email' => 'admin@taksipos.test',
                'role' => 'admin',
                'password' => Hash::make('123456'),
                'active' => true,
            ]
        );

        // 2. GENERATE 5 AKUN CSO
        for ($i = 1; $i <= 5; $i++) {
            User::updateOrCreate(
                ['username' => 'cso' . $i], 
                [
                    'name' => $faker->name,
                    'email' => 'cso' . $i . '@taksipos.test',
                    'role' => 'cso',
                    'password' => Hash::make('123456'),
                    'active' => true,
                ]
            );
        }

        // 3. GENERATE 30 AKUN DRIVER (DENGAN NOMOR URUT)
        $carModels = ['Toyota Avanza', 'Daihatsu Xenia', 'Toyota Calya', 'Daihatsu Sigra', 'Honda Brio', 'Suzuki Ertiga', 'Toyota Innova'];

        for ($i = 1; $i <= 30; $i++) {
            
            $driver = User::updateOrCreate(
                ['username' => 'driver' . $i], 
                [
                    'name' => $faker->name('male'),
                    'email' => 'driver' . $i . '@taksipos.test',
                    'role' => 'driver',
                    'password' => Hash::make('123456'),
                    'active' => true,
                ]
            );

            $randomPlate = 'KT ' . $faker->numberBetween(1000, 9999) . ' ' . strtoupper($faker->lexify('??'));
            $randomCar = $carModels[array_rand($carModels)];

            $driver->driverProfile()->updateOrCreate(
                ['user_id' => $driver->id],
                [
                    'car_model' => $randomCar,
                    'plate_number' => $randomPlate,
                    'status' => 'offline', // Default offline
                    'line_number' => $i,   // NOMOR URUT TETAP (1 s/d 30)
                ]
            );
        }
    }
}