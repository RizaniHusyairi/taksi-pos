import 'package:flutter/material.dart';

import '../../models/cso_deposit.dart';
import '../../services/api_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/api_error.dart';
import '../../utils/format.dart';
import '../../widgets/app_card.dart';
import '../../widgets/fade_in.dart';
import '../../widgets/gradient_button.dart';

/// Tab "Setoran": CSO menyerahkan uang tunai penumpang (metode Tunai ke Kasir)
/// ke admin.
///
/// Setoran dilakukan PER TANGGAL — CSO mencentang satu atau beberapa hari,
/// server yang menjumlahkan nominalnya. Nominal sengaja tidak bisa diketik:
/// angka yang disetor harus selalu sama dengan catatan transaksi, supaya
/// rekonsiliasi tidak pernah bergantung pada ingatan orang.
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

  num _grandTotal = 0;
  bool _loading = true;
  bool _submitting = false;
  String? _error;

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
          .toList();

      // Endpoint riwayat dipaginasi Laravel: isinya ada di key 'data'.
      final rawHist = hasil[1].data;
      final listHist = rawHist is Map ? (rawHist['data'] as List? ?? []) : (rawHist as List? ?? []);
      final history = listHist
          .map((e) => CsoDeposit.fromJson(Map<String, dynamic>.from(e)))
          .toList();

      if (!mounted) return;
      setState(() {
        _days = days;
        _grandTotal = out['grand_total'] is num
            ? out['grand_total'] as num
            : num.tryParse('${out['grand_total']}') ?? 0;
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

  String _tanggalPanjang(String ymd) {
    final d = DateTime.tryParse(ymd);
    if (d == null) return ymd;
    return '${d.day} ${_namaBulan[d.month - 1]} ${d.year}';
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
          '($_jumlahTransaksiTerpilih transaksi).\n\n'
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
      await _api.createCsoDeposit(_selected.toList()..sort());
      if (!mounted) return;
      _selected.clear();
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
                _judul('Belum Disetor', Icons.savings_rounded),
                const SizedBox(height: 10),
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
    return AppCard(
      gradient: AppColors.skyGradient,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.account_balance_wallet_rounded, color: Colors.white, size: 18),
              SizedBox(width: 8),
              Text(
                'Tunai di tangan Anda',
                style: TextStyle(color: Colors.white70, fontSize: 12.5, fontWeight: FontWeight.w600),
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
          const Icon(Icons.check_circle_outline_rounded, color: AppColors.success, size: 20),
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

  Widget _kartuTanggal(OutstandingDay hari) {
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
          border: dipilih
              ? Border.all(color: AppColors.skyBlue, width: 1.6)
              : null,
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
                      '${hari.count} transaksi tunai',
                      style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
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

  Widget _kartuRiwayat(CsoDeposit d) {
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
                  style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: warna),
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
              '${d.periodDates.map(_tanggalPanjang).join(' · ')} — ${d.transactionsCount} transaksi',
              style: const TextStyle(fontSize: 11.5, color: AppColors.inkSoft),
            ),
            if (d.submittedAt != null) ...[
              const SizedBox(height: 2),
              Text(
                'Diajukan ${_tanggalPanjang(d.submittedAt!.toIso8601String().substring(0, 10))}',
                style: const TextStyle(fontSize: 11, color: AppColors.inkFaint),
              ),
            ],
            // Alasan penolakan wajib terlihat: inilah yang memberi tahu CSO
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
