import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../models/driver_deposit.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../utils/api_error.dart';
import '../utils/format.dart';
import '../widgets/app_card.dart';
import '../widgets/fade_in.dart';
import '../widgets/gradient_button.dart';

/// Layar "Setor Tunai": supir melunasi utang komisi atas order yang dibayar
/// penumpang secara tunai langsung kepadanya.
///
/// Alurnya sengaja dibuat kembar dengan layar setoran CSO — pilih tanggal,
/// server yang menjumlahkan, admin yang memverifikasi — supaya siapa pun yang
/// pernah memakai salah satunya langsung paham yang lain.
///
/// Satu hal yang WAJIB jelas di layar ini dan tidak ada di layar CSO: angka
/// yang disetor adalah KOMISI koperasi, bukan seluruh ongkos penumpang. Supir
/// yang mengira harus menyerahkan Rp 150.000 padahal cuma Rp 30.000 akan
/// menunda-nunda setoran, dan itu persis masalah yang ingin diselesaikan
/// fitur ini.
class DriverDepositScreen extends StatefulWidget {
  const DriverDepositScreen({super.key});

  @override
  State<DriverDepositScreen> createState() => _DriverDepositScreenState();
}

class _DriverDepositScreenState extends State<DriverDepositScreen> {
  final _api = ApiService();

  List<DriverOutstandingDay> _days = [];
  List<DriverDeposit> _history = [];
  final Set<String> _selected = {};

  num _grandTotal = 0;
  num _debtLimit = 0;
  bool _loading = true;
  bool _submitting = false;
  String? _error;

  /// Foto bukti serah terima (opsional). Server yang membakar keterangan
  /// setoran ke gambarnya — lihat App\Services\ImageWatermark di backend.
  String? _proofPath;

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
        _api.getDriverDepositOutstanding(),
        _api.getDriverDeposits(),
      ]);

      final out = Map<String, dynamic>.from(hasil[0].data as Map);
      final days = (out['days'] as List? ?? [])
          .map((e) => DriverOutstandingDay.fromJson(Map<String, dynamic>.from(e)))
          .toList();

      // Endpoint riwayat dipaginasi Laravel: isinya ada di key 'data'.
      final rawHist = hasil[1].data;
      final listHist =
          rawHist is Map ? (rawHist['data'] as List? ?? []) : (rawHist as List? ?? []);
      final history = listHist
          .map((e) => DriverDeposit.fromJson(Map<String, dynamic>.from(e)))
          .toList();

      num toNum(dynamic v) =>
          v is num ? v : (num.tryParse(v?.toString() ?? '0') ?? 0);

      if (!mounted) return;
      setState(() {
        _days = days;
        _grandTotal = toNum(out['grand_total']);
        _debtLimit = toNum(out['debt_limit']);
        _history = history;
        // Buang pilihan atas tanggal yang sudah tidak ada lagi (mis. baru
        // disetor dari perangkat lain) supaya total terpilih tidak berbohong.
        _selected.removeWhere((d) => !days.any((h) => h.date == d));
        _loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = apiErrorMessage(e);
          _loading = false;
        });
      }
    }
  }

  num get _totalTerpilih => _days
      .where((d) => _selected.contains(d.date))
      .fold<num>(0, (a, d) => a + d.total);

  int get _jumlahTransaksiTerpilih => _days
      .where((d) => _selected.contains(d.date))
      .fold<int>(0, (a, d) => a + d.count);

  /// Utang sudah melewati batas yang ditetapkan admin -> antrian terkunci.
  bool get _terblokir => _debtLimit > 0 && _grandTotal > _debtLimit;

  String _tanggalPanjang(String ymd) {
    final d = DateTime.tryParse(ymd);
    if (d == null) return ymd;
    return '${d.day} ${_namaBulan[d.month - 1]} ${d.year}';
  }

  /// Ambil foto bukti serah terima. Kamera didahulukan karena inilah cara
  /// pakainya di lapangan: memotret uang/kuitansi saat menyerahkannya.
  Future<void> _ambilFoto() async {
    final sumber = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 16, 20, 4),
              child: Text(
                'Foto bukti serah terima',
                style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.ink),
              ),
            ),
            ListTile(
              leading: const Icon(Icons.photo_camera_rounded, color: AppColors.skyBlue),
              title: const Text('Kamera'),
              subtitle: const Text('Potret uang / kuitansi saat diserahkan'),
              onTap: () => Navigator.pop(ctx, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_rounded, color: AppColors.skyBlue),
              title: const Text('Galeri'),
              onTap: () => Navigator.pop(ctx, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (sumber == null) return;

    try {
      final file = await ImagePicker().pickImage(
        source: sumber,
        imageQuality: 75,
        maxWidth: 1600,
      );
      if (file != null && mounted) setState(() => _proofPath = file.path);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Gagal mengambil gambar.')),
        );
      }
    }
  }

  Future<void> _setor() async {
    if (_selected.isEmpty || _submitting) return;

    final setuju = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Konfirmasi Setoran'),
        content: Text(
          'Anda menyetorkan ${formatRupiah(_totalTerpilih)} '
          'dari ${_selected.length} tanggal '
          '($_jumlahTransaksiTerpilih order).\n\n'
          'Nominal ini adalah komisi koperasi, bukan seluruh tarif penumpang.\n\n'
          '${_proofPath == null ? 'Tanpa foto bukti. ' : 'Foto bukti akan diberi keterangan setoran otomatis. '}'
          'Serahkan uangnya ke admin, lalu admin akan memverifikasi setoran ini.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Setor Sekarang'),
          ),
        ],
      ),
    );
    if (setuju != true) return;

    setState(() => _submitting = true);
    try {
      await _api.createDriverDeposit(
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text('Setor Tunai'),
        backgroundColor: AppColors.surface,
        foregroundColor: AppColors.ink,
        elevation: 0,
      ),
      body: _body(),
    );
  }

  Widget _body() {
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
                if (_terblokir) ...[
                  const SizedBox(height: 12),
                  _peringatanBlokir(),
                ],
                const SizedBox(height: 18),
                _judul('Belum Disetor', Icons.savings_rounded),
                const SizedBox(height: 10),
                if (_days.isEmpty)
                  _kosong('Tidak ada utang setoran. Semua sudah beres.')
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
    return AppCard(
      gradient: AppColors.skyGradient,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.savings_rounded, color: Colors.white, size: 18),
              SizedBox(width: 8),
              Text(
                'Utang setoran ke koperasi',
                style: TextStyle(
                    color: Colors.white70, fontSize: 12.5, fontWeight: FontWeight.w600),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            formatRupiah(_grandTotal),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 28,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.5,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            _days.isEmpty
                ? 'Semua setoran sudah diserahkan'
                : 'Dari ${_days.length} tanggal yang belum disetor',
            style: const TextStyle(color: Colors.white70, fontSize: 12),
          ),
          // Menyebut batasnya sejak awal — supir berhak tahu seberapa dekat
          // ia dengan titik di mana antriannya terkunci, bukan baru sadar
          // saat ditolak di gerbang.
          if (_debtLimit > 0) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                'Batas utang: ${formatRupiah(_debtLimit)}',
                style: const TextStyle(
                    color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _peringatanBlokir() {
    return AppCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.lock_rounded, color: AppColors.danger, size: 20),
          const SizedBox(width: 10),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Antrian terkunci',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.danger,
                  ),
                ),
                SizedBox(height: 2),
                Text(
                  'Utang Anda melewati batas. Setor tunai ke admin dulu, '
                  'lalu Anda bisa masuk antrian lagi.',
                  style: TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _judul(String teks, IconData ikon) {
    return Row(
      children: [
        Icon(ikon, size: 16, color: AppColors.inkSoft),
        const SizedBox(width: 6),
        Text(
          teks,
          style: const TextStyle(
            fontSize: 13.5,
            fontWeight: FontWeight.w700,
            color: AppColors.ink,
          ),
        ),
      ],
    );
  }

  Widget _kosong(String pesan) {
    return AppCard(
      child: Row(
        children: [
          const Icon(Icons.check_circle_outline_rounded,
              color: AppColors.success, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              pesan,
              style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
            ),
          ),
        ],
      ),
    );
  }

  Widget _kartuTanggal(DriverOutstandingDay hari) {
    final dipilih = _selected.contains(hari.date);
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(22),
        onTap: () => setState(() {
          dipilih ? _selected.remove(hari.date) : _selected.add(hari.date);
        }),
        child: AppCard(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          border:
              dipilih ? Border.all(color: AppColors.skyBlue, width: 1.6) : null,
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
                    const SizedBox(height: 2),
                    Text(
                      '${hari.count} order tunai',
                      style:
                          const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
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
            ],
          ),
        ),
      ),
    );
  }

  Widget _kartuRiwayat(DriverDeposit d) {
    final (Color warna, String label, IconData ikon) = d.isApproved
        ? (AppColors.success, 'Diterima', Icons.verified_rounded)
        : d.isRejected
            ? (AppColors.danger, 'Ditolak', Icons.cancel_rounded)
            : (AppColors.warning, 'Menunggu', Icons.hourglass_top_rounded);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppCard(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(ikon, size: 16, color: warna),
                const SizedBox(width: 6),
                Text(
                  label,
                  style: TextStyle(
                      fontSize: 11.5, fontWeight: FontWeight.w700, color: warna),
                ),
                const Spacer(),
                Text(
                  formatRupiah(d.amount),
                  style: const TextStyle(
                    fontSize: 14.5,
                    fontWeight: FontWeight.w800,
                    color: AppColors.ink,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              '${d.periodDates.map(_tanggalPanjang).join(' · ')} — ${d.transactionsCount} order',
              style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
            ),
            if (d.submittedAt != null) ...[
              const SizedBox(height: 2),
              Text(
                'Diajukan ${_tanggalPanjang(d.submittedAt!.toIso8601String().substring(0, 10))}',
                style: const TextStyle(fontSize: 11, color: AppColors.inkFaint),
              ),
            ],
            // Alasan penolakan wajib terlihat: inilah yang memberi tahu supir
            // apa yang harus diperbaiki sebelum mengajukan ulang.
            if (d.isRejected && d.adminNote != null) ...[
              const SizedBox(height: 8),
              Container(
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

  /// Baris lampiran foto: tombol kamera bila belum ada, pratinjau + tombol
  /// hapus bila sudah. Opsional — setoran tetap bisa diajukan tanpa foto.
  Widget _barisFoto() {
    if (_proofPath == null) {
      return SizedBox(
        width: double.infinity,
        child: OutlinedButton.icon(
          onPressed: _submitting ? null : _ambilFoto,
          icon: const Icon(Icons.photo_camera_rounded, size: 18),
          label: const Text('Lampirkan Foto Bukti (opsional)'),
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.skyBlue,
            side: const BorderSide(color: AppColors.skyBlue, width: 1.2),
            padding: const EdgeInsets.symmetric(vertical: 12),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: AppColors.paleBlue,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: Image.file(
              File(_proofPath!),
              width: 46,
              height: 46,
              fit: BoxFit.cover,
              errorBuilder: (_, _, _) => const SizedBox(
                width: 46,
                height: 46,
                child:
                    Icon(Icons.broken_image_rounded, color: AppColors.inkFaint),
              ),
            ),
          ),
          const SizedBox(width: 10),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Foto bukti terlampir',
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.ink,
                  ),
                ),
                SizedBox(height: 2),
                Text(
                  'Keterangan setoran ditambahkan otomatis',
                  style: TextStyle(fontSize: 11, color: AppColors.inkSoft),
                ),
              ],
            ),
          ),
          IconButton(
            tooltip: 'Ganti foto',
            onPressed: _submitting ? null : _ambilFoto,
            icon: const Icon(Icons.refresh_rounded,
                size: 19, color: AppColors.skyBlue),
          ),
          IconButton(
            tooltip: 'Hapus foto',
            onPressed: _submitting ? null : () => setState(() => _proofPath = null),
            icon: const Icon(Icons.close_rounded,
                size: 19, color: AppColors.danger),
          ),
        ],
      ),
    );
  }

  Widget _barSetor() {
    final adaPilihan = _selected.isNotEmpty;
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
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
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _barisFoto(),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Text(
                  adaPilihan
                      ? '${_selected.length} tanggal dipilih'
                      : 'Pilih tanggal yang akan disetor',
                  style: const TextStyle(fontSize: 12.5, color: AppColors.inkSoft),
                ),
              ),
              Text(
                formatRupiah(_totalTerpilih),
                style: const TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.w800,
                  color: AppColors.deepBlue,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          GradientButton(
            label: 'Setor ke Admin',
            icon: Icons.upload_rounded,
            loading: _submitting,
            onPressed: adaPilihan ? _setor : null,
          ),
        ],
      ),
    );
  }
}
