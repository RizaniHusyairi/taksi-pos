<?php

return [
    'driver_queue' => [
        'latitude' => -0.371975, // APT Pranoto Airport
        'longitude' => 117.257919,
        'radius_km' => 2.6,

        // Tenggang (menit) bagi supir standby yang keluar area sebelum antriannya
        // hangus otomatis. Nilai efektif bisa diubah admin lewat Pengaturan;
        // 0 = auto-keluar dinonaktifkan (supir tetap ditandai di luar area).
        'out_of_area_grace_minutes' => 60,

        // --- Ketahanan terhadap GPS yang tidak sempurna & HP yang diam ---

        // Sabuk histeresis (km) di batas geofence. Supir yang SUDAH di dalam
        // area baru dianggap keluar setelah melewati radius + sabuk ini.
        // Tanpa ini, supir yang parkir tepat di tepi lingkaran akan bolak-balik
        // "masuk-keluar" karena jitter GPS saja: counter perlintasan jadi
        // sampah dan jam tenggang mulai-berhenti berulang kali.
        'geofence_hysteresis_km' => 0.15,

        // Fix GPS dengan akurasi lebih buruk dari ini (meter) tidak dipercaya
        // untuk memutuskan di dalam / di luar area. Posisinya tetap disimpan
        // (peta CSO & bukti supir masih hidup), tapi keputusan geofence
        // ditunda sampai datang fix yang layak. Radius area cuma 2,6 km —
        // fix meleset 800 m sudah cukup menghanguskan antrian orang.
        'poor_accuracy_meters' => 150,

        // --- Deteksi lokasi palsu (fake GPS) ---
        //
        // Seluruh mesin antrian digerakkan oleh koordinat KIRIMAN KLIEN. Tanpa
        // pemeriksaan di sini, supir cukup memasang aplikasi fake GPS (atau
        // memanggil endpointnya langsung dengan curl) untuk "hadir" di bandara
        // sambil tidur di rumah, dan semua pertahanan lain — histeresis,
        // tenggang luar area, penyapu supir basi — tidak melihat apa pun yang
        // aneh. Dua detektor yang saling menutupi:
        //
        //  1. Flag `is_mocked` dari Android (Position.isMocked). Tegas, tapi
        //     hilang kalau supir memakai APK modifikasi.
        //  2. Lompatan mustahil antar-ping, dihitung di server. Tidak bisa
        //     dimatikan dari sisi klien.

        // Kecepatan (km/jam) yang mustahil bagi kendaraan darat. Sengaja jauh
        // di atas kecepatan jalan raya: yang diburu adalah lompatan puluhan
        // kilometer dalam hitungan detik (fake GPS menghasilkan ribuan km/jam),
        // BUKAN supir yang ngebut. Ambang longgar = nyaris nol salah tuduh.
        'teleport_speed_kmh' => 300.0,

        // Lompatan di bawah jarak ini (km) tidak pernah dianggap teleport,
        // berapa pun kecepatan hitungannya. Dua fix berjarak 2 detik yang
        // meleset 300 m menghasilkan 540 km/jam — itu jitter GPS biasa, bukan
        // kecurangan.
        'teleport_min_km' => 3.0,

        // Selisih waktu minimum (detik) antar dua fix sebelum kecepatan layak
        // dihitung. Pembagi yang terlalu kecil membuat angkanya meledak.
        'teleport_min_seconds' => 5,

        // Supir 'standby' yang tidak mengirim lokasi selama ini (menit)
        // dianggap tidak hadir: dikeluarkan dari antrian oleh perintah
        // terjadwal `queue:sweep-stale`, dan sementara itu tidak akan
        // kebagian order (lihat CsoApiController::readyQueueQuery).
        // Aplikasi mengirim heartbeat tiap 75 detik, jadi ambang ini sangat
        // longgar — ia hanya menangkap HP yang benar-benar berhenti bicara.
        'stale_location_minutes' => 10,
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