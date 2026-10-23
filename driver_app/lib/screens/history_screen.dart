import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../services/api_service.dart';
import '../utils/api_error.dart';
import '../theme/app_colors.dart';
import '../widgets/sky_header.dart';
import '../widgets/fade_in.dart';
import '../widgets/error_state.dart';

enum _Periode { hariIni, tujuhHari, tigaPuluhHari, pilih }

class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  /// Susun daftar datar: [header tanggal, trip, trip, header, ...].
  /// Header berupa [GrupTanggal]; trip tetap Map aslinya.
  @visibleForTesting
  static List<Object> susunPerTanggal(List<dynamic> history) {
    final entri = <Object>[];
    String? kunciTerakhir;
    GrupTanggal? grup;
    for (final trip in history) {
      final t = _HistoryScreenState._waktu(trip);
      final kunci = t == null ? '-' : '${t.year}-${t.month}-${t.day}';
      if (grup == null || kunci != kunciTerakhir) {
        grup = GrupTanggal(t, []);
        entri.add(grup);
        kunciTerakhir = kunci;
      }
      // Simpan referensi grup: entri.last sudah berupa trip setelah trip
      // pertama, jadi tidak boleh di-cast ke grup (dulu crash di sini).
      grup.trips.add(trip);
      entri.add(trip);
    }
    return entri;
  }

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  final ApiService _apiService = ApiService();
  bool _isLoading = true;
  String? _error;
  List<dynamic> _history = [];

  _Periode _periode = _Periode.tujuhHari;
  DateTimeRange? _rentangPilihan;

  /// Batas baris dari server (lihat DriverApiController::getTripHistory).
  static const _batasServer = 200;

  static const _oranye = Color(0xFFF57C00);

  final _rupiah = NumberFormat.currency(
    locale: 'id_ID',
    symbol: 'Rp ',
    decimalDigits: 0,
  );

  // Nama hari/bulan ditulis manual: aplikasi ini sengaja tidak memanggil
  // initializeDateFormatting(), jadi DateFormat berlocale 'id_ID' akan
  // melempar (dijaga test/date_format_locale_test.dart).
  static const _hari = [
    'Senin',
    'Selasa',
    'Rabu',
    'Kamis',
    'Jumat',
    'Sabtu',
    'Minggu',
  ];
  static const _bulan = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
  ];

  @override
  void initState() {
    super.initState();
    _fetchHistory();
  }

  DateTimeRange _rentang() {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    return switch (_periode) {
      _Periode.hariIni => DateTimeRange(start: today, end: today),
      _Periode.tujuhHari => DateTimeRange(
        start: today.subtract(const Duration(days: 6)),
        end: today,
      ),
      _Periode.tigaPuluhHari => DateTimeRange(
        start: today.subtract(const Duration(days: 29)),
        end: today,
      ),
      _Periode.pilih =>
        _rentangPilihan ?? DateTimeRange(start: today, end: today),
    };
  }

  Future<void> _fetchHistory() async {
    final r = _rentang();
    final ymd = DateFormat('yyyy-MM-dd');
    try {
      final response = await _apiService.getTripHistory(
        dateFrom: ymd.format(r.start),
        dateTo: ymd.format(r.end),
      );
      if (mounted) {
        setState(() {
          _history = response.data;
          _error = null;
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = apiErrorMessage(e);
          _isLoading = false;
        });
      }
    }
  }

  void _gantiPeriode(_Periode p) {
    if (p == _periode && p != _Periode.pilih) return;
    setState(() {
      _periode = p;
      _isLoading = true;
    });
    _fetchHistory();
  }

  Future<void> _pilihTanggal() async {
    final now = DateTime.now();
    final hasil = await showDateRangePicker(
      context: context,
      firstDate: DateTime(now.year - 2),
      lastDate: now,
      initialDateRange: _rentangPilihan ?? _rentang(),
      helpText: 'Pilih rentang tanggal',
      cancelText: 'Batal',
      confirmText: 'Pilih',
      saveText: 'Terapkan',
    );
    if (hasil == null) return;
    _rentangPilihan = hasil;
    _gantiPeriode(_Periode.pilih);
  }

  // === Pembantu data ======================================================

  static double _angka(dynamic v) => double.tryParse('$v') ?? 0;

  /// Server mengirim waktu UTC ("…Z"); tampilkan dalam jam ponsel.
  static DateTime? _waktu(dynamic trip) =>
      DateTime.tryParse('${trip['created_at'] ?? ''}')?.toLocal();

  static bool _tunaiSupir(dynamic trip) => trip['method'] == 'CashDriver';
  static bool _belumSelesai(dynamic trip) => trip['payout_status'] != 'Paid';

  String _labelTanggal(DateTime d) {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final hari = DateTime(d.year, d.month, d.day);
    final selisih = today.difference(hari).inDays;
    if (selisih == 0) return 'Hari ini';
    if (selisih == 1) return 'Kemarin';
    final tahun = d.year == now.year ? '' : ' ${d.year}';
    return '${_hari[d.weekday - 1]}, ${d.day} ${_bulan[d.month - 1]}$tahun';
  }

  String _tanggalPendek(DateTime d) => '${d.day} ${_bulan[d.month - 1]}';

  String _labelPeriode() {
    final r = _rentang();
    return switch (_periode) {
      _Periode.hariIni => 'Hari ini',
      _Periode.tujuhHari => '7 hari terakhir',
      _Periode.tigaPuluhHari => '30 hari terakhir',
      _Periode.pilih =>
        r.start == r.end
            ? _tanggalPendek(r.start)
            : '${_tanggalPendek(r.start)} – ${_tanggalPendek(r.end)}',
    };
  }

  // === Build ==============================================================

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      body: Column(
        children: [
          const SkyHeader(
            title: 'Riwayat Perjalanan',
            subtitle: 'Catatan trip & pendapatan Anda',
          ),
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : (_error != null && _history.isEmpty)
                ? _errorBody()
                : RefreshIndicator(
                    color: AppColors.skyBlue,
                    onRefresh: _fetchHistory,
                    child: _daftar(),
                  ),
          ),
        ],
      ),
    );
  }

  Widget _daftar() {
    final entri = HistoryScreen.susunPerTanggal(_history);

    final atas = <Widget>[
      _ringkasan(),
      const SizedBox(height: 14),
      _pilihanPeriode(),
      const SizedBox(height: 6),
      if (_history.length >= _batasServer) _catatanTerpotong(),
      if (_history.isEmpty) _emptyState(),
    ];

    return ListView.builder(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 24),
      itemCount: atas.length + entri.length,
      itemBuilder: (context, i) {
        if (i < atas.length) return atas[i];
        final e = entri[i - atas.length];
        if (e is GrupTanggal) return _headerGrup(e);
        return FadeInUp(
          delayMs: ((i - atas.length) * 35).clamp(0, 250),
          child: _tripCard(e),
        );
      },
    );
  }

  // === Ringkasan ==========================================================

  Widget _ringkasan() {
    var bersih = 0.0;
    var menungguCair = 0.0;
    var komisiTerutang = 0.0;
    for (final t in _history) {
      bersih += _angka(t['driver_share']);
      if (_belumSelesai(t)) {
        if (_tunaiSupir(t)) {
          komisiTerutang += _angka(t['commission']);
        } else {
          menungguCair += _angka(t['driver_share']);
        }
      }
    }

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.deepBlue, AppColors.skyBlue],
        ),
        borderRadius: BorderRadius.circular(22),
        boxShadow: [
          BoxShadow(
            color: AppColors.deepBlue.withValues(alpha: 0.25),
            blurRadius: 20,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                'Pendapatan bersih',
                style: GoogleFonts.outfit(
                  color: Colors.white.withValues(alpha: 0.85),
                  fontSize: 13,
                ),
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 4,
                ),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.18),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  _labelPeriode(),
                  style: GoogleFonts.outfit(
                    color: Colors.white,
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              _rupiah.format(bersih),
              style: GoogleFonts.outfit(
                color: Colors.white,
                fontSize: 30,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              _statMini(Icons.local_taxi_rounded, '${_history.length}', 'Trip'),
              _pemisahStat(),
              _statMini(
                Icons.schedule_rounded,
                _rupiah.format(menungguCair),
                'Menunggu cair',
              ),
              _pemisahStat(),
              _statMini(
                Icons.payments_rounded,
                _rupiah.format(komisiTerutang),
                'Komisi belum disetor',
                sorot: komisiTerutang > 0,
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _statMini(
    IconData icon,
    String nilai,
    String label, {
    bool sorot = false,
  }) {
    final warna = sorot ? const Color(0xFFFFD180) : Colors.white;
    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 16, color: warna),
          const SizedBox(height: 4),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              nilai,
              style: GoogleFonts.outfit(
                color: warna,
                fontWeight: FontWeight.w700,
                fontSize: 14,
              ),
            ),
          ),
          Text(
            label,
            maxLines: 2,
            style: GoogleFonts.outfit(
              color: Colors.white.withValues(alpha: 0.75),
              fontSize: 10.5,
              height: 1.2,
            ),
          ),
        ],
      ),
    );
  }

  Widget _pemisahStat() => Container(
    width: 1,
    height: 40,
    margin: const EdgeInsets.symmetric(horizontal: 10),
    color: Colors.white.withValues(alpha: 0.2),
  );

  // === Filter periode =====================================================

  Widget _pilihanPeriode() {
    Widget chip(
      String label,
      _Periode p, {
      IconData? icon,
      VoidCallback? onTap,
    }) {
      final aktif = _periode == p;
      return Padding(
        padding: const EdgeInsets.only(right: 8),
        child: ChoiceChip(
          selected: aktif,
          showCheckmark: false,
          avatar: icon == null
              ? null
              : Icon(
                  icon,
                  size: 16,
                  color: aktif ? Colors.white : AppColors.inkSoft,
                ),
          label: Text(p == _Periode.pilih && aktif ? _labelPeriode() : label),
          labelStyle: GoogleFonts.outfit(
            fontSize: 12.5,
            fontWeight: FontWeight.w600,
            color: aktif ? Colors.white : AppColors.inkSoft,
          ),
          selectedColor: AppColors.skyBlue,
          backgroundColor: Colors.white,
          side: BorderSide(
            color: aktif
                ? AppColors.skyBlue
                : AppColors.inkFaint.withValues(alpha: 0.4),
          ),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
          ),
          onSelected: (_) => onTap != null ? onTap() : _gantiPeriode(p),
        ),
      );
    }

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          chip('Hari ini', _Periode.hariIni),
          chip('7 Hari', _Periode.tujuhHari),
          chip('30 Hari', _Periode.tigaPuluhHari),
          chip(
            'Pilih tanggal',
            _Periode.pilih,
            icon: Icons.calendar_month_rounded,
            onTap: _pilihTanggal,
          ),
        ],
      ),
    );
  }

  Widget _catatanTerpotong() {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Text(
        'Menampilkan $_batasServer trip terbaru. Persempit tanggal untuk melihat sisanya.',
        style: GoogleFonts.outfit(fontSize: 11.5, color: AppColors.inkSoft),
      ),
    );
  }

  // === Grup per tanggal ===================================================

  Widget _headerGrup(GrupTanggal g) {
    final total = g.trips.fold<double>(
      0,
      (s, t) => s + _angka(t['driver_share']),
    );
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 16, 4, 8),
      child: Row(
        children: [
          Text(
            g.tanggal == null ? 'Tanpa tanggal' : _labelTanggal(g.tanggal!),
            style: GoogleFonts.outfit(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: AppColors.ink,
            ),
          ),
          const Spacer(),
          Text(
            '${g.trips.length} trip · ${_rupiah.format(total)}',
            style: GoogleFonts.outfit(fontSize: 12, color: AppColors.inkSoft),
          ),
        ],
      ),
    );
  }

  Widget _errorBody() {
    return RefreshIndicator(
      color: AppColors.skyBlue,
      onRefresh: _fetchHistory,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          SizedBox(
            height: 420,
            child: ErrorState(message: _error!, onRetry: _fetchHistory),
          ),
        ],
      ),
    );
  }

  Widget _emptyState() {
    return Padding(
      padding: const EdgeInsets.only(top: 48),
      child: Column(
        children: [
          Icon(
            Icons.flight_takeoff_rounded,
            size: 64,
            color: AppColors.inkFaint.withValues(alpha: 0.5),
          ),
          const SizedBox(height: 16),
          Text(
            'Belum ada perjalanan pada periode ini.',
            textAlign: TextAlign.center,
            style: GoogleFonts.outfit(color: AppColors.inkFaint, fontSize: 15),
          ),
        ],
      ),
    );
  }

  // === Kartu trip =========================================================

  Widget _tripCard(dynamic trip) {
    final booking = trip['booking'] ?? {};
    final zona = booking['zone_to']?['name'];
    final manual = booking['manual_destination'];
    final tujuan = zona ?? manual ?? 'Tujuan tidak tercatat';
    final waktu = _waktu(trip);
    final tunai = _tunaiSupir(trip);

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          BoxShadow(
            color: AppColors.deepBlue.withValues(alpha: 0.06),
            blurRadius: 14,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      // Garis kiri oranye = komisi tunai belum disetor. Dipasang di kotak
      // dalam karena Flutter menolak border tak seragam + borderRadius.
      child: Container(
        padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
        decoration: tunai && _belumSelesai(trip)
            ? const BoxDecoration(
                border: Border(left: BorderSide(color: _oranye, width: 4)),
              )
            : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 52,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        waktu == null
                            ? '--:--'
                            : DateFormat('HH:mm').format(waktu),
                        style: GoogleFonts.outfit(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: AppColors.ink,
                        ),
                      ),
                      Text(
                        '#${booking['id'] ?? trip['booking_id'] ?? '-'}',
                        style: GoogleFonts.outfit(
                          fontSize: 11,
                          color: AppColors.inkFaint,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '$tujuan',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: GoogleFonts.outfit(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: AppColors.ink,
                          height: 1.2,
                        ),
                      ),
                      if (zona == null && manual != null)
                        Text(
                          'Order manual',
                          style: GoogleFonts.outfit(
                            fontSize: 11,
                            color: AppColors.inkSoft,
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      _rupiah.format(_angka(trip['amount'])),
                      style: GoogleFonts.outfit(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: AppColors.ink,
                      ),
                    ),
                    Text(
                      tunai
                          ? 'Komisi ${_rupiah.format(_angka(trip['commission']))}'
                          : 'Bersih ${_rupiah.format(_angka(trip['driver_share']))}',
                      style: GoogleFonts.outfit(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: tunai ? _oranye : AppColors.success,
                      ),
                    ),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                const SizedBox(width: 60),
                Expanded(
                  child: Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [_chipMetode(trip), _chipStatus(trip)],
                  ),
                ),
                if (trip['can_dispute'] == true &&
                    trip['method_dispute_status'] == null)
                  _tombolSanggah(trip),
              ],
            ),
            ..._infoSanggahanTrip(trip),
          ],
        ),
      ),
    );
  }

  Widget _chip(IconData icon, String text, Color warna, {bool isi = false}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: warna.withValues(alpha: isi ? 0.14 : 0.08),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 12, color: warna),
          const SizedBox(width: 4),
          Text(
            text,
            style: GoogleFonts.outfit(
              fontSize: 10.5,
              fontWeight: FontWeight.w700,
              color: warna,
            ),
          ),
        ],
      ),
    );
  }

  /// Warna disamakan dengan kartu order aktif: oranye = uang dipegang supir.
  Widget _chipMetode(dynamic trip) {
    return switch (trip['method']) {
      'CashDriver' => _chip(Icons.payments_rounded, 'Tunai ke Supir', _oranye),
      'CashCSO' => _chip(
        Icons.storefront_rounded,
        'Kasir CSO',
        AppColors.success,
      ),
      'QRIS' => _chip(Icons.qr_code_2_rounded, 'QRIS', AppColors.success),
      _ => _chip(
        Icons.receipt_long_rounded,
        '${trip['method'] ?? '-'}',
        AppColors.inkSoft,
      ),
    };
  }

  /// Merah sengaja tidak dipakai di sini: "menunggu cair" adalah keadaan
  /// normal, bukan masalah. Oranye hanya untuk komisi tunai yang belum disetor.
  Widget _chipStatus(dynamic trip) {
    final status = trip['payout_status'];
    if (_tunaiSupir(trip)) {
      return switch (status) {
        'Paid' => _chip(
          Icons.check_circle_rounded,
          'Komisi lunas',
          AppColors.success,
          isi: true,
        ),
        'Processing' => _chip(
          Icons.sync_rounded,
          'Setoran diproses',
          AppColors.skyBlue,
          isi: true,
        ),
        _ => _chip(
          Icons.error_outline_rounded,
          'Komisi belum disetor',
          _oranye,
          isi: true,
        ),
      };
    }
    return switch (status) {
      'Paid' => _chip(
        Icons.check_circle_rounded,
        'Sudah cair',
        AppColors.success,
        isi: true,
      ),
      'Processing' => _chip(
        Icons.sync_rounded,
        'Pencairan diproses',
        AppColors.skyBlue,
        isi: true,
      ),
      _ => _chip(
        Icons.schedule_rounded,
        'Menunggu cair',
        AppColors.inkSoft,
        isi: true,
      ),
    };
  }

  // === Sanggahan ==========================================================

  /// Tombol sanggahan metode pembayaran — ikon kecil saja; penjelasannya ada
  /// di tooltip dan dialog yang terbuka saat ditekan.
  ///
  /// Muncul untuk order yang tercatat "Tunai ke Supir" — sistem mengklaim supir
  /// memegang uangnya dan menagih komisinya. Kalau kenyataannya uang itu
  /// diterima kasir, inilah satu-satunya cara supir membantah.
  ///
  /// Kelayakannya TIDAK dihitung ulang di sini — dibaca dari flag `can_dispute`
  /// yang dikirim server, supaya aturan di aplikasi tidak bisa menyimpang dari
  /// yang ditegakkan endpoint.
  Widget _tombolSanggah(dynamic trip) {
    return Tooltip(
      message: 'Sanggah: saya tidak menerima uang tunai ini',
      child: Material(
        color: AppColors.danger.withValues(alpha: 0.08),
        shape: CircleBorder(
          side: BorderSide(color: AppColors.danger.withValues(alpha: 0.35)),
        ),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: () => _ajukanSanggahan(trip),
          child: const Padding(
            padding: EdgeInsets.all(6),
            child: Icon(
              Icons.report_gmailerrorred_rounded,
              size: 18,
              color: AppColors.danger,
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _infoSanggahanTrip(dynamic trip) {
    final status = trip['method_dispute_status'];

    if (status == 'Open') {
      return [
        _infoSanggahan(
          Icons.hourglass_top_rounded,
          AppColors.warning,
          'Sanggahan terkirim — menunggu keputusan admin.',
        ),
      ];
    }
    if (status == 'Upheld') {
      return [
        _infoSanggahan(
          Icons.verified_rounded,
          AppColors.success,
          'Sanggahan dikabulkan. Order ini dikoreksi menjadi Tunai ke Kasir.',
        ),
      ];
    }
    if (status == 'Rejected') {
      final catatan = trip['method_admin_note'];
      return [
        _infoSanggahan(
          Icons.cancel_rounded,
          AppColors.danger,
          'Sanggahan ditolak.${catatan != null ? ' Alasan: $catatan' : ''}',
        ),
      ];
    }
    return [];
  }

  Widget _infoSanggahan(IconData ikon, Color warna, String teks) {
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: warna.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(ikon, size: 15, color: warna),
            const SizedBox(width: 7),
            Expanded(
              child: Text(
                teks,
                style: GoogleFonts.outfit(fontSize: 11.5, color: warna),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _ajukanSanggahan(dynamic trip) async {
    final ctrl = TextEditingController();

    final kirim = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sanggah Pembayaran'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Order ini tercatat sebagai Tunai ke Supir, jadi komisinya ditagihkan '
              'kepada Anda. Jelaskan singkat apa yang sebenarnya terjadi — admin '
              'akan memeriksa dan memutuskan.',
              style: GoogleFonts.outfit(fontSize: 13, color: AppColors.inkSoft),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: ctrl,
              maxLines: 3,
              maxLength: 500,
              autofocus: true,
              decoration: const InputDecoration(
                hintText:
                    'Contoh: uang diterima kasir di loket, saya tidak pegang.',
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Kirim Sanggahan'),
          ),
        ],
      ),
    );

    if (kirim != true) return;

    if (ctrl.text.trim().length < 5) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('Alasan terlalu pendek.')));
      }
      return;
    }

    try {
      await _apiService.disputePaymentMethod(trip['id'], ctrl.text.trim());
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Sanggahan terkirim. Admin akan memeriksanya.'),
          backgroundColor: AppColors.success,
        ),
      );
      await _fetchHistory();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(apiErrorMessage(e)),
            backgroundColor: AppColors.danger,
          ),
        );
      }
    }
  }
}

class GrupTanggal {
  final DateTime? tanggal;
  final List<dynamic> trips;
  GrupTanggal(this.tanggal, this.trips);
}
