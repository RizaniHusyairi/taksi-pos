import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../models/queue_driver.dart';
import '../../providers/cso_order_provider.dart';
import '../../theme/app_colors.dart';
import '../../utils/api_error.dart';

/// Bottom sheet pemilihan supir (langkah 3). Hanya menampilkan supir yang
/// standby/available. Saat dipilih → finalisasi order (process-order).
/// Mengembalikan data booking lewat Navigator.pop bila order berhasil.
class CsoSelectDriverSheet extends StatefulWidget {
  const CsoSelectDriverSheet({super.key});

  static Future<Map<String, dynamic>?> show(BuildContext context) {
    final provider = context.read<CsoOrderProvider>();
    return showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => ChangeNotifierProvider<CsoOrderProvider>.value(
        value: provider,
        child: const CsoSelectDriverSheet(),
      ),
    );
  }

  @override
  State<CsoSelectDriverSheet> createState() => _CsoSelectDriverSheetState();
}

class _CsoSelectDriverSheetState extends State<CsoSelectDriverSheet> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<CsoOrderProvider>().loadDrivers();
    });
  }

  Future<void> _pick(QueueDriver driver) async {
    final provider = context.read<CsoOrderProvider>();

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Konfirmasi'),
        content: Text('Assign order ini ke ${driver.name}?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Ya, Assign'),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    try {
      final data = await provider.finalizeOrder(driver.id);
      if (mounted) Navigator.of(context).pop(data);
    } catch (e) {
      final msg = apiErrorMessage(e);
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(msg)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<CsoOrderProvider>();
    final ready = provider.drivers.where((d) => d.isReady).toList();

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
                  Icon(Icons.groups_rounded, color: AppColors.deepBlue),
                  SizedBox(width: 8),
                  Text(
                    'Pilih Supir',
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
              child: provider.loadingDrivers
                  ? const Center(child: CircularProgressIndicator())
                  : provider.driversError != null
                  ? _message(provider.driversError!, isError: true)
                  : ready.isEmpty
                  ? _message('Tidak ada supir standby saat ini.')
                  : ListView.builder(
                      controller: controller,
                      padding: const EdgeInsets.fromLTRB(16, 6, 16, 24),
                      itemCount: ready.length,
                      itemBuilder: (context, i) => _driverTile(
                        ready[i],
                        showRejoin: _isFirstRejoin(ready, i),
                      ),
                    ),
            ),
            if (provider.submitting)
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
                      'Memproses order...',
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

  bool _isFirstRejoin(List<QueueDriver> list, int i) {
    if (!list[i].isRejoin) return false;
    return i == 0 || !list[i - 1].isRejoin;
  }

  Widget _driverTile(QueueDriver d, {required bool showRejoin}) {
    final badge = d.queueNumber?.toString() ?? '↺';
    return Column(
      children: [
        if (showRejoin)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 10),
            child: Row(
              children: [
                const Expanded(child: Divider(endIndent: 8)),
                Text(
                  '↺ Antrian Rejoin',
                  style: TextStyle(
                    color: AppColors.warning,
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 0.5,
                  ),
                ),
                const Expanded(child: Divider(indent: 8)),
              ],
            ),
          ),
        Container(
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
                  badge,
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
                onPressed: () => _pick(d),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.success,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 10,
                  ),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                child: const Text(
                  'PILIH',
                  style: TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _message(String text, {bool isError = false}) {
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
