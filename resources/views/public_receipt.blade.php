<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Pembayaran - #{{ $transaction->id }}</title>
    <style>
        /* ... style lama ... */
        body { font-family: 'Courier New', Courier, monospace; background: #f3f4f6; padding: 20px; display: flex; justify-content: center; }
        .receipt { background: #fff; padding: 20px; width: 100%; max-width: 380px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .center { text-align: center; }
        .flex { display: flex; justify-content: space-between; }
        .bold { font-weight: bold; }
        .divider { border-bottom: 1px dashed #000; margin: 10px 0; }
        .mb { margin-bottom: 5px; }
        .logo-img { filter: grayscale(100%); width: 60px; margin: 0 auto; display: block; }
        .qr-img { width: 100px; margin: 15px auto; display: block; } /* Style QR */
    </style>
</head>
<body>
    <div class="receipt">
        <div class="center mb">
            <img src="{{ asset('pos-assets/img/logo-apt.svg') }}" alt="Logo" class="logo-img">
            <div class="bold" style="margin-top:5px;">KOPERASI ANGKASA JAYA</div>
            <div style="font-size:10px;">Bandara APT. Pranoto Samarinda</div>
        </div>
        <div class="divider"></div>
        <div class="flex mb"><span>Waktu</span> <span>{{ $transaction->created_at->format('d M Y H:i') }}</span></div>
        <div class="flex mb"><span>Kasir</span> <span>{{ $transaction->cso->name ?? 'Admin' }}</span></div>
        <div class="flex mb"><span>Supir</span> <span>{{ $transaction->booking->driver->name ?? '-' }}</span></div>
        <div class="divider"></div>
        <div class="mb bold">Rute: {{ $transaction->booking->zoneTo->name ?? 'Manual' }}</div>
        <div class="flex mb">
            <span>Harga</span>
            <span>Rp {{ number_format($transaction->amount, 0, ',', '.') }}</span>
        </div>
        <div class="divider"></div>
        <div class="flex bold" style="font-size: 14px;">
            <span>TOTAL</span>
            <span>Rp {{ number_format($transaction->amount, 0, ',', '.') }}</span>
        </div>
        
        <div class="divider"></div>
        <div class="center">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ route('receipt.show', $transaction->id) }}" class="qr-img">
            <div style="font-size: 10px; color: #555;">Scan QR untuk validasi keaslian</div>
        </div>
        <div class="divider"></div>
        <div class="center" style="font-size: 10px;">Simpan link ini sebagai bukti pembayaran yang sah.</div>
    </div>
</body>
</html>