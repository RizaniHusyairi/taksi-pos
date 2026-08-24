<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Zone;

class ZoneSeeder extends Seeder
{
    /**
     * Tarif resmi Bandar Udara APT Pranoto Samarinda (poster "Tarif / Sewa"):
     * 5 zona Dalam Kota Samarinda + 5 tujuan Luar Kota.
     * updateOrCreate → idempoten (aman dijalankan berulang / di produksi).
     */
    public function run(): void
    {
        $zones = [
            // === DALAM KOTA SAMARINDA ===
            ['name' => 'ZONA 1', 'price' => 60000,  'category' => 'dalam', 'description' => 'Depan Bandara'],
            ['name' => 'ZONA 2', 'price' => 100000, 'category' => 'dalam', 'description' => 'Sungai Siring - Tanah Merah'],
            ['name' => 'ZONA 3', 'price' => 185000, 'category' => 'dalam', 'description' => 'Talang Sari - Jalan Juanda'],
            ['name' => 'ZONA 4', 'price' => 225000, 'category' => 'dalam', 'description' => 'Jalan Antasari - Sungai Kujang'],
            ['name' => 'ZONA 5', 'price' => 275000, 'category' => 'dalam', 'description' => 'Samarinda Seberang - Loa Janan'],
            // === LUAR KOTA SAMARINDA ===
            ['name' => 'TENGGARONG',  'price' => 350000, 'category' => 'luar', 'description' => null],
            ['name' => 'SANGA-SANGA', 'price' => 450000, 'category' => 'luar', 'description' => null],
            ['name' => 'BONTANG',     'price' => 550000, 'category' => 'luar', 'description' => null],
            ['name' => 'BALIKPAPAN',  'price' => 600000, 'category' => 'luar', 'description' => null],
            ['name' => 'SANGATTA',    'price' => 750000, 'category' => 'luar', 'description' => null],
        ];

        foreach ($zones as $zone) {
            Zone::updateOrCreate(
                ['name' => $zone['name']],
                [
                    'price'       => $zone['price'],
                    'category'    => $zone['category'],
                    'description' => $zone['description'],
                ]
            );
        }
    }
}
