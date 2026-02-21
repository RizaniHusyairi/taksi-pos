<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Zone;

class ZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Data zona tujuan yang akan kita masukkan
        $zones = [
            ['name' => 'BONTANG', 'price' => 550000],
            ['name' => 'JONGGON', 'price' => 450000],
            ['name' => 'KOTA BANGUN', 'price' => 600000],
            ['name' => 'L4', 'price' => 350000],
            ['name' => 'MARANGKAYU', 'price' => 300000],
            ['name' => 'SAMBOJA', 'price' => 400000],
            ['name' => 'SANGATTA', 'price' => 750000],
            ['name' => 'Zona I ( Depan bandara )', 'price' => 50000],
            ['name' => 'Zona II ( Sungai Siring -Tanah Merah )', 'price' => 100000],
            ['name' => 'Zona III ( Talang Sari - Jalan Juanda )', 'price' => 185000],
            ['name' => 'Zona IV ( Jalan Antasari - Sungai Kujang )', 'price' => 225000],
            ['name' => 'Zona V ( Samarinda Seberang - Loa janan )', 'price' => 275000],
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
