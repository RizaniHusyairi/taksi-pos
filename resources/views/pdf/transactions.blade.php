<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    * { font-family: 'DejaVu Sans', sans-serif; }
    @page { margin: 92px 22px 40px 22px; }
    body { margin: 0; color: #1f2937; font-size: 10px; }

    /* Header & footer fixed (muncul di tiap halaman) */
    .header { position: fixed; top: -78px; left: 0; right: 0; height: 62px;
              background: #0B4DA2; color: #fff; padding: 12px 22px; }
    .brand { font-size: 17px; font-weight: bold; letter-spacing: 1px; }
    .brand .accent { color: #FBBF24; }
    .sub { font-size: 10px; color: #cfe0f5; margin-top: 2px; }
    .header .gen { position: absolute; right: 22px; top: 16px; text-align: right;
                   font-size: 8.5px; color: #cfe0f5; }
    .footer { position: fixed; bottom: -28px; left: 22px; right: 22px;
              font-size: 8px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 5px; }

    .chips { margin: 0 0 8px 0; }
    .chip { background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe;
            border-radius: 8px; padding: 2px 8px; margin-right: 4px; font-size: 8.5px; }

    table.cards { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 2px 0 8px; }
    table.cards td { border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px 10px; background: #f9fafb; }
    table.cards td.hi { background: #0B4DA2; border-color: #0B4DA2; }
    .label { font-size: 7.5px; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
    td.hi .label { color: #bcd4f2; }
    .value { font-size: 13px; font-weight: bold; margin-top: 3px; color: #111827; }
    td.hi .value { color: #fff; }

    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #1f2937; color: #fff; text-align: left; padding: 6px 8px;
                    font-size: 8px; text-transform: uppercase; letter-spacing: .3px; }
    table.data th.right, table.data td.right { text-align: right; }
    table.data td { padding: 5px 8px; border-bottom: 1px solid #eef0f2; font-size: 9px; }
    table.data tr:nth-child(even) td { background: #f9fafb; }
    .badge { padding: 2px 6px; border-radius: 5px; font-size: 7.5px; font-weight: bold; }
    .m-qris { background:#ede9fe; color:#6d28d9; } .m-cso { background:#dbeafe; color:#1d4ed8; } .m-driver { background:#ffedd5; color:#c2410c; }
    .s-Paid { background:#dcfce7; color:#15803d; } .s-Processing { background:#fef9c3; color:#a16207; } .s-Unpaid { background:#fee2e2; color:#b91c1c; }
    tfoot td { background: #0B4DA2; color: #fff; font-weight: bold; font-size: 10px; padding: 7px 8px; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand">TAKSI <span class="accent">ANGKASA JAYA</span></div>
        <div class="sub">Laporan Log Transaksi</div>
        <div class="gen">Dibuat: {{ $generatedAt }}<br>{{ number_format($summary['count'],0,',','.') }} transaksi</div>
    </div>
    <div class="footer">Taksi Angkasa Jaya — dokumen internal, dibuat otomatis pada {{ $generatedAt }}.</div>

    @if(count($context))
    <div class="chips">
        @foreach($context as $k => $v)<span class="chip">{{ $k }}: {{ $v }}</span>@endforeach
    </div>
    @endif

    <table class="cards"><tr>
        <td class="hi" width="22%"><div class="label">Total Nilai</div><div class="value">Rp {{ number_format($summary['total'],0,',','.') }}</div></td>
        <td width="14%"><div class="label">Jumlah</div><div class="value">{{ number_format($summary['count'],0,',','.') }}</div></td>
        <td width="22%"><div class="label">QRIS</div><div class="value">Rp {{ number_format($summary['qris'],0,',','.') }}</div></td>
        <td width="21%"><div class="label">Tunai (Kasir)</div><div class="value">Rp {{ number_format($summary['cash_cso'],0,',','.') }}</div></td>
        <td width="21%"><div class="label">Tunai (Supir)</div><div class="value">Rp {{ number_format($summary['cash_driver'],0,',','.') }}</div></td>
    </tr></table>

    <table class="data">
        <thead><tr>
            <th width="3%">No</th><th width="11%">Waktu</th><th width="15%">CSO</th><th width="15%">Supir</th>
            <th width="22%">Destinasi</th><th width="12%">Metode</th><th width="10%">Status</th><th width="12%" class="right">Jumlah</th>
        </tr></thead>
        <tbody>
        @forelse($transactions as $i => $t)
            @php
                $b = $t->booking;
                $dest = optional(optional($b)->zoneTo)->name ?: (optional($b)->manual_destination ?: '-');
                $mClass = $t->method === 'QRIS' ? 'm-qris' : ($t->method === 'CashCSO' ? 'm-cso' : 'm-driver');
                $mLabel = $t->method === 'CashDriver' ? 'Tunai (Supir)' : ($t->method === 'CashCSO' ? 'Tunai (Kasir)' : $t->method);
                $p = $t->payout_status ?: 'Unpaid';
            @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ \Carbon\Carbon::parse($t->created_at)->format('d/m/y H:i') }}</td>
                <td>{{ optional(optional($b)->cso)->name ?: 'Self / Driver' }}</td>
                <td>{{ optional(optional($b)->driver)->name ?: '-' }}</td>
                <td>{{ $dest }}</td>
                <td><span class="badge {{ $mClass }}">{{ $mLabel }}</span></td>
                <td><span class="badge s-{{ $p }}">{{ $p }}</span></td>
                <td class="right">Rp {{ number_format($t->amount, 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="text-align:center; padding:24px; color:#9ca3af;">Tidak ada transaksi untuk filter ini.</td></tr>
        @endforelse
        </tbody>
        @if($summary['count'] > 0)
        <tfoot><tr>
            <td colspan="7">TOTAL — {{ number_format($summary['count'],0,',','.') }} transaksi</td>
            <td class="right">Rp {{ number_format($summary['total'], 0, ',', '.') }}</td>
        </tr></tfoot>
        @endif
    </table>
</body>
</html>
