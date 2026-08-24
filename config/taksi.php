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