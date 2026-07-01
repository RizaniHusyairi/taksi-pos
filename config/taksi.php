<?php

return [
    'driver_queue' => [
        'latitude' => -0.371975, // APT Pranoto Airport
        'longitude' => 117.257919,
        'radius_km' => 2.6,
    ],

    // Jam operasi pelacakan lokasi driver (default efektif bila admin belum
    // mengatur di Pengaturan). Di luar jam ini aplikasi driver MENGHENTIKAN
    // layanan lokasi demi hemat baterai & privasi — kecuali sedang mengantar
    // (ontrip). Bandingkan waktu dalam WITA (Samarinda). Set start == end
    // (mis. 00:00 & 00:00) untuk 24 jam nonstop.
    'operating' => [
        'timezone' => 'Asia/Makassar', // WITA (UTC+8)
        'start'    => '05:00',
        'end'      => '23:30',
    ],
];