@php
    $s   = $report['summary'];
    $rp  = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $kas = $report['cash_position'];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    * { font-family: 'DejaVu Sans', sans-serif; }
    @page { margin: 92px 22px 40px 22px; }
    body { margin: 0; color: #1f2937; font-size: 10px; }

    .header { position: fixed; top: -78px; left: 0; right: 0; height: 62px;
              background: #0B4DA2; color: #fff; padding: 12px 22px; }
    .brand { font-size: 17px; font-weight: bold; letter-spacing: 1px; }
    .brand .accent { color: #FBBF24; }
    .sub { font-size: 10px; color: #cfe0f5; margin-top: 2px; }
    .header .gen { position: absolute; right: 22px; top: 16px; text-align: right;
                   font-size: 8.5px; color: #cfe0f5; }
    .footer { position: fixed; bottom: -28px; left: 22px; right: 22px;
              font-size: 8px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 5px; }

    table.cards { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 2px 0 8px; }
    table.cards td { border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 10px; background: #f9fafb; }
    table.cards td.hi { background: #0B4DA2; border-color: #0B4DA2; }
    table.cards td.warn { background: #fffbeb; border-color: #fde68a; }
    .label { font-size: 7.5px; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
    td.hi .label { color: #bcd4f2; }
    .value { font-size: 13px; font-weight: bold; margin-top: 3px; color: #111827; }
    td.hi .value { color: #fff; }
    .note { font-size: 7.5px; color: #9ca3af; margin-top: 2px; }

    h2 { font-size: 11px; margin: 14px 0 5px; color: #0B4DA2; }
    .hint { font-size: 8px; color: #9ca3af; margin: 0 0 5px; }

    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #1f2937; color: #fff; text-align: left; padding: 6px 8px;
                    font-size: 8px; text-transform: uppercase; letter-spacing: .3px; }
    table.data th.right, table.data td.right { text-align: right; }
    table.data th.center, table.data td.center { text-align: center; }
    table.data td { padding: 5px 8px; border-bottom: 1px solid #eef0f2; font-size: 9px; }
    table.data tr:nth-child(even) td { background: #f9fafb; }
    tfoot td { background: #0B4DA2; color: #fff; font-weight: bold; font-size: 10px; padding: 7px 8px; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand">TAKSI <span class="accent">ANGKASA JAYA</span></div>
        <div class="sub">Laporan Pendapatan — {{ $report['range']['label'] }}</div>
        <div class="gen">Dibuat: {{ $generatedAt }}<br>{{ number_format($s['trx_count'], 0, ',', '.') }} transaksi / {{ $report['range']['days'] }} hari</div>
    </div>
    <div class="footer">Taksi Angkasa Jaya — dokumen internal, dibuat otomatis pada {{ $generatedAt }}.</div>

    <table class="cards"><tr>
        <td class="hi" width="28%">
            <div class="label">Pendapatan Kotor</div>
            <div class="value">{{ $rp($s['gross']) }}</div>
            <div class="note" style="color:#bcd4f2">
                @if($s['change_pct'] !== null)
                    {{ $s['change_pct'] >= 0 ? '+' : '' }}{{ $s['change_pct'] }}% vs periode sebelumnya
                @else
                    Tidak ada pembanding
                @endif
            </div>
        </td>
        <td width="24%">
            <div class="label">Potongan Sistem</div>
            <div class="value">{{ $rp($s['commission']) }}</div>
            <div class="note">{{ round($report['commission_rate'] * 100) }}% berzona · {{ $rp($report['manual_fee_flat']) }} flat manual</div>
        </td>
        <td width="24%">
            <div class="label">Bersih ke Supir</div>
            <div class="value">{{ $rp($s['net_driver']) }}</div>
        </td>
        <td width="24%">
            <div class="label">Rata-rata / Transaksi</div>
            <div class="value">{{ $rp($s['avg_per_trx']) }}</div>
        </td>
    </tr></table>

    <h2>Posisi Kas — Uang Tunai di Luar</h2>
    <p class="hint">Saldo saat ini, di luar rentang tanggal laporan.</p>
    <table class="cards"><tr>
        <td class="warn" width="25%"><div class="label">Di Kasir, Belum Disetor</div><div class="value">{{ $rp($kas['cso_unsettled']) }}</div></td>
        <td class="warn" width="25%"><div class="label">Menunggu Verifikasi</div><div class="value">{{ $rp($kas['cso_processing']) }}</div></td>
        <td class="warn" width="25%"><div class="label">Di Supir, Belum Disetor</div><div class="value">{{ $rp($kas['driver_outstanding']) }}</div></td>
        <td class="warn" width="25%"><div class="label">Total di Luar</div><div class="value">{{ $rp($kas['total_outside']) }}</div></td>
    </tr></table>

    <h2>Metode Bayar</h2>
    <table class="data">
        <thead><tr>
            <th width="40%">Metode</th><th width="15%" class="center">Transaksi</th>
            <th width="20%" class="right">Rata-rata</th><th width="15%" class="right">Porsi</th>
            <th width="20%" class="right">Total</th>
        </tr></thead>
        <tbody>
        @foreach($report['by_method'] as $m)
            <tr>
                <td>{{ $m['label'] }}</td>
                <td class="center">{{ number_format($m['count'], 0, ',', '.') }}</td>
                <td class="right">{{ $rp($m['avg']) }}</td>
                <td class="right">{{ $m['pct'] }}%</td>
                <td class="right">{{ $rp($m['total']) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr>
            <td>TOTAL</td><td class="center">{{ number_format($s['trx_count'], 0, ',', '.') }}</td>
            <td class="right">{{ $rp($s['avg_per_trx']) }}</td><td class="right">100%</td>
            <td class="right">{{ $rp($s['gross']) }}</td>
        </tr></tfoot>
    </table>

    <h2>Pendapatan per Zona Tujuan</h2>
    <table class="data">
        <thead><tr>
            <th width="45%">Zona</th><th width="15%" class="center">Trip</th>
            <th width="20%" class="right">Rata-rata</th><th width="20%" class="right">Pendapatan</th>
        </tr></thead>
        <tbody>
        @forelse($report['by_zone'] as $z)
            <tr>
                <td>{{ $z['zone'] }}</td>
                <td class="center">{{ number_format($z['count'], 0, ',', '.') }}</td>
                <td class="right">{{ $rp($z['avg']) }}</td>
                <td class="right">{{ $rp($z['total']) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" style="text-align:center;color:#9ca3af">Belum ada data pada rentang ini.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Kontribusi CSO</h2>
    <table class="data">
        <thead><tr><th width="60%">Nama CSO</th><th width="20%" class="center">Transaksi</th><th width="20%" class="right">Pendapatan</th></tr></thead>
        <tbody>
        @forelse($report['by_cso'] as $c)
            <tr><td>{{ $c['name'] }}</td><td class="center">{{ number_format($c['count'], 0, ',', '.') }}</td><td class="right">{{ $rp($c['total']) }}</td></tr>
        @empty
            <tr><td colspan="3" style="text-align:center;color:#9ca3af">Belum ada data pada rentang ini.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Kontribusi Supir</h2>
    <table class="data">
        <thead><tr><th width="60%">Nama Supir</th><th width="20%" class="center">Transaksi</th><th width="20%" class="right">Pendapatan</th></tr></thead>
        <tbody>
        @forelse($report['by_driver'] as $d)
            <tr><td>{{ $d['name'] }}</td><td class="center">{{ number_format($d['count'], 0, ',', '.') }}</td><td class="right">{{ $rp($d['total']) }}</td></tr>
        @empty
            <tr><td colspan="3" style="text-align:center;color:#9ca3af">Belum ada data pada rentang ini.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
