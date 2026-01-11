<!DOCTYPE html>
<html>
<head>
    <title>Pencairan Dana Berhasil</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Halo, {{ $withdrawal->driver->name }}</h2>
    
    <p>Kabar gembira! Pengajuan pencairan dana Anda telah <strong>DISETUJUI</strong> oleh admin.</p>
    
    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 8px; margin: 20px 0;">
        <h3 style="margin-top: 0; color: #166534;">Rincian Transfer</h3>
        <table style="width: 100%;">
            <tr>
                <td>Jumlah Diterima:</td>
                <td style="font-weight: bold; font-size: 18px;">Rp {{ number_format($withdrawal->amount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td>Bank Tujuan:</td>
                <td>{{ $withdrawal->driver->driverProfile->bank_name ?? 'Bank BTN' }}</td>
            </tr>
            <tr>
                <td>No. Rekening:</td>
                <td>{{ $withdrawal->driver->driverProfile->account_number ?? '-' }}</td>
            </tr>
            <tr>
                <td>Waktu Proses:</td>
                <td>{{ now()->format('d M Y H:i') }}</td>
            </tr>
        </table>
    </div>

    <p>Silakan cek mutasi rekening Anda. Bukti transfer juga dapat dilihat melalui menu <strong>Dompet</strong> di aplikasi.</p>
    
    <p>Terima kasih atas kerja keras Anda!</p>
    <p style="font-size: 12px; color: #777;">Koperasi Angkasa Jaya</p>
</body>
</html>