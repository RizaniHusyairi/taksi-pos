import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../../models/queue_driver.dart';
import '../../services/api_service.dart';
import '../../theme/app_colors.dart';

/// Bottom sheet untuk mengalihkan sebuah booking ke supir lain.
/// Mengembalikan `true` lewat Navigator.pop bila berhasil.
class CsoChangeDriverSheet extends StatefulWidget {
  final int bookingId;
  const CsoChangeDriverSheet({super.key, required this.bookingId});

  static Future<bool?> show(BuildContext context, int bookingId) {
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => CsoChangeDriverSheet(bookingId: bookingId),
    );
  }

  @override
  State<CsoChangeDriverSheet> createState() => _CsoChangeDriverSheetState();
}

class _CsoChangeDriverSheetState extends State<CsoChangeDriverSheet> {
  final _api = ApiService();
  List<QueueDriver> _drivers = [];
  bool _loading = true;
  bool _submitting = false;
  String? _error;

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
      final res = await _api.getCsoAvailableDrivers();
      final list = (res.data as List)
          .map((e) => QueueDriver.fromJson((e as Map).cast<String, dynamic>()))
          .where((d) => d.isReady)
          .toList();
      if (mounted) setState(() => _drivers = list);
    } catch (e) {
      if (mounted) setState(() => _error = 'Gagal memuat supir.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _change(QueueDriver driver) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Ganti Supir'),
        content: Text('Alihkan order ini ke ${driver.name}?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Ya, Ganti'),
          ),
        ],
      ),
    );
    if (ok != true) return;

    setState(() => _submitting = true);
    try {
      await _api.csoChangeDriver(widget.bookingId, driver.id);
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      var msg = 'Gagal mengganti supir.';
      if (e is DioException && e.response?.data is Map) {
        msg = (e.response!.data['message'] ?? msg).toString();
      }
      if (mounted) {
        setState(() => _submitting = false);
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(msg)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.7,
      minChildSize: 0.45,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, controller) => Container(
        decoration: const BoxDecoration(
          color: AppColors.background,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        child: Column(
          children: [
            const SizedBox(height: 12),
            Container(
              width: 44,
              height: 5,
              decoration: BoxDecoration(
                color: AppColors.inkFaint.withValues(alpha: 0.4),
                borderRadius: BorderRadius.circular(3),
              ),
            ),
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 14, 20, 6),
              child: Row(
                children: [
                  Icon(Icons.swap_horiz_rounded, color: AppColors.danger),
                  SizedBox(width: 8),
                  Text(
                    'Ganti Supir',
                    style: TextStyle(
                      color: AppColors.ink,
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _error != null
                  ? _msg(_error!, isError: true)
                  : _drivers.isEmpty
                  ? _msg('Tidak ada supir standby.')
                  : ListView.builder(
                      controller: controller,
                      padding: const EdgeInsets.fromLTRB(16, 6, 16, 24),
                      itemCount: _drivers.length,
                      itemBuilder: (context, i) => _tile(_drivers[i]),
                    ),
            ),
            if (_submitting)
              const Padding(
                padding: EdgeInsets.only(bottom: 16),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                    SizedBox(width: 10),
                    Text(
                      'Mengalihkan order...',
                      style: TextStyle(
                        color: AppColors.inkSoft,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _tile(QueueDriver d) {
    final initial = d.name.isNotEmpty ? d.name[0].toUpperCase() : '?';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
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
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            alignment: Alignment.center,
            decoration: const BoxDecoration(
              gradient: AppColors.buttonGradient,
              shape: BoxShape.circle,
            ),
            child: Text(
              initial,
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w800,
                fontSize: 16,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    if (d.lineNumber != null)
                      Padding(
                        padding: const EdgeInsets.only(right: 6),
                        child: Text(
                          '#L${d.lineNumber}',
                          style: const TextStyle(
                            color: AppColors.deepBlue,
                            fontWeight: FontWeight.w700,
                            fontSize: 11,
                          ),
                        ),
                      ),
                    Flexible(
                      child: Text(
                        d.name,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: AppColors.ink,
                          fontWeight: FontWeight.w700,
                          fontSize: 14,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  '${d.carModel ?? '-'} • ${d.plateNumber ?? '-'}',
                  style: const TextStyle(
                    color: AppColors.inkSoft,
                    fontSize: 12,
                  ),
                ),
              ],
            ),
          ),
          ElevatedButton(
            onPressed: _submitting ? null : () => _change(d),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.deepBlue,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
            ),
            child: const Text(
              'Pilih',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
        ],
      ),
    );
  }

  Widget _msg(String text, {bool isError = false}) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          text,
          textAlign: TextAlign.center,
          style: TextStyle(
            color: isError ? AppColors.danger : AppColors.inkSoft,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}
