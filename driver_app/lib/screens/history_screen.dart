import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../services/api_service.dart';
import '../utils/api_error.dart';
import '../theme/app_colors.dart';
import '../widgets/sky_header.dart';
import '../widgets/fade_in.dart';
import '../widgets/error_state.dart';

class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  final ApiService _apiService = ApiService();
  bool _isLoading = true;
  String? _error;
  List<dynamic> _history = [];

  @override
  void initState() {
    super.initState();
    _fetchHistory();
  }

  Future<void> _fetchHistory() async {
    try {
      final response = await _apiService.getTripHistory();
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

  @override
  Widget build(BuildContext context) {
    final currencyFormat = NumberFormat.currency(
      locale: 'id_ID',
      symbol: 'Rp ',
      decimalDigits: 0,
    );
    final dateFormat = DateFormat('dd MMM yyyy, HH:mm');

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
                : _history.isEmpty
                    ? (_error != null ? _errorBody() : _emptyState())
                    : RefreshIndicator(
                        color: AppColors.skyBlue,
                        onRefresh: _fetchHistory,
                        child: ListView.builder(
                          padding: const EdgeInsets.fromLTRB(16, 20, 16, 24),
                          itemCount: _history.length,
                          itemBuilder: (context, index) {
                            return FadeInUp(
                              delayMs: (index * 45).clamp(0, 300),
                              child: _tripCard(
                                _history[index],
                                currencyFormat,
                                dateFormat,
                              ),
                            );
                          },
                        ),
                      ),
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
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.flight_takeoff_rounded,
              size: 64, color: AppColors.inkFaint.withValues(alpha: 0.5)),
          const SizedBox(height: 16),
          Text(
            "Belum ada perjalanan.",
            style: GoogleFonts.outfit(color: AppColors.inkFaint, fontSize: 15),
          ),
        ],
      ),
    );
  }

  Widget _tripCard(
    dynamic trip,
    NumberFormat currencyFormat,
    DateFormat dateFormat,
  ) {
    final booking = trip['booking'];
    final zone = booking['zone_to']?['name'] ??
        (booking['manual_destination'] != null
            ? 'Self: ${booking['manual_destination']}'
            : 'Manual / Charter');

    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: AppColors.deepBlue.withValues(alpha: 0.06),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppColors.paleBlue,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.place_rounded,
                    color: AppColors.skyBlue, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  zone,
                  style: GoogleFonts.outfit(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: AppColors.ink,
                  ),
                ),
              ),
              Text(
                currencyFormat.format(double.parse(trip['amount'].toString())),
                style: GoogleFonts.outfit(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: AppColors.cyan,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              const Icon(Icons.calendar_today_rounded,
                  size: 13, color: AppColors.inkFaint),
              const SizedBox(width: 5),
              Text(
                dateFormat.format(DateTime.parse(trip['created_at'])),
                style: GoogleFonts.outfit(
                  color: AppColors.inkFaint,
                  fontSize: 12,
                ),
              ),
              const Spacer(),
              _buildStatusBadge(trip),
            ],
          ),
          ..._barisSanggahan(trip),
        ],
      ),
    );
  }

  /// Baris sanggahan metode pembayaran.
  ///
  /// Muncul untuk order yang tercatat "Tunai ke Supir" — sistem mengklaim supir
  /// memegang uangnya dan menagih komisinya. Kalau kenyataannya uang itu
  /// diterima kasir, inilah satu-satunya cara supir membantah; tanpa ini ia
  /// baru sadar saat dompetnya minus dan tidak punya saluran resmi apa pun.
  ///
  /// Kelayakannya TIDAK dihitung ulang di sini — dibaca dari flag `can_dispute`
  /// yang dikirim server, supaya aturan di aplikasi tidak bisa menyimpang dari
  /// yang ditegakkan endpoint.
  List<Widget> _barisSanggahan(dynamic trip) {
    final status = trip['method_dispute_status'];

    if (status == 'Open') {
      return [_infoSanggahan(
        Icons.hourglass_top_rounded,
        AppColors.warning,
        'Sanggahan terkirim — menunggu keputusan admin.',
      )];
    }
    if (status == 'Upheld') {
      return [_infoSanggahan(
        Icons.verified_rounded,
        AppColors.success,
        'Sanggahan dikabulkan. Order ini dikoreksi menjadi Tunai ke Kasir.',
      )];
    }
    if (status == 'Rejected') {
      final catatan = trip['method_admin_note'];
      return [_infoSanggahan(
        Icons.cancel_rounded,
        AppColors.danger,
        'Sanggahan ditolak.${catatan != null ? ' Alasan: $catatan' : ''}',
      )];
    }

    if (trip['can_dispute'] != true) return [];

    return [
      const SizedBox(height: 10),
      SizedBox(
        width: double.infinity,
        child: OutlinedButton.icon(
          onPressed: () => _ajukanSanggahan(trip),
          icon: const Icon(Icons.report_problem_outlined, size: 16),
          label: const Text('Saya tidak menerima uang tunai ini'),
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.danger,
            side: BorderSide(color: AppColors.danger.withValues(alpha: 0.5)),
            padding: const EdgeInsets.symmetric(vertical: 10),
            textStyle: GoogleFonts.outfit(fontSize: 12.5, fontWeight: FontWeight.w600),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
      ),
    ];
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
                hintText: 'Contoh: uang diterima kasir di loket, saya tidak pegang.',
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
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Alasan terlalu pendek.')),
        );
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

  Widget _buildStatusBadge(Map<String, dynamic> trip) {
    final status = trip['payout_status'];
    final method = trip['method'];

    Color color;
    String text;

    switch (status) {
      case 'Paid':
        color = AppColors.success;
        // Jika metode 'CashDriver' (Hutang), istilahnya LUNAS
        text = (method == 'CashDriver') ? 'SUDAH LUNAS' : 'SUDAH CAIR';
        break;
      case 'Processing':
        color = AppColors.warning;
        text = 'DIPROSES';
        break;
      case 'Unpaid':
      default:
        color = AppColors.danger;
        // Jika metode 'CashDriver' (Hutang), istilahnya BELUM LUNAS
        text = (method == 'CashDriver') ? 'BELUM LUNAS' : 'BELUM CAIR';
        break;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        text,
        style: GoogleFonts.outfit(
          color: color,
          fontSize: 10,
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }
}
