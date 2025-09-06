<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Data zona tujuan yang akan kita masukkan
        $zones = [
            [
            'name' => 'Pusat Kota (Big Mall)',
            'price' => 150000
            ],
            [
            'name' => 'Samarinda Square (SS)',
            'price' => 135000
            ],
            [
            'name' => 'Stadion Palaran',
            'price' => 180000
            ],
            [
            'name' => 'Pelabuhan Samarinda',
            'price' => 160000
            ],
            [
            'name' => 'Universitas Mulawarman',
            'price' => 140000
            ],
        ];

        // Loop melalui data dan masukkan ke database
        foreach ($zones as $zone) {
            // Menggunakan updateOrCreate untuk menghindari duplikasi data
            // jika seeder dijalankan lebih dari sekali.
            Zone::updateOrCreate(
                ['name' => $zone['name']], // <-- Mencari zona berdasarkan nama
                ['price' => $zone['price']] // <-- Data yang akan di-insert atau di-update
            );
        }
    }
}
