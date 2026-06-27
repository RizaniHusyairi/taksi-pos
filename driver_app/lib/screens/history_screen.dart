import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../services/api_service.dart';
import '../theme/app_colors.dart';
import '../widgets/sky_header.dart';
import '../widgets/fade_in.dart';

class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  final ApiService _apiService = ApiService();
  bool _isLoading = true;
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
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _isLoading = false);
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
                    ? _emptyState()
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
        ],
      ),
    );
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
