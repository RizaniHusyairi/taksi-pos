import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';

import '../services/api_service.dart';
import '../theme/app_colors.dart';

/// Lembar bawah berisi transaksi di balik satu angka pada kartu Rincian Saldo.
///
/// Kartu itu sudah memperlihatkan rumusnya, tapi berhenti di jumlah agregat
/// ("18 transaksi", "3 trip"): supir bisa melihat BERAPA, tidak bisa melihat
/// DARI MANA. Lembar ini menutup jarak itu — tiap baris menyebut berapa yang
/// ia sumbang ke angka tersebut, sehingga totalnya bisa ditelusuri sendiri.
class WalletSourceSheet extends StatefulWidget {
  final String bucket;

  const WalletSourceSheet({super.key, required this.bucket});

  static Future<void> tampilkan(BuildContext context, String bucket) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => WalletSourceSheet(bucket: bucket),
    );
  }

  @override
  State<WalletSourceSheet> createState() => _WalletSourceSheetState();
}

class _WalletSourceSheetState extends State<WalletSourceSheet> {
  final _api = ApiService();
  final _rupiah = NumberFormat.currency(
      locale: 'id_ID', symbol: 'Rp ', decimalDigits: 0);
  // TANPA argumen locale. Aplikasi ini tidak pernah memanggil
  // initializeDateFormatting(), sehingga DateFormat('…', 'id_ID') melempar
  // LocaleDataException begitu lembar dibuka. Seluruh DateFormat lain di
  // aplikasi (history_screen, wallet_screen) juga memakai locale bawaan, jadi
  // ini sekaligus menjaga tampilannya tetap seragam.
  final _tanggal = DateFormat('dd MMM yyyy, HH:mm');

  bool _memuat = true;
  String? _galat;
  Map<String, dynamic>? _data;

  @override
  void initState() {
    super.initState();
    _ambil();
  }

  Future<void> _ambil() async {
    setState(() {
      _memuat = true;
      _galat = null;
    });

    try {
      final res = await _api.getBalanceTransactions(widget.bucket);
      if (!mounted) return;
      setState(() {
        _data = Map<String, dynamic>.from(res.data);
        _memuat = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _galat = 'Gagal memuat rincian. Periksa koneksi Anda.';
        _memuat = false;
      });
    }
  }

  /// Kata untuk kontribusi tiap baris — order manual dikenai tarif tetap,
  /// jadi menyebutnya "komisi" akan menyesatkan.
  String get _sebutanKontribusi =>
      widget.bucket == 'debt_manual' ? 'biaya tetap' : 'komisi';

  Color get _warna => switch (widget.bucket) {
        'income' => AppColors.success,
        'debt_manual' => AppColors.warning,
        _ => AppColors.warning,
      };

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.62,
      minChildSize: 0.35,
      maxChildSize: 0.9,
      expand: false,
      builder: (context, scrollController) {
        return Container(
          decoration: const BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
          ),
          child: Column(
            children: [
              // Pegangan geser — penanda bahwa lembar ini bisa ditarik/ditutup.
              Container(
                width: 42,
                height: 4,
                margin: const EdgeInsets.only(top: 10, bottom: 6),
                decoration: BoxDecoration(
                  color: AppColors.inkFaint.withValues(alpha: 0.4),
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
              _kepala(),
              const Divider(height: 1),
              Expanded(child: _isi(scrollController)),
            ],
          ),
        );
      },
    );
  }

  Widget _kepala() {
    final d = _data;
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 10, 20, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  d?['label'] ?? 'Rincian',
                  style: GoogleFonts.outfit(
                    fontSize: 17,
                    fontWeight: FontWeight.bold,
                    color: AppColors.ink,
                  ),
                ),
              ),
              if (d != null)
                Text(
                  _rupiah.format((d['total'] ?? 0) as num),
                  style: GoogleFonts.outfit(
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    color: _warna,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 3),
          Text(
            d == null
                ? 'Memuat…'
                : 'Nominal ini berasal dari ${d['count']} transaksi berikut.',
            style: GoogleFonts.outfit(fontSize: 12.5, color: AppColors.inkSoft),
          ),
        ],
      ),
    );
  }

  Widget _isi(ScrollController controller) {
    if (_memuat) {
      return const Center(child: CircularProgressIndicator(strokeWidth: 2.5));
    }

    if (_galat != null) {
      return _pesan(
        Icons.cloud_off_rounded,
        _galat!,
        aksi: TextButton(onPressed: _ambil, child: const Text('Coba lagi')),
      );
    }

    final items = List<Map<String, dynamic>>.from(
        (_data?['items'] as List?)?.map((e) => Map<String, dynamic>.from(e)) ?? []);

    if (items.isEmpty) {
      return _pesan(Icons.receipt_long_rounded,
          'Belum ada transaksi pada bagian ini.');
    }

    final terpotong = _data?['truncated'] == true;

    return ListView.separated(
      controller: controller,
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
      itemCount: items.length + (terpotong ? 1 : 0),
      separatorBuilder: (_, _) => const Divider(height: 18),
      itemBuilder: (context, i) {
        if (i == items.length) {
          // Jujur mengatakan daftarnya dipotong; tanpa ini supir mengira
          // transaksinya hilang.
          return Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              'Menampilkan ${items.length} transaksi terbaru dari ${_data?['count']}.',
              textAlign: TextAlign.center,
              style: GoogleFonts.outfit(
                  fontSize: 11.5, color: AppColors.inkFaint),
            ),
          );
        }
        return _baris(items[i]);
      },
    );
  }

  Widget _baris(Map<String, dynamic> t) {
    final waktu = DateTime.tryParse(t['date']?.toString() ?? '');
    final metode = switch (t['method']) {
      'QRIS' => 'QRIS',
      'CashCSO' => 'Tunai (Kasir)',
      'CashDriver' => 'Tunai (Supir)',
      _ => t['method']?.toString() ?? '-',
    };

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                t['destination']?.toString() ?? '-',
                style: GoogleFonts.outfit(
                  fontSize: 13.5,
                  fontWeight: FontWeight.w700,
                  color: AppColors.ink,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                waktu != null ? _tanggal.format(waktu) : '-',
                style: GoogleFonts.outfit(
                    fontSize: 11.5, color: AppColors.inkFaint),
              ),
              const SizedBox(height: 4),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                decoration: BoxDecoration(
                  color: AppColors.paleBlue,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  metode,
                  style: GoogleFonts.outfit(
                    fontSize: 10.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.royalBlue,
                  ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 12),
        Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              _rupiah.format((t['amount'] ?? 0) as num),
              style: GoogleFonts.outfit(
                fontSize: 13.5,
                fontWeight: FontWeight.w600,
                color: AppColors.inkSoft,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              '$_sebutanKontribusi ${_rupiah.format((t['contribution'] ?? 0) as num)}',
              style: GoogleFonts.outfit(
                fontSize: 12,
                fontWeight: FontWeight.w800,
                color: _warna,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _pesan(IconData ikon, String teks, {Widget? aksi}) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(ikon, size: 40, color: AppColors.inkFaint),
            const SizedBox(height: 10),
            Text(
              teks,
              textAlign: TextAlign.center,
              style:
                  GoogleFonts.outfit(fontSize: 13, color: AppColors.inkSoft),
            ),
            if (aksi != null) ...[const SizedBox(height: 6), aksi],
          ],
        ),
      ),
    );
  }
}
