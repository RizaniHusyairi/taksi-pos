<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bukti Pencairan Dana</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
        .logo { font-size: 24px; font-weight: bold; color: #2c3e50; }
        .sublogo { font-size: 10px; color: #7f8c8d; }
        
        .info-box { width: 100%; margin-bottom: 20px; }
        .info-box td { vertical-align: top; padding: 4px 0; }
        .label { font-weight: bold; color: #555; width: 120px; }
        
        .table-responsive { width: 100%; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .total-box { float: right; width: 300px; }
        .total-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 14px; }
        .grand-total { font-weight: bold; font-size: 16px; border-top: 2px solid #333; margin-top: 5px; padding-top: 5px; }
        
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; color: #aaa; border-top: 1px solid #eee; padding-top: 10px; }
        .status-paid { color: #27ae60; font-weight: bold; border: 1px solid #27ae60; padding: 2px 8px; border-radius: 4px; display: inline-block;}
    </style>
</head>
<body>

    <div class="header">
        <div class="logo">KOPERASI ANGKASA JAYA</div>
        <div class="sublogo">Bandara APT Pranoto Samarinda</div>
        <div style="margin-top:5px;">Laporan Pencairan Dana Pengemudi</div>
    </div>

    <table class="info-box" style="border: none;">
        <tr style="border: none;">
            <td style="border: none; width: 50%;">
                <strong>Penerima (Driver):</strong><br>
                Nama: {{ $driver->name }}<br>
                No. HP: {{ $driver->phone_number }}<br>
                Mobil: {{ $driver->driverProfile->car_model }} ({{ $driver->driverProfile->plate_number }})
            </td>
            <td style="border: none; width: 50%;">
                <strong>Detail Pencairan:</strong><br>
                ID Request: #{{ $withdrawal->id }}<br>
                Tanggal Request: {{ \Carbon\Carbon::parse($withdrawal->requested_at)->format('d M Y H:i') }}<br>
                Tanggal Proses: {{ \Carbon\Carbon::parse($withdrawal->processed_at)->format('d M Y H:i') }}<br>
                Status: <span class="status-paid">{{ strtoupper($withdrawal->status) }}</span>
            </td>
        </tr>
    </table>

    <h3>Rincian Transaksi Tunai</h3>
    <p style="font-size:10px; color:#666;">Berikut adalah daftar setoran tunai (CashDriver) yang dikumpulkan dalam pencairan ini:</p>

    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="20%">Tanggal</th>
                <th width="30%">Tujuan</th>
                <th width="15%">Metode</th>
                <th width="30%" class="text-right">Jumlah (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $index => $trx)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ \Carbon\Carbon::parse($trx->created_at)->format('d/m/Y H:i') }}</td>
                <td>
                    {{ $trx->booking->zoneTo->name ?? ($trx->booking->manual_destination ?? 'Manual') }}
                    <br><small style="color:#888;">#{{ $trx->booking->id }}</small>
                </td>
                <td>{{ $trx->method }}</td>
                <td class="text-right">{{ number_format($trx->amount, 0, ',', '.') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-center">Tidak ada rincian transaksi.</td>
            </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="text-right" style="font-weight:bold;">Total Transaksi Tertunda</td>
                <td class="text-right" style="font-weight:bold;">Rp {{ number_format($transactions->sum('amount'), 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="total-box">
        <table style="border:none;">
             <tr style="border:none;">
                <td style="border:none;"><strong>Total Dana Dicairkan:</strong></td>
                <td style="border:none;" class="text-right"><strong>Rp {{ number_format($withdrawal->amount, 0, ',', '.') }}</strong></td>
             </tr>
        </table>
        <div style="font-size:10px; color:#666; margin-top:5px;">
            * Dana ini telah ditransfer ke rekening {{ $driver->driverProfile->bank_name ?? 'Bank' }} 
            ({{ $driver->driverProfile->account_number ?? '-' }})
        </div>
    </div>

    <div class="footer">
        Dokumen ini dicetak otomatis pada {{ $date }}.<br>
        Sistem POS Taksi Bandara - Koperasi Angkasa Pura
    </div>

</body>
</html>
