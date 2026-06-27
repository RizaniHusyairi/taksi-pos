<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Data setting default aplikasi
        $settings = [
            'commission_rate' => '0.2', // Simpan sebagai '0.2' untuk 20%
            'admin_email'     => 'leo.rizan68@gmail.com',
        ];

        // Menggunakan updateOrCreate agar seeder aman dijalankan lebih dari sekali
        // (menghindari UNIQUE constraint violation pada kolom 'key').
        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }
}
