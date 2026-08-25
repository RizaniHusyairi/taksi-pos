@php
    $s   = $report['summary'];
    $kas = $report['cash_position'];
    // Nilai uang ditulis sebagai angka mentah, bukan string ber-"Rp", supaya
    // sel-nya bisa langsung dijumlah di Excel.
@endphp
<table border="1">
    <tr><td colspan="5"><b>TAKSI ANGKASA JAYA — Laporan Pendapatan</b></td></tr>
    <tr><td colspan="5">Periode: {{ $report['range']['label'] }} ({{ $report['range']['days'] }} hari)</td></tr>
    <tr><td colspan="5">Dibuat: {{ $generatedAt }}</td></tr>
    <tr></tr>

    <tr><td colspan="5"><b>RINGKASAN</b></td></tr>
    <tr><td>Pendapatan Kotor</td><td>{{ $s['gross'] }}</td></tr>
    <tr><td>Potongan Sistem</td><td>{{ $s['commission'] }}</td></tr>
    <tr><td>Bersih ke Supir</td><td>{{ $s['net_driver'] }}</td></tr>
    <tr><td>Jumlah Transaksi</td><td>{{ $s['trx_count'] }}</td></tr>
    <tr><td>Rata-rata per Transaksi</td><td>{{ $s['avg_per_trx'] }}</td></tr>
    <tr><td>Periode Sebelumnya</td><td>{{ $s['prev_gross'] }}</td></tr>
    <tr><td>Perubahan (%)</td><td>{{ $s['change_pct'] ?? '-' }}</td></tr>
    <tr><td>Tarif komisi berzona</td><td>{{ $report['commission_rate'] }}</td></tr>
    <tr><td>Tarif flat order manual</td><td>{{ $report['manual_fee_flat'] }}</td></tr>
    <tr></tr>

    <tr><td colspan="5"><b>POSISI KAS (saldo saat ini, di luar rentang tanggal)</b></td></tr>
    <tr><td>Di Kasir, Belum Disetor</td><td>{{ $kas['cso_unsettled'] }}</td></tr>
    <tr><td>Menunggu Verifikasi</td><td>{{ $kas['cso_processing'] }}</td></tr>
    <tr><td>Di Supir, Belum Disetor</td><td>{{ $kas['driver_outstanding'] }}</td></tr>
    <tr><td>Total di Luar</td><td>{{ $kas['total_outside'] }}</td></tr>
    <tr></tr>

    <tr><td colspan="5"><b>METODE BAYAR</b></td></tr>
    <tr><td><b>Metode</b></td><td><b>Transaksi</b></td><td><b>Rata-rata</b></td><td><b>Porsi (%)</b></td><td><b>Total</b></td></tr>
    @foreach($report['by_method'] as $m)
        <tr><td>{{ $m['label'] }}</td><td>{{ $m['count'] }}</td><td>{{ $m['avg'] }}</td><td>{{ $m['pct'] }}</td><td>{{ $m['total'] }}</td></tr>
    @endforeach
    <tr></tr>

    <tr><td colspan="5"><b>TREN HARIAN</b></td></tr>
    <tr><td><b>Tanggal</b></td><td><b>Transaksi</b></td><td><b>Pendapatan</b></td></tr>
    @foreach($report['daily'] as $d)
        <tr><td>{{ $d['date'] }}</td><td>{{ $d['count'] }}</td><td>{{ $d['total'] }}</td></tr>
    @endforeach
    <tr></tr>

    <tr><td colspan="5"><b>PER ZONA TUJUAN</b></td></tr>
    <tr><td><b>Zona</b></td><td><b>Trip</b></td><td><b>Rata-rata</b></td><td><b>Pendapatan</b></td></tr>
    @forelse($report['by_zone'] as $z)
        <tr><td>{{ $z['zone'] }}</td><td>{{ $z['count'] }}</td><td>{{ $z['avg'] }}</td><td>{{ $z['total'] }}</td></tr>
    @empty
        <tr><td colspan="4">Belum ada data pada rentang ini.</td></tr>
    @endforelse
    <tr></tr>

    <tr><td colspan="5"><b>KONTRIBUSI CSO</b></td></tr>
    <tr><td><b>Nama</b></td><td><b>Transaksi</b></td><td><b>Pendapatan</b></td></tr>
    @forelse($report['by_cso'] as $c)
        <tr><td>{{ $c['name'] }}</td><td>{{ $c['count'] }}</td><td>{{ $c['total'] }}</td></tr>
    @empty
        <tr><td colspan="3">Belum ada data pada rentang ini.</td></tr>
    @endforelse
    <tr></tr>

    <tr><td colspan="5"><b>KONTRIBUSI SUPIR</b></td></tr>
    <tr><td><b>Nama</b></td><td><b>Transaksi</b></td><td><b>Pendapatan</b></td></tr>
    @forelse($report['by_driver'] as $d)
        <tr><td>{{ $d['name'] }}</td><td>{{ $d['count'] }}</td><td>{{ $d['total'] }}</td></tr>
    @empty
        <tr><td colspan="3">Belum ada data pada rentang ini.</td></tr>
    @endforelse
</table>
