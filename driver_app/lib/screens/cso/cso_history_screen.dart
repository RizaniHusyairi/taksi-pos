import 'package:flutter/material.dart';
import '../../models/cso_transaction.dart';
import '../../services/api_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/format.dart';
import '../../utils/api_error.dart';
import '../../widgets/cso/cso_receipt_sheet.dart';
import '../../widgets/cso/cso_change_driver_sheet.dart';
import '../../widgets/cso/cso_ticket_sheet.dart';

enum _Periode { hariIni, kemarin, tujuhHari, pilih }

/// Tab "Riwayat Transaksi" CSO.
///
/// Ringkasan dipisah PER METODE karena maknanya berbeda bagi kasir: hanya
/// "Tunai di kasir" yang benar-benar ia pegang dan wajib disetor; tunai ke
/// supir tidak pernah lewat tangannya, QRIS langsung ke rekening. Order
/// batal tidak ikut dijumlahkan.
class CsoHistoryScreen extends StatefulWidget {
  const CsoHistoryScreen({super.key});

  @override
  State<CsoHistoryScreen> createState() => _CsoHistoryScreenState();
}

class _CsoHistoryScreenState extends State<CsoHistoryScreen> {
  final _api = ApiService();
  final _cari = TextEditingController();

  List<CsoTransaction> _txs = [];
  bool _loading = true;
  String? _error;

  _Periode _periode = _Periode.hariIni;
  DateTimeRange? _rentangPilihan;
  String? _filterMetode; // CashCSO | CashDriver | QRIS
  String? _filterStatus; // aktif | Completed | Cancelled

  /// Batas baris dari server (CsoApiController::getHistory).
  static const _batasServer = 500;

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
    _cari.addListener(() => setState(() {}));
    _load();
  }

  @override
  void dispose() {
    _cari.dispose();
    super.dispose();
  }

  String _two(int n) => n.toString().padLeft(2, '0');
  String _ymd(DateTime d) => '${d.year}-${_two(d.month)}-${_two(d.day)}';

  DateTimeRange get _rentang {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    return switch (_periode) {
      _Periode.hariIni => DateTimeRange(start: today, end: today),
      _Periode.kemarin => DateTimeRange(
        start: today.subtract(const Duration(days: 1)),
        end: today.subtract(const Duration(days: 1)),
      ),
      _Periode.tujuhHari => DateTimeRange(
        start: today.subtract(const Duration(days: 6)),
        end: today,
      ),
      _Periode.pilih =>
        _rentangPilihan ?? DateTimeRange(start: today, end: today),
    };
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r = _rentang;
      final res = await _api.getCsoHistory(
        startDate: _ymd(r.start),
        endDate: _ymd(r.end),
      );
      final list = (res.data as List)
          .map(
            (e) => CsoTransaction.fromJson((e as Map).cast<String, dynamic>()),
          )
          .toList();
      if (mounted) setState(() => _txs = list);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _gantiPeriode(_Periode p) {
    if (p == _periode && p != _Periode.pilih) return;
    setState(() => _periode = p);
    _load();
  }

  Future<void> _pilihTanggal() async {
    final now = DateTime.now();
    final hasil = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2024, 1, 1),
      lastDate: now,
      initialDateRange: _rentangPilihan ?? _rentang,
      helpText: 'Pilih rentang tanggal',
      cancelText: 'Batal',
      confirmText: 'Pilih',
      saveText: 'Terapkan',
    );
    if (hasil == null) return;
    _rentangPilihan = hasil;
    _gantiPeriode(_Periode.pilih);
  }

  // === Filter & ringkasan ==================================================

  List<CsoTransaction> get _tampil {
    final q = _cari.text.trim().toLowerCase();
    final qDigit = q.replaceAll(RegExp(r'\D'), '');
    return _txs.where((t) {
      if (_filterMetode != null && t.method != _filterMetode) return false;
      if (_filterStatus == 'aktif' &&
          t.status != 'Assigned' &&
          t.status != 'OnTrip') {
        return false;
      }
      if (_filterStatus != null &&
          _filterStatus != 'aktif' &&
          t.status != _filterStatus) {
        return false;
      }
      if (q.isEmpty) return true;
      final phone = t.passengerPhone.replaceAll(RegExp(r'\D'), '');
      return t.driverName.toLowerCase().contains(q) ||
          t.zoneName.toLowerCase().contains(q) ||
          (qDigit.isNotEmpty &&
              (phone.contains(qDigit) ||
                  '${t.bookingId}' == qDigit ||
                  '${t.id}' == qDigit));
    }).toList();
  }

  bool get _adaFilter =>
      _filterMetode != null ||
      _filterStatus != null ||
      _cari.text.trim().isNotEmpty;

  String _labelTanggal(DateTime d) {
    final now = DateTime.now();
    final selisih = DateTime(
      now.year,
      now.month,
      now.day,
    ).difference(DateTime(d.year, d.month, d.day)).inDays;
    if (selisih == 0) return 'Hari ini';
    if (selisih == 1) return 'Kemarin';
    return '${_hari[d.weekday - 1]}, ${d.day} ${_bulan[d.month - 1]}${d.year == now.year ? '' : ' ${d.year}'}';
  }

  String _labelPeriode() {
    final r = _rentang;
    String pendek(DateTime d) => '${d.day} ${_bulan[d.month - 1]}';
    return switch (_periode) {
      _Periode.hariIni => 'Hari ini',
      _Periode.kemarin => 'Kemarin',
      _Periode.tujuhHari => '7 hari terakhir',
      _Periode.pilih =>
        r.start == r.end
            ? pendek(r.start)
            : '${pendek(r.start)} – ${pendek(r.end)}',
    };
  }

  // === Build ===============================================================

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: const Duration(milliseconds: 420),
      curve: Curves.easeOutCubic,
      builder: (context, t, child) => Opacity(
        opacity: t.clamp(0, 1),
        child: Transform.translate(
          offset: Offset(0, (1 - t) * 22),
          child: child,
        ),
      ),
      child: _body(),
    );
  }

  Widget _body() {
    if (_loading && _txs.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _txs.isEmpty) {
      return _center(_error!, isError: true, retry: _load);
    }

    final tampil = _tampil;

    // Daftar datar: [header tanggal, tx, tx, header, ...]
    final entri = <Object>[];
    String? kunci;
    for (final t in tampil) {
      final d = t.createdAt?.toLocal();
      final k = d == null ? '-' : _ymd(d);
      if (k != kunci) {
        kunci = k;
        entri.add(
          _Grup(
            d,
            tampil.where((x) {
              final dx = x.createdAt?.toLocal();
              return (dx == null ? '-' : _ymd(dx)) == k;
            }).toList(),
          ),
        );
      }
      entri.add(t);
    }

    final atas = <Widget>[
      _ringkasan(),
      const SizedBox(height: 12),
      _barPeriode(),
      const SizedBox(height: 10),
      _barCari(),
      const SizedBox(height: 8),
      _barFilter(),
      if (_txs.length >= _batasServer)
        const Padding(
          padding: EdgeInsets.only(top: 8),
          child: Text(
            'Menampilkan $_batasServer transaksi terbaru. Persempit tanggal untuk melihat sisanya.',
            style: TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
          ),
        ),
      if (_loading)
        const Padding(
          padding: EdgeInsets.only(top: 8),
          child: LinearProgressIndicator(minHeight: 2),
        ),
      if (tampil.isEmpty) _emptyState(),
    ];

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        itemCount: atas.length + entri.length,
        itemBuilder: (context, i) {
          if (i < atas.length) return atas[i];
          final e = entri[i - atas.length];
          return e is _Grup ? _headerGrup(e) : _kartu(e as CsoTransaction);
        },
      ),
    );
  }

  Widget _ringkasan() {
    num jumlah(String m) => _txs
        .where((t) => t.method == m && t.status != 'Cancelled')
        .fold<num>(0, (a, t) => a + t.amount);
    final kasir = jumlah('CashCSO');
    final supir = jumlah('CashDriver');
    final qris = jumlah('QRIS');
    final batal = _txs.where((t) => t.status == 'Cancelled').length;
    final order = _txs.length - batal;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.circular(22),
        boxShadow: const [
          BoxShadow(
            color: Color(0x330B4DA2),
            blurRadius: 16,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.payments_rounded, color: Colors.white, size: 18),
              const SizedBox(width: 6),
              const Text(
                'Tunai di kasir',
                style: TextStyle(color: Colors.white70, fontSize: 12.5),
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
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              formatRupiah(kasir),
              style: const TextStyle(
                color: Colors.white,
                fontSize: 28,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
          const Text(
            'Uang yang Anda pegang — disetor lewat menu Setoran',
            style: TextStyle(color: Colors.white70, fontSize: 11.5),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              _statMini('Tunai ke supir', formatRupiah(supir)),
              _pemisah(),
              _statMini('QRIS', formatRupiah(qris)),
              _pemisah(),
              _statMini('Order', '$order${batal > 0 ? ' · $batal batal' : ''}'),
            ],
          ),
        ],
      ),
    );
  }

  Widget _statMini(String label, String nilai) => Expanded(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(
            nilai,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 13.5,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
        Text(
          label,
          style: const TextStyle(color: Colors.white70, fontSize: 10.5),
        ),
      ],
    ),
  );

  Widget _pemisah() => Container(
    width: 1,
    height: 30,
    margin: const EdgeInsets.symmetric(horizontal: 10),
    color: Colors.white.withValues(alpha: 0.25),
  );

  Widget _chip(
    String label,
    bool aktif,
    VoidCallback onTap, {
    IconData? icon,
    Color? warna,
  }) {
    final c = warna ?? AppColors.skyBlue;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        selected: aktif,
        showCheckmark: false,
        avatar: icon == null
            ? null
            : Icon(
                icon,
                size: 15,
                color: aktif ? Colors.white : AppColors.inkSoft,
              ),
        label: Text(label),
        labelStyle: TextStyle(
          fontSize: 12.5,
          fontWeight: FontWeight.w600,
          color: aktif ? Colors.white : AppColors.inkSoft,
        ),
        selectedColor: c,
        backgroundColor: Colors.white,
        side: BorderSide(
          color: aktif ? c : AppColors.inkFaint.withValues(alpha: 0.4),
        ),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        visualDensity: VisualDensity.compact,
        onSelected: (_) => onTap(),
      ),
    );
  }

  Widget _barPeriode() {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          _chip(
            'Hari ini',
            _periode == _Periode.hariIni,
            () => _gantiPeriode(_Periode.hariIni),
          ),
          _chip(
            'Kemarin',
            _periode == _Periode.kemarin,
            () => _gantiPeriode(_Periode.kemarin),
          ),
          _chip(
            '7 Hari',
            _periode == _Periode.tujuhHari,
            () => _gantiPeriode(_Periode.tujuhHari),
          ),
          _chip(
            _periode == _Periode.pilih ? _labelPeriode() : 'Pilih tanggal',
            _periode == _Periode.pilih,
            _pilihTanggal,
            icon: Icons.calendar_month_rounded,
          ),
        ],
      ),
    );
  }

  Widget _barCari() {
    return TextField(
      controller: _cari,
      textInputAction: TextInputAction.search,
      decoration: InputDecoration(
        hintText: 'Cari no. HP penumpang, supir, tujuan, atau #order',
        hintStyle: const TextStyle(fontSize: 13, color: AppColors.inkFaint),
        prefixIcon: const Icon(Icons.search_rounded, color: AppColors.inkSoft),
        suffixIcon: _cari.text.isEmpty
            ? null
            : IconButton(
                icon: const Icon(Icons.close_rounded, size: 18),
                onPressed: () => _cari.clear(),
              ),
        filled: true,
        fillColor: Colors.white,
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(vertical: 12),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(
            color: AppColors.inkFaint.withValues(alpha: 0.3),
          ),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(
            color: AppColors.inkFaint.withValues(alpha: 0.3),
          ),
        ),
      ),
    );
  }

  Widget _barFilter() {
    void metode(String m) =>
        setState(() => _filterMetode = _filterMetode == m ? null : m);
    void status(String s) =>
        setState(() => _filterStatus = _filterStatus == s ? null : s);
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          _chip(
            'Tunai kasir',
            _filterMetode == 'CashCSO',
            () => metode('CashCSO'),
            warna: AppColors.success,
          ),
          _chip(
            'Tunai supir',
            _filterMetode == 'CashDriver',
            () => metode('CashDriver'),
            warna: const Color(0xFFF57C00),
          ),
          _chip(
            'QRIS',
            _filterMetode == 'QRIS',
            () => metode('QRIS'),
            warna: AppColors.deepBlue,
          ),
          Container(
            width: 1,
            height: 22,
            margin: const EdgeInsets.only(right: 8),
            color: AppColors.inkFaint.withValues(alpha: 0.4),
          ),
          _chip(
            'Berjalan',
            _filterStatus == 'aktif',
            () => status('aktif'),
            warna: AppColors.warning,
          ),
          _chip(
            'Selesai',
            _filterStatus == 'Completed',
            () => status('Completed'),
            warna: AppColors.success,
          ),
          _chip(
            'Batal',
            _filterStatus == 'Cancelled',
            () => status('Cancelled'),
            warna: AppColors.danger,
          ),
        ],
      ),
    );
  }

  Widget _headerGrup(_Grup g) {
    final subtotal = g.txs
        .where((t) => t.status != 'Cancelled')
        .fold<num>(0, (a, t) => a + t.amount);
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 16, 4, 8),
      child: Row(
        children: [
          Text(
            g.tanggal == null ? 'Tanpa tanggal' : _labelTanggal(g.tanggal!),
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: AppColors.ink,
            ),
          ),
          const Spacer(),
          Text(
            '${g.txs.length} order · ${formatRupiah(subtotal)}',
            style: const TextStyle(fontSize: 12, color: AppColors.inkSoft),
          ),
        ],
      ),
    );
  }

  (String, Color) _metode(String m) => switch (m) {
    'CashCSO' => ('Tunai kasir', AppColors.success),
    'CashDriver' => ('Tunai supir', const Color(0xFFF57C00)),
    _ => ('QRIS', AppColors.deepBlue),
  };

  Widget _kartu(CsoTransaction tx) {
    final s = _statusStyle(tx.status);
    final batal = tx.status == 'Cancelled';
    final t = tx.createdAt?.toLocal();
    final jam = t == null ? '--:--' : '${_two(t.hour)}:${_two(t.minute)}';
    final (labelMetode, warnaMetode) = _metode(tx.method);
    final aksi = _aksi(tx);

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(16),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0F0B4DA2),
            blurRadius: 10,
            offset: Offset(0, 4),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: () => CsoReceiptSheet.show(context, tx.receiptData),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
            child: Column(
              children: [
                Row(
                  children: [
                    Container(
                      width: 38,
                      height: 38,
                      decoration: BoxDecoration(
                        color: s.accent.withValues(alpha: 0.14),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Icon(s.icon, color: s.accent, size: 19),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Bandara → ${tx.zoneName}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: AppColors.ink,
                              fontWeight: FontWeight.w800,
                              fontSize: 13.5,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            '$jam · #${tx.bookingId ?? tx.id} · ${tx.driverName}'
                            '${tx.driverLine != null ? ' #L${tx.driverLine}' : ''}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: AppColors.inkSoft,
                              fontSize: 11.5,
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
                          formatRupiah(tx.amount),
                          style: TextStyle(
                            color: batal
                                ? AppColors.inkFaint
                                : AppColors.deepBlue,
                            fontWeight: FontWeight.w800,
                            fontSize: 14,
                            decoration: batal
                                ? TextDecoration.lineThrough
                                : null,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 7,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: warnaMetode.withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(
                            labelMetode,
                            style: TextStyle(
                              color: warnaMetode,
                              fontSize: 10,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
                if (tx.status != 'Completed' || aksi.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      const SizedBox(width: 48),
                      if (tx.status != 'Completed')
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 3,
                          ),
                          decoration: BoxDecoration(
                            color: s.bg,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(
                            s.label,
                            style: TextStyle(
                              color: s.fg,
                              fontSize: 10.5,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                      const Spacer(),
                      ...aksi,
                    ],
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Aksi kecil di kartu. "Struk" tidak perlu tombol: mengetuk kartu sudah
  /// membuka struk.
  List<Widget> _aksi(CsoTransaction tx) {
    final w = <Widget>[];
    if (tx.hasProof) {
      w.add(
        _tombolAksi(
          'Bukti',
          Icons.image_rounded,
          AppColors.skyBlue,
          () => _viewProof(tx.proofUrl!),
        ),
      );
    }
    if (tx.canChangeDriver) {
      // Jeda sama dengan lembar karcis (CsoTicketSheet.lateAfter) supaya
      // aturannya tidak berbeda di dua tempat.
      final sisa = CsoTicketSheet.lateAfter - tx.sinceBooking;
      final tunggu = sisa > Duration.zero;
      if (w.isNotEmpty) w.add(const SizedBox(width: 6));
      w.add(
        _tombolAksi(
          tunggu ? 'Tunggu ${sisa.inSeconds + 1} dtk' : 'Ganti Supir',
          Icons.swap_horiz_rounded,
          AppColors.danger,
          tunggu
              ? null
              : () async {
                  final changed = await CsoChangeDriverSheet.show(
                    context,
                    tx.bookingId!,
                  );
                  if (changed == true && mounted) _load();
                },
        ),
      );
    }
    return w;
  }

  Widget _tombolAksi(
    String label,
    IconData icon,
    Color color,
    VoidCallback? onTap,
  ) {
    final c = onTap == null ? AppColors.inkFaint : color;
    return Material(
      color: c.withValues(alpha: 0.1),
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 14, color: c),
              const SizedBox(width: 4),
              Text(
                label,
                style: TextStyle(
                  color: c,
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _viewProof(String url) {
    showDialog(
      context: context,
      builder: (ctx) => GestureDetector(
        onTap: () => Navigator.pop(ctx),
        child: Container(
          color: Colors.black87,
          alignment: Alignment.center,
          child: InteractiveViewer(
            child: Image.network(
              url,
              errorBuilder: (_, _, _) => const Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'Gagal memuat bukti.',
                  style: TextStyle(color: Colors.white),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _emptyState() {
    return Padding(
      padding: const EdgeInsets.only(top: 40),
      child: Column(
        children: [
          Container(
            width: 72,
            height: 72,
            decoration: const BoxDecoration(
              color: AppColors.paleBlue,
              shape: BoxShape.circle,
            ),
            child: const Icon(
              Icons.receipt_long_rounded,
              size: 34,
              color: AppColors.skyBlue,
            ),
          ),
          const SizedBox(height: 14),
          Text(
            _adaFilter ? 'Tidak ada yang cocok' : 'Belum ada transaksi',
            style: const TextStyle(
              color: AppColors.ink,
              fontSize: 16,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            _adaFilter
                ? 'Ubah kata kunci atau filter.'
                : 'Coba pilih periode lain di atas.',
            style: const TextStyle(color: AppColors.inkSoft, fontSize: 13),
          ),
        ],
      ),
    );
  }

  Widget _center(String text, {bool isError = false, VoidCallback? retry}) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            text,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: isError ? AppColors.danger : AppColors.inkSoft,
              fontWeight: FontWeight.w600,
            ),
          ),
          if (retry != null) ...[
            const SizedBox(height: 10),
            TextButton(onPressed: retry, child: const Text('Coba lagi')),
          ],
        ],
      ),
    );
  }

  _StatusStyle _statusStyle(String status) {
    switch (status) {
      case 'Assigned':
        return const _StatusStyle(
          'Menunggu Jemput',
          Color(0xFFB45309),
          Color(0x1FF5A623),
          AppColors.warning,
          Icons.directions_walk_rounded,
        );
      case 'OnTrip':
        return const _StatusStyle(
          'Dalam Perjalanan',
          Color(0xFF1D4ED8),
          Color(0x1F1E88E5),
          AppColors.skyBlue,
          Icons.local_taxi_rounded,
        );
      case 'Completed':
        return const _StatusStyle(
          'Selesai',
          Color(0xFF0F6E56),
          Color(0x1F18B27E),
          AppColors.success,
          Icons.check_circle_rounded,
        );
      case 'Cancelled':
        return const _StatusStyle(
          'Dibatalkan',
          Color(0xFFB91C1C),
          Color(0x1FEF5350),
          AppColors.danger,
          Icons.cancel_rounded,
        );
      default:
        return _StatusStyle(
          status,
          AppColors.inkSoft,
          AppColors.inkFaint.withValues(alpha: 0.15),
          AppColors.inkFaint,
          Icons.receipt_long_rounded,
        );
    }
  }
}

class _Grup {
  final DateTime? tanggal;
  final List<CsoTransaction> txs;
  _Grup(this.tanggal, this.txs);
}

class _StatusStyle {
  final String label;
  final Color fg;
  final Color bg;
  final Color accent;
  final IconData icon;
  const _StatusStyle(this.label, this.fg, this.bg, this.accent, this.icon);
}
