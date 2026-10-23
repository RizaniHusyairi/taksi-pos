import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../models/cso_deposit.dart';
import '../../services/api_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/api_error.dart';
import '../../utils/format.dart';
import '../../widgets/app_card.dart';
import '../../widgets/fade_in.dart';
import '../../widgets/gradient_button.dart';
import '../live_camera_screen.dart';

/// Setoran tunai CSO ke admin.
///
/// Tanggal diurutkan dari yang TERLAMA dan diberi penanda umur: tunai yang
/// lama tertahan di tangan kasir adalah risiko terbesar (hilang, terpakai,
/// lupa disetor), jadi itulah yang harus paling menonjol.
class CsoDepositScreen extends StatefulWidget {
  const CsoDepositScreen({super.key});

  @override
  State<CsoDepositScreen> createState() => _CsoDepositScreenState();
}

class _CsoDepositScreenState extends State<CsoDepositScreen> {
  final _api = ApiService();

  List<OutstandingDay> _days = [];
  List<CsoDeposit> _history = [];
  final Set<String> _selected = {};

  // Rincian transaksi per tanggal: dimuat saat kartu dibuka, lalu disimpan.
  final Set<String> _terbuka = {};
  final Map<String, List<OutstandingTx>> _rincian = {};
  final Set<String> _memuatRincian = {};

  num _grandTotal = 0;
  num _pendingTotal = 0;
  int _pendingCount = 0;
  bool _loading = true;
  bool _submitting = false;
  String? _error;

  String? _proofPath;

  /// Batas umur tunai (hari): oranye mulai [_umurWaspada], merah mulai [_umurBahaya].
  static const _umurWaspada = 2;
  static const _umurBahaya = 7;

  static const _namaBulan = [
    'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
    'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final hasil = await Future.wait([
        _api.getCsoDepositOutstanding(),
        _api.getCsoDeposits(),
      ]);

      final out = hasil[0].data as Map<String, dynamic>;
      final days = (out['days'] as List? ?? [])
          .map((e) => OutstandingDay.fromJson(Map<String, dynamic>.from(e)))
          .toList()
        // Terlama di atas: yang paling berisiko tampil pertama.
        ..sort((a, b) => a.date.compareTo(b.date));

      final rawHist = hasil[1].data;
      final listHist = rawHist is Map ? (rawHist['data'] as List? ?? []) : (rawHist as List? ?? []);
      final history = listHist
          .map((e) => CsoDeposit.fromJson(Map<String, dynamic>.from(e)))
          .toList();

      num angka(dynamic v) => v is num ? v : num.tryParse('$v') ?? 0;

      if (!mounted) return;
      setState(() {
        _days = days;
        _grandTotal = angka(out['grand_total']);
        _pendingTotal = angka(out['pending_total']);
        _pendingCount = angka(out['pending_count']).toInt();
        _history = history;
        _selected.removeWhere((d) => !days.any((h) => h.date == d));
        _rincian.clear();
        _terbuka.removeWhere((d) => !days.any((h) => h.date == d));
        _loading = false;
      });
      for (final d in _terbuka) {
        _muatRincian(d);
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = apiErrorMessage(e);
          _loading = false;
        });
      }
    }
  }

  Future<void> _muatRincian(String date) async {
    if (_rincian.containsKey(date) || _memuatRincian.contains(date)) return;
    setState(() => _memuatRincian.add(date));
    try {
      final res = await _api.getCsoDepositOutstandingDay(date);
      final list = (res.data['transactions'] as List? ?? [])
          .map((e) => OutstandingTx.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      if (mounted) setState(() => _rincian[date] = list);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(apiErrorMessage(e))),
        );
      }
    } finally {
      if (mounted) setState(() => _memuatRincian.remove(date));
    }
  }

  void _toggleBuka(String date) {
    setState(() {
      _terbuka.contains(date) ? _terbuka.remove(date) : _terbuka.add(date);
    });
    if (_terbuka.contains(date)) _muatRincian(date);
  }

  num get _totalTerpilih => _days
      .where((d) => _selected.contains(d.date))
      .fold<num>(0, (a, d) => a + d.total);

  int get _jumlahTransaksiTerpilih => _days
      .where((d) => _selected.contains(d.date))
      .fold<int>(0, (a, d) => a + d.count);

  bool get _semuaTerpilih =>
      _days.isNotEmpty && _selected.length == _days.length;

  void _pilihSemua(bool pilih) {
    setState(() {
      _selected.clear();
      if (pilih) _selected.addAll(_days.map((d) => d.date));
    });
  }

  // === Tanggal & umur =====================================================

  String _tanggalPanjang(String ymd) {
    final d = DateTime.tryParse(ymd);
    if (d == null) return ymd;
    return '${d.day} ${_namaBulan[d.month - 1]} ${d.year}';
  }

  String _tanggalJam(DateTime d) =>
      '${d.day} ${_namaBulan[d.month - 1]} ${d.year}, '
      '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

  static String _jam(DateTime d) =>
      '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

  /// Umur tunai dalam hari (0 = hari ini).
  int _umur(String ymd) {
    final d = DateTime.tryParse(ymd);
    if (d == null) return 0;
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day)
        .difference(DateTime(d.year, d.month, d.day))
        .inDays;
  }

  Color _warnaUmur(int umur) => umur >= _umurBahaya
      ? AppColors.danger
      : umur >= _umurWaspada
          ? const Color(0xFFF57C00)
          : AppColors.success;

  String _labelUmur(int umur) =>
      umur <= 0 ? 'Hari ini' : (umur == 1 ? 'Kemarin' : '$umur hari');

  // === Setor ==============================================================

  Future<void> _setorSemua() async {
    _pilihSemua(true);
    await _setor();
  }

  Future<void> _setor() async {
    if (_selected.isEmpty || _submitting) return;

    final setuju = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => StatefulBuilder(
        builder: (ctx, setSheet) => _lembarKonfirmasi(ctx, setSheet),
      ),
    );
    if (setuju != true) return;

    setState(() => _submitting = true);
    try {
      await _api.createCsoDeposit(
        _selected.toList()..sort(),
        proofPath: _proofPath,
      );
      if (!mounted) return;
      _selected.clear();
      _proofPath = null;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Setoran diajukan. Menunggu verifikasi admin.'),
          backgroundColor: AppColors.success,
        ),
      );
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(apiErrorMessage(e)),
            backgroundColor: AppColors.danger,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  /// Foto bukti serah terima. Kamera langsung didahulukan karena inilah cara
  /// pakainya di lapangan: memotret uang/kuitansi saat menyerahkannya.
  Future<void> _ambilFoto(ImageSource sumber, StateSetter setSheet) async {
    String? path;
    if (sumber == ImageSource.camera) {
      path = await LiveCameraScreen.buka(
        context,
        judul: 'Foto Bukti Setoran',
        petunjuk: 'Potret uang / kuitansi saat diserahkan ke admin',
      );
    } else {
      try {
        // Dikecilkan sejak dari HP: hemat kuota, dan server toh akan
        // memampatkannya lagi saat membakar keterangan setoran.
        final file = await ImagePicker().pickImage(
          source: sumber,
          imageQuality: 75,
          maxWidth: 1600,
        );
        path = file?.path;
      } catch (_) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Gagal mengambil gambar.')),
          );
        }
      }
    }
    if (path != null && mounted) {
      setState(() => _proofPath = path);
      setSheet(() {});
    }
  }

  Widget _lembarKonfirmasi(BuildContext ctx, StateSetter setSheet) {
    final terpilih = _days.where((d) => _selected.contains(d.date)).toList();
    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      padding: const EdgeInsets.fromLTRB(20, 10, 20, 20),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 44,
                  height: 5,
                  margin: const EdgeInsets.only(bottom: 16),
                  decoration: BoxDecoration(
                    color: AppColors.inkFaint.withValues(alpha: 0.4),
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
              ),
              const Text(
                'Konfirmasi Setoran',
                style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: AppColors.ink),
              ),
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  gradient: AppColors.skyGradient,
                  borderRadius: BorderRadius.circular(18),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Diserahkan ke admin',
                        style: TextStyle(color: Colors.white70, fontSize: 12.5)),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text(
                        formatRupiah(_totalTerpilih),
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    Text(
                      '${terpilih.length} tanggal · $_jumlahTransaksiTerpilih transaksi tunai',
                      style: const TextStyle(color: Colors.white, fontSize: 12.5),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              ConstrainedBox(
                constraints: const BoxConstraints(maxHeight: 150),
                child: ListView(
                  shrinkWrap: true,
                  children: terpilih
                      .map((d) => Padding(
                            padding: const EdgeInsets.symmetric(vertical: 4),
                            child: Row(
                              children: [
                                const Icon(Icons.event_rounded, size: 16, color: AppColors.inkFaint),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(_tanggalPanjang(d.date),
                                      style: const TextStyle(fontSize: 13, color: AppColors.ink)),
                                ),
                                Text(formatRupiah(d.total),
                                    style: const TextStyle(
                                        fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.ink)),
                              ],
                            ),
                          ))
                      .toList(),
                ),
              ),
              const Divider(height: 24),
              const Text(
                'Foto bukti serah terima (disarankan)',
                style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: AppColors.ink),
              ),
              const SizedBox(height: 8),
              if (_proofPath == null)
                Row(
                  children: [
                    Expanded(
                      child: _tombolFoto(Icons.photo_camera_rounded, 'Kamera',
                          () => _ambilFoto(ImageSource.camera, setSheet)),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _tombolFoto(Icons.photo_library_rounded, 'Galeri',
                          () => _ambilFoto(ImageSource.gallery, setSheet)),
                    ),
                  ],
                )
              else
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppColors.success.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Row(
                    children: [
                      ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: Image.file(File(_proofPath!), width: 56, height: 56, fit: BoxFit.cover),
                      ),
                      const SizedBox(width: 12),
                      const Expanded(
                        child: Text(
                          'Foto terlampir. Nomor setoran & nominal akan distempel otomatis.',
                          style: TextStyle(fontSize: 12, color: AppColors.inkSoft),
                        ),
                      ),
                      IconButton(
                        tooltip: 'Hapus foto',
                        onPressed: () {
                          setState(() => _proofPath = null);
                          setSheet(() {});
                        },
                        icon: const Icon(Icons.close_rounded, color: AppColors.danger),
                      ),
                    ],
                  ),
                ),
              const SizedBox(height: 18),
              GradientButton(
                label: 'SETOR ${formatRupiah(_totalTerpilih)}',
                icon: Icons.upload_rounded,
                onPressed: () => Navigator.pop(ctx, true),
              ),
              const SizedBox(height: 6),
              const Text(
                'Serahkan uangnya ke admin; admin akan memverifikasi setoran ini.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 11.5, color: AppColors.inkFaint),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _tombolFoto(IconData icon, String label, VoidCallback onTap) {
    return Material(
      color: AppColors.paleBlue,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 14),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, color: AppColors.skyBlue, size: 20),
              const SizedBox(width: 8),
              Text(label,
                  style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.deepBlue)),
            ],
          ),
        ),
      ),
    );
  }

  // === Build ==============================================================

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.cloud_off_rounded, color: AppColors.inkFaint, size: 34),
              const SizedBox(height: 10),
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 14),
              GradientButton(label: 'Coba Lagi', onPressed: _load),
            ],
          ),
        ),
      );
    }

    return Column(
      children: [
        Expanded(
          child: RefreshIndicator(
            color: AppColors.skyBlue,
            onRefresh: _load,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
              children: [
                FadeInUp(child: _ringkasan()),
                const SizedBox(height: 18),
                _judul(
                  'Belum Disetor',
                  Icons.savings_rounded,
                  aksi: _days.isEmpty
                      ? null
                      : TextButton.icon(
                          onPressed: () => _pilihSemua(!_semuaTerpilih),
                          icon: Icon(
                            _semuaTerpilih
                                ? Icons.remove_done_rounded
                                : Icons.done_all_rounded,
                            size: 18,
                          ),
                          label: Text(_semuaTerpilih ? 'Batal pilih' : 'Pilih semua'),
                        ),
                ),
                const SizedBox(height: 6),
                if (_days.isEmpty)
                  _kosong('Tidak ada tunai yang perlu disetor. Semua sudah beres.')
                else
                  ..._days.map(_kartuTanggal),
                const SizedBox(height: 24),
                _judul('Riwayat Setoran', Icons.receipt_long_rounded),
                const SizedBox(height: 10),
                if (_history.isEmpty)
                  _kosong('Belum ada setoran yang pernah diajukan.')
                else
                  ..._history.map(_kartuRiwayat),
              ],
            ),
          ),
        ),
        if (_days.isNotEmpty) _barSetor(),
      ],
    );
  }

  Widget _ringkasan() {
    final tertua = _days.isEmpty ? null : _days.first;
    final umurTertua = tertua == null ? 0 : _umur(tertua.date);
    final jumlahTransaksi = _days.fold<int>(0, (a, d) => a + d.count);

    return AppCard(
      gradient: AppColors.skyGradient,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.account_balance_wallet_rounded, color: Colors.white, size: 20),
              SizedBox(width: 8),
              Text('Tunai di tangan Anda',
                  style: TextStyle(color: Colors.white70, fontSize: 13)),
            ],
          ),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              formatRupiah(_grandTotal),
              style: const TextStyle(
                color: Colors.white,
                fontSize: 30,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
          Text(
            _days.isEmpty
                ? 'Semua setoran sudah diserahkan'
                : '${_days.length} tanggal · $jumlahTransaksi transaksi belum disetor',
            style: const TextStyle(color: Colors.white, fontSize: 12.5),
          ),
          if (_pendingCount > 0) ...[
            const SizedBox(height: 10),
            _chipPutih(
              Icons.hourglass_top_rounded,
              '${formatRupiah(_pendingTotal)} menunggu verifikasi admin'
              '${_pendingCount > 1 ? ' ($_pendingCount setoran)' : ''}',
            ),
          ],
          if (tertua != null && umurTertua >= _umurWaspada) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                children: [
                  Icon(Icons.warning_amber_rounded, color: _warnaUmur(umurTertua), size: 18),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Tunai tertua ${_tanggalPanjang(tertua.date)} sudah $umurTertua hari belum disetor',
                      style: TextStyle(
                        color: _warnaUmur(umurTertua),
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
          if (_days.isNotEmpty) ...[
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _submitting ? null : _setorSemua,
                style: FilledButton.styleFrom(
                  backgroundColor: Colors.white,
                  foregroundColor: AppColors.deepBlue,
                  padding: const EdgeInsets.symmetric(vertical: 13),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  textStyle: const TextStyle(fontWeight: FontWeight.w800),
                ),
                icon: const Icon(Icons.upload_rounded, size: 20),
                label: Text('Setor semua ${formatRupiah(_grandTotal)}'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _chipPutih(IconData icon, String teks) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.18),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: Colors.white, size: 15),
          const SizedBox(width: 6),
          Flexible(
            child: Text(teks,
                style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }

  Widget _judul(String teks, IconData ikon, {Widget? aksi}) {
    return Row(
      children: [
        Icon(ikon, color: AppColors.inkSoft, size: 20),
        const SizedBox(width: 8),
        Text(
          teks,
          style: const TextStyle(
            fontSize: 15.5,
            fontWeight: FontWeight.w700,
            color: AppColors.ink,
          ),
        ),
        const Spacer(),
        ?aksi,
      ],
    );
  }

  Widget _kosong(String pesan) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 18),
      child: Center(
        child: Text(
          pesan,
          textAlign: TextAlign.center,
          style: const TextStyle(color: AppColors.inkFaint, fontSize: 13),
        ),
      ),
    );
  }

  Widget _kartuTanggal(OutstandingDay hari) {
    final dipilih = _selected.contains(hari.date);
    final terbuka = _terbuka.contains(hari.date);
    final umur = _umur(hari.date);
    final warna = _warnaUmur(umur);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppCard(
        padding: EdgeInsets.zero,
        border: dipilih ? Border.all(color: AppColors.skyBlue, width: 1.6) : null,
        child: Column(
          children: [
            InkWell(
              borderRadius: BorderRadius.circular(22),
              onTap: () => setState(() {
                dipilih ? _selected.remove(hari.date) : _selected.add(hari.date);
              }),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(6, 10, 4, 10),
                child: Row(
                  children: [
                    Checkbox(
                      value: dipilih,
                      onChanged: (v) => setState(() {
                        v == true ? _selected.add(hari.date) : _selected.remove(hari.date);
                      }),
                      activeColor: AppColors.skyBlue,
                    ),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _tanggalPanjang(hari.date),
                            style: const TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.w700,
                              color: AppColors.ink,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                                decoration: BoxDecoration(
                                  color: warna.withValues(alpha: 0.12),
                                  borderRadius: BorderRadius.circular(20),
                                ),
                                child: Text(
                                  _labelUmur(umur),
                                  style: TextStyle(
                                    fontSize: 10.5,
                                    fontWeight: FontWeight.w800,
                                    color: warna,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 6),
                              Flexible(
                                child: Text(
                                  '${hari.count} transaksi',
                                  style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    Text(
                      formatRupiah(hari.total),
                      style: const TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w800,
                        color: AppColors.deepBlue,
                      ),
                    ),
                    IconButton(
                      tooltip: terbuka ? 'Tutup rincian' : 'Lihat rincian',
                      onPressed: () => _toggleBuka(hari.date),
                      icon: AnimatedRotation(
                        turns: terbuka ? 0.5 : 0,
                        duration: const Duration(milliseconds: 200),
                        child: const Icon(Icons.expand_more_rounded, color: AppColors.inkSoft),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            AnimatedSize(
              duration: const Duration(milliseconds: 220),
              curve: Curves.easeOut,
              alignment: Alignment.topCenter,
              child: terbuka ? _rincianHari(hari.date) : const SizedBox(width: double.infinity),
            ),
          ],
        ),
      ),
    );
  }

  Widget _rincianHari(String date) {
    final list = _rincian[date];
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.fromLTRB(14, 0, 14, 12),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: AppColors.background,
        borderRadius: BorderRadius.circular(14),
      ),
      child: list == null
          ? const Padding(
              padding: EdgeInsets.all(12),
              child: Center(
                child: SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ),
            )
          : Column(
              children: [
                for (var i = 0; i < list.length; i++) ...[
                  if (i > 0) Divider(height: 1, color: AppColors.inkFaint.withValues(alpha: 0.25)),
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 44,
                          child: Text(
                            list[i].createdAt == null ? '--:--' : _jam(list[i].createdAt!),
                            style: const TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w700,
                              color: AppColors.ink,
                            ),
                          ),
                        ),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                list[i].destination ?? 'Tujuan tidak tercatat',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontSize: 12.5, color: AppColors.ink),
                              ),
                              Text(
                                '${list[i].driver ?? '-'} · #${list[i].bookingId ?? list[i].id}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontSize: 11, color: AppColors.inkSoft),
                              ),
                            ],
                          ),
                        ),
                        Text(
                          formatRupiah(list[i].amount),
                          style: const TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w700,
                            color: AppColors.ink,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
    );
  }

  Widget _kartuRiwayat(CsoDeposit d) {
    final (Color warna, String label, IconData ikon) = d.isApproved
        ? (AppColors.success, 'Diterima', Icons.verified_rounded)
        : d.isRejected
            ? (AppColors.danger, 'Ditolak', Icons.cancel_rounded)
            : (AppColors.warning, 'Menunggu', Icons.hourglass_top_rounded);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppCard(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (d.proofImage != null) ...[
                  _thumbBukti(d.proofImage!),
                  const SizedBox(width: 12),
                ],
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Text(
                            'Setoran #${d.id}',
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                              color: AppColors.inkSoft,
                            ),
                          ),
                          const Spacer(),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: warna.withValues(alpha: 0.12),
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(ikon, size: 13, color: warna),
                                const SizedBox(width: 4),
                                Text(
                                  label,
                                  style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: warna),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        formatRupiah(d.amount),
                        style: const TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.w800,
                          color: AppColors.ink,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${d.periodDates.map(_tanggalPanjang).join(' · ')} — ${d.transactionsCount} transaksi',
                        style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              [
                if (d.submittedAt != null) 'Diajukan ${_tanggalJam(d.submittedAt!)}',
                if (d.processedAt != null && !d.isPending)
                  '${d.isApproved ? 'Diterima' : 'Ditolak'} ${_tanggalJam(d.processedAt!)}',
              ].join('\n'),
              style: const TextStyle(fontSize: 11, color: AppColors.inkFaint, height: 1.4),
            ),
            // Alasan penolakan wajib terlihat: inilah yang memberi tahu CSO
            // apa yang harus diperbaiki sebelum mengajukan ulang.
            if (d.isRejected && d.adminNote != null) ...[
              const SizedBox(height: 8),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                decoration: BoxDecoration(
                  color: AppColors.danger.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  'Alasan: ${d.adminNote}',
                  style: const TextStyle(fontSize: 11.5, color: AppColors.danger),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _thumbBukti(String path) {
    final url = ApiService.storageUrl(path);
    return GestureDetector(
      onTap: () => showDialog(
        context: context,
        builder: (ctx) => Dialog(
          backgroundColor: Colors.black,
          insetPadding: const EdgeInsets.all(12),
          child: Stack(
            children: [
              InteractiveViewer(
                maxScale: 5,
                child: Image.network(url, fit: BoxFit.contain),
              ),
              Positioned(
                right: 4,
                top: 4,
                child: IconButton(
                  onPressed: () => Navigator.pop(ctx),
                  icon: const Icon(Icons.close_rounded, color: Colors.white),
                ),
              ),
            ],
          ),
        ),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Image.network(
          url,
          width: 56,
          height: 56,
          fit: BoxFit.cover,
          errorBuilder: (_, _, _) => Container(
            width: 56,
            height: 56,
            color: AppColors.paleBlue,
            child: const Icon(Icons.image_not_supported_outlined, color: AppColors.inkFaint),
          ),
        ),
      ),
    );
  }

  /// Bar bawah ringkas: total terpilih + satu tombol. Foto bukti diminta di
  /// lembar konfirmasi, supaya daftar tanggal mendapat ruang layar lebih luas.
  Widget _barSetor() {
    final adaPilihan = _selected.isNotEmpty;
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
      decoration: BoxDecoration(
        color: AppColors.surface,
        boxShadow: [
          BoxShadow(
            color: AppColors.deepBlue.withValues(alpha: 0.10),
            blurRadius: 18,
            offset: const Offset(0, -6),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    adaPilihan ? '${_selected.length} tanggal dipilih' : 'Pilih tanggal di atas',
                    style: const TextStyle(fontSize: 12, color: AppColors.inkSoft),
                  ),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(
                      formatRupiah(_totalTerpilih),
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w800,
                        color: AppColors.deepBlue,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            SizedBox(
              width: 150,
              child: GradientButton(
                label: 'Setor',
                icon: Icons.upload_rounded,
                height: 50,
                loading: _submitting,
                onPressed: adaPilihan ? _setor : null,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
