<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Membakar informasi ke dalam foto bukti (bukan sekadar menempel di tampilan).
 *
 * Dipakai untuk foto setoran tunai CSO. Tujuannya membuat foto itu
 * MENJELASKAN DIRINYA SENDIRI: siapa yang menyetor, berapa, tanggal berapa,
 * dan kapan diunggah. Dengan begitu satu foto tidak bisa dipakai ulang untuk
 * setoran lain, dan siapa pun yang membuka berkasnya di luar aplikasi tetap
 * tahu konteksnya.
 *
 * Sengaja dikerjakan di SERVER, bukan di aplikasi: datanya (nominal & nomor
 * setoran) baru pasti setelah server mengunci transaksinya, dan stempel yang
 * dibuat klien bisa dilewati begitu saja.
 */
class ImageWatermark
{
    /** Lebar maksimum hasil akhir. Foto kamera 4000px tidak berguna sebagai bukti. */
    private const MAX_WIDTH = 1280;

    /**
     * Tempel stempel ke berkas di disk 'public'.
     *
     * @param  string    $path   path relatif hasil store(), mis. 'deposit_proofs/xxx.jpg'
     * @param  string[]  $lines  baris teks; baris PERTAMA jadi judul (lebih besar)
     * @return bool      false bila gagal — pemanggil boleh mengabaikannya, foto
     *                   aslinya tetap tersimpan dan tidak ikut hilang.
     */
    public function stamp(string $path, array $lines): bool
    {
        try {
            if (!extension_loaded('gd')) {
                Log::warning('Watermark dilewati: ekstensi GD tidak aktif.');
                return false;
            }

            $disk = Storage::disk('public');
            if (!$disk->exists($path)) {
                return false;
            }

            $absolute = $disk->path($path);
            $img = $this->load($absolute);
            if (!$img) {
                return false;
            }

            $img = $this->resizeToMaxWidth($img);
            $this->drawPanel($img, array_values(array_filter($lines)));

            // Selalu ditulis ulang sebagai JPEG mutu 82: ukuran wajar untuk
            // bukti, dan menghilangkan data EXIF bawaan kamera.
            // imagedestroy() sengaja tidak dipanggil: sejak PHP 8.0 sudah tidak
            // berefek, dan di PHP 8.5 memicu peringatan deprecated.
            $ok = imagejpeg($img, $absolute, 82);

            return (bool) $ok;
        } catch (\Throwable $e) {
            // Foto bukti bersifat OPSIONAL — gagal menstempel tidak boleh
            // menggagalkan setoran yang uangnya sudah terkunci di server.
            Log::error('Gagal menstempel foto setoran: ' . $e->getMessage());
            return false;
        }
    }

    /** Buka gambar apa pun yang didukung GD; null bila bukan gambar. */
    private function load(string $absolute)
    {
        $info = @getimagesize($absolute);
        if (!$info) {
            return null;
        }

        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_PNG  => @imagecreatefrompng($absolute),
            IMAGETYPE_WEBP => @imagecreatefromwebp($absolute),
            default        => null,
        };

        return $img ?: null;
    }

    private function resizeToMaxWidth($img)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= self::MAX_WIDTH) {
            return $img;
        }

        $newW = self::MAX_WIDTH;
        $newH = (int) round($h * ($newW / $w));
        $kecil = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($kecil, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);

        return $kecil;
    }

    /** Panel gelap semi-transparan di bawah foto + teksnya. */
    private function drawPanel($img, array $lines): void
    {
        if (!$lines) {
            return;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // Ukuran font ikut lebar gambar supaya terbaca di foto kecil maupun besar.
        $fontJudul = max(13, (int) round($w * 0.026));
        $fontIsi   = max(11, (int) round($w * 0.019));
        $jarak     = (int) round($fontIsi * 1.75);
        $padding   = max(10, (int) round($w * 0.022));

        $tinggiPanel = $padding * 2
            + (int) round($fontJudul * 1.35)
            + (count($lines) - 1) * $jarak;

        // Panel digambar terpisah lalu ditempel dengan imagecopymerge agar
        // transparansinya berlaku untuk kotaknya saja, bukan teksnya.
        $panel = imagecreatetruecolor($w, $tinggiPanel);
        imagefill($panel, 0, 0, imagecolorallocate($panel, 8, 20, 38));
        imagecopymerge($img, $panel, 0, $h - $tinggiPanel, 0, 0, $w, $tinggiPanel, 62);

        // Garis aksen tipis di tepi atas panel.
        $aksen = imagecolorallocate($img, 41, 171, 226); // AppColors.cyan
        imagefilledrectangle($img, 0, $h - $tinggiPanel, $w, $h - $tinggiPanel + max(2, (int) round($w * 0.003)), $aksen);

        $putih  = imagecolorallocate($img, 255, 255, 255);
        $lembut = imagecolorallocate($img, 205, 220, 238);

        $fontBold  = $this->fontPath('DejaVuSans-Bold.ttf');
        $fontBiasa = $this->fontPath('DejaVuSans.ttf');

        $y = $h - $tinggiPanel + $padding + $fontJudul;

        foreach ($lines as $i => $teks) {
            $isJudul = $i === 0;
            $ukuran  = $isJudul ? $fontJudul : $fontIsi;
            $warna   = $isJudul ? $putih : $lembut;
            $font    = $isJudul ? $fontBold : $fontBiasa;

            if ($font) {
                imagettftext($img, $ukuran, 0, $padding, $y, $warna, $font, $teks);
            } else {
                // Tanpa FreeType/berkas font: font bitmap bawaan GD. Jelek,
                // tapi informasinya tetap terbaca — lebih baik daripada polos.
                imagestring($img, 5, $padding, $y - 14, $teks, $warna);
            }

            $y += $isJudul ? (int) round($fontJudul * 1.35) : $jarak;
        }
    }

    /**
     * Berkas font milik proyek sendiri (resources/fonts), dengan cadangan ke
     * font bawaan dompdf bila ada. null = jatuh ke font bitmap GD.
     */
    private function fontPath(string $nama): ?string
    {
        if (!function_exists('imagettftext')) {
            return null;
        }

        foreach ([
            resource_path('fonts/' . $nama),
            base_path('vendor/dompdf/dompdf/lib/fonts/' . $nama),
        ] as $kandidat) {
            if (is_file($kandidat)) {
                return $kandidat;
            }
        }

        return null;
    }
}
