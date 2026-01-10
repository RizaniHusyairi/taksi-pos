<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('settings')->insert([
            [
                'key' => 'commission_rate',
                'value' => '0.2', // Simpan sebagai '0.2' untuk 20%
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'admin_email',
                'value' => 'leo.rizan68@gmail.com', 
                'created_at' => now(),
                'updated_at' => now(),
            ],
    
        ]);
    }
}
