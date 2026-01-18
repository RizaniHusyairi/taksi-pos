<!DOCTYPE html>
<html>
<head>
    <title>Order Baru Masuk</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f9fafb; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header { text-align: center; border-bottom: 2px solid #f3f4f6; padding-bottom: 15px; margin-bottom: 20px; }
        .header h2 { margin: 0; color: #2563eb; }
        .details { background: #eff6ff; padding: 15px; border-radius: 8px; border: 1px solid #dbeafe; }
        .details table { width: 100%; }
        .details td { padding: 5px 0; }
        .details .label { font-weight: bold; color: #4b5563; width: 120px; }
        .btn { display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 20px; text-align: center; }
        .footer { margin-top: 30px; font-size: 12px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Order Baru Masuk! 🚖</h2>
            <p>Halo {{ $booking->driver->name }}, Anda mendapatkan orderan baru.</p>
        </div>

        <div class="details">
            <table>
                <tr>
                    <td class="label">Tujuan:</td>
                    <td style="font-size: 16px; font-weight: bold;">{{ $booking->zoneTo->name }}</td>
                </tr>
                <tr>
                    <td class="label">Penumpang:</td>
                    <td><a href="tel:{{ $booking->passenger_phone }}">{{ $booking->passenger_phone }}</a></td>
                </tr>
                <tr>
                    <td class="label">Tarif:</td>
                    <td style="color: #166534; font-weight: bold;">Rp {{ number_format($booking->price, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="label">Metode:</td>
                    <td>{{ $booking->transaction->method ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label">Waktu:</td>
                    <td>{{ $booking->created_at->format('d M Y H:i') }}</td>
                </tr>
            </table>
        </div>

        <div style="text-align: center;">
            <p>Silakan segera menuju titik jemput dan hubungi penumpang jika diperlukan.</p>
            
            <a href="{{ $receiptUrl }}" class="btn">Lihat Struk Pembayaran</a>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} Koperasi Angkasa Jaya. <br>
            Email ini dikirim otomatis oleh sistem.
        </div>
    </div>
</body>
</html>