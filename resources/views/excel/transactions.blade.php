<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="utf-8"></head>
<body>
@php
    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
@endphp
<table border="1" cellspacing="0" cellpadding="6"
       style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt;">
    <tr>
        <td colspan="8" style="background:#0B4DA2;color:#ffffff;font-size:16pt;font-weight:bold;height:34px;">
            &nbsp;TAKSI ANGKASA JAYA — Laporan Log Transaksi
        </td>
    </tr>
    <tr>
        <td colspan="8" style="color:#555555;font-size:10pt;">
            &nbsp;Dibuat: {{ $generatedAt }}@foreach($context as $k => $v) &nbsp;|&nbsp; {{ $k }}: {{ $v }}@endforeach
        </td>
    </tr>
    <tr><td colspan="8" style="height:6px;"></td></tr>

    {{-- Ringkasan --}}
    <tr style="font-weight:bold;background:#eef2ff;color:#1e3a8a;">
        <td colspan="2">Total: {{ $rp($summary['total']) }}</td>
        <td colspan="2">Transaksi: {{ $summary['count'] }}</td>
        <td>QRIS: {{ $rp($summary['qris']) }}</td>
        <td>Tunai Kasir: {{ $rp($summary['cash_cso']) }}</td>
        <td colspan="2">Tunai Supir: {{ $rp($summary['cash_driver']) }}</td>
    </tr>
    <tr><td colspan="8" style="height:6px;"></td></tr>

    {{-- Header kolom --}}
    <tr style="background:#1f2937;color:#ffffff;font-weight:bold;">
        <td>No</td><td>Waktu</td><td>CSO</td><td>Supir</td><td>Destinasi</td>
        <td>Metode</td><td>Status</td><td style="text-align:right;">Jumlah (Rp)</td>
    </tr>

    @foreach($transactions as $i => $t)
        @php
            $b = $t->booking;
            $dest = optional(optional($b)->zoneTo)->name ?: (optional($b)->manual_destination ?: '-');
            $mLabel = $t->method === 'CashDriver' ? 'Tunai (Supir)' : ($t->method === 'CashCSO' ? 'Tunai (Kasir)' : $t->method);
            $p = $t->payout_status ?: 'Unpaid';
            $bg = $i % 2 ? '#ffffff' : '#f3f4f6';
        @endphp
        <tr style="background:{{ $bg }};">
            <td>{{ $i + 1 }}</td>
            <td>{{ \Carbon\Carbon::parse($t->created_at)->format('d/m/Y H:i') }}</td>
            <td>{{ optional(optional($b)->cso)->name ?: 'Self / Driver' }}</td>
            <td>{{ optional(optional($b)->driver)->name ?: '-' }}</td>
            <td>{{ $dest }}</td>
            <td>{{ $mLabel }}</td>
            <td>{{ $p }}</td>
            <td style="text-align:right;mso-number-format:'\#\,\#\#0';">{{ (int) $t->amount }}</td>
        </tr>
    @endforeach

    <tr style="font-weight:bold;background:#0B4DA2;color:#ffffff;">
        <td colspan="7" style="text-align:right;">TOTAL&nbsp;</td>
        <td style="text-align:right;">{{ (int) $summary['total'] }}</td>
    </tr>
</table>
</body>
</html>
