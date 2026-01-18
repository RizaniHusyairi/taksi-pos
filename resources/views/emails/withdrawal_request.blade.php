<!DOCTYPE html>
<html>
<head>
    <title>Pengajuan Pencairan Baru</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Halo Admin,</h2>
    
    <p>Terdapat permintaan pencairan dana baru dari driver dengan rincian sebagai berikut:</p>
    
    <table style="width: 100%; max-width: 600px; border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Nama Driver</td>
            <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{ $driver->name }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Jumlah</td>
            <td style="padding: 8px; border-bottom: 1px solid #ddd;">Rp {{ number_format($withdrawal->amount, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Waktu Request</td>
            <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{ $withdrawal->requested_at }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Bank</td>
            <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{ $driver->driverProfile->bank_name ?? '-' }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">No. Rekening</td>
            <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{ $driver->driverProfile->account_number ?? '-' }}</td>
        </tr>
    </table>

    <p>Silakan login ke panel admin untuk memproses pencairan ini.</p>
    
    <a href="{{ url('/admin') }}" style="background-color: #2563eb; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Buka Panel Admin</a>
    
    <p style="margin-top: 30px; font-size: 12px; color: #777;">Email ini dikirim otomatis oleh sistem.</p>
</body>
</html>