import 'package:flutter/material.dart';
import '../../models/cso_transaction.dart';
import '../../services/api_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/format.dart';
import '../../widgets/cso/cso_receipt_sheet.dart';
import '../../widgets/cso/cso_change_driver_sheet.dart';

/// Tab "Riwayat Transaksi": daftar transaksi CSO + filter tanggal,
/// lihat bukti & struk, serta ganti supir untuk order yang masih berjalan.
/// UI disamakan dengan tab lain: ringkasan, kartu berikon status, animasi masuk.
class CsoHistoryScreen extends StatefulWidget {
  const CsoHistoryScreen({super.key});

  @override
  State<CsoHistoryScreen> createState() => _CsoHistoryScreenState();
}

class _CsoHistoryScreenState extends State<CsoHistoryScreen> {
  final _api = ApiService();
  List<CsoTransaction> _txs = [];
  bool _loading = true;
  String? _error;

  late DateTime _start;
  late DateTime _end;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _start = DateTime(now.year, now.month, now.day);
    _end = _start;
    _load();
  }

  String _ymd(DateTime d) => '${d.year}-${_two(d.month)}-${_two(d.day)}';
  String _two(int n) => n.toString().padLeft(2, '0');

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await _api.getCsoHistory(
        startDate: _ymd(_start),
        endDate: _ymd(_end),
      );
      final list = (res.data as List)
          .map(
            (e) => CsoTransaction.fromJson((e as Map).cast<String, dynamic>()),
          )
          .toList();
      if (mounted) setState(() => _txs = list);
    } catch (e) {
      if (mounted) setState(() => _error = 'Gagal memuat riwayat.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _pickDate({required bool isStart}) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: isStart ? _start : _end,
      firstDate: DateTime(2024, 1, 1),
      lastDate: DateTime.now().add(const Duration(days: 1)),
    );
    if (picked == null) return;
    setState(() {
      if (isStart) {
        _start = picked;
        if (_end.isBefore(_start)) _end = _start;
      } else {
        _end = picked;
        if (_start.isAfter(_end)) _start = _end;
      }
    });
    _load();
  }

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
      child: Column(
        children: [
          _filterBar(),
          if (!_loading && _error == null && _txs.isNotEmpty) _summary(),
          Expanded(child: _body()),
        ],
      ),
    );
  }

  Widget _summary() {
    final total = _txs.fold<num>(0, (sum, t) => sum + t.amount);
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 4),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.circular(14),
        boxShadow: const [
          BoxShadow(
            color: Color(0x330B4DA2),
            blurRadius: 12,
            offset: Offset(0, 5),
          ),
        ],
      ),
      child: Row(
        children: [
          const Icon(Icons.receipt_long_rounded, color: Colors.white, size: 20),
          const SizedBox(width: 10),
          Text(
            '${_txs.length} transaksi',
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w700,
              fontSize: 13.5,
            ),
          ),
          const Spacer(),
          const Text(
            'Total ',
            style: TextStyle(color: Colors.white70, fontSize: 12),
          ),
          Text(
            formatRupiah(total),
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w800,
              fontSize: 15,
            ),
          ),
        ],
      ),
    );
  }

  Widget _filterBar() {
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 10, 16, 8),
      padding: const EdgeInsets.all(10),
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
          Expanded(
            child: _dateChip('Dari', _start, () => _pickDate(isStart: true)),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _dateChip('Sampai', _end, () => _pickDate(isStart: false)),
          ),
          const SizedBox(width: 8),
          GestureDetector(
            onTap: _loading ? null : _load,
            child: Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                gradient: AppColors.buttonGradient,
                borderRadius: BorderRadius.circular(13),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.cyan.withValues(alpha: 0.35),
                    blurRadius: 8,
                    offset: const Offset(0, 3),
                  ),
                ],
              ),
              child: const Icon(
                Icons.refresh_rounded,
                color: Colors.white,
                size: 22,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _dateChip(String label, DateTime date, VoidCallback onTap) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: AppColors.paleBlue,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: [
            const Icon(
              Icons.calendar_today_rounded,
              size: 15,
              color: AppColors.skyBlue,
            ),
            const SizedBox(width: 8),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: const TextStyle(
                    color: AppColors.inkSoft,
                    fontSize: 10,
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  '${_two(date.day)}/${_two(date.month)}/${date.year}',
                  style: const TextStyle(
                    color: AppColors.ink,
                    fontWeight: FontWeight.w700,
                    fontSize: 13,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) return _center(_error!, isError: true, retry: _load);
    if (_txs.isEmpty) {
      return _emptyState();
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(16, 6, 16, 20),
        itemCount: _txs.length,
        itemBuilder: (context, i) => _card(_txs[i]),
      ),
    );
  }

  Widget _card(CsoTransaction tx) {
    final s = _statusStyle(tx.status);
    final time = tx.createdAt?.toLocal();
    final timeStr = time == null
        ? '-'
        : '${_two(time.day)}/${_two(time.month)} ${_two(time.hour)}:${_two(time.minute)}';
    final methodLabel = tx.method == 'CashDriver'
        ? '💵 Tunai (Supir)'
        : (tx.method == 'CashCSO' ? '💵 Tunai (Kasir)' : '🏦 QRIS');

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
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
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 42,
                      height: 42,
                      decoration: BoxDecoration(
                        color: s.accent.withValues(alpha: 0.15),
                        borderRadius: BorderRadius.circular(13),
                      ),
                      child: Icon(s.icon, color: s.accent, size: 21),
                    ),
                    const SizedBox(width: 12),
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
                              fontSize: 14,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            '$timeStr • #${tx.id}',
                            style: const TextStyle(
                              color: AppColors.inkSoft,
                              fontSize: 11.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 9,
                        vertical: 4,
                      ),
                      decoration: BoxDecoration(
                        color: s.bg,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        s.label,
                        style: TextStyle(
                          color: s.fg,
                          fontSize: 10,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(11),
                  decoration: BoxDecoration(
                    color: AppColors.background,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Flexible(
                            child: Row(
                              children: [
                                const Icon(
                                  Icons.person_rounded,
                                  size: 15,
                                  color: AppColors.inkFaint,
                                ),
                                const SizedBox(width: 5),
                                Flexible(
                                  child: Text(
                                    '${tx.driverName}${tx.driverLine != null ? ' #L${tx.driverLine}' : ''}',
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                      color: AppColors.ink,
                                      fontWeight: FontWeight.w700,
                                      fontSize: 13,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          Text(
                            formatRupiah(tx.amount),
                            style: const TextStyle(
                              color: AppColors.deepBlue,
                              fontWeight: FontWeight.w800,
                              fontSize: 15,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          const Icon(
                            Icons.phone_rounded,
                            size: 14,
                            color: AppColors.inkFaint,
                          ),
                          const SizedBox(width: 5),
                          Text(
                            tx.passengerPhone,
                            style: const TextStyle(
                              color: AppColors.inkSoft,
                              fontSize: 12,
                            ),
                          ),
                          const Spacer(),
                          Text(
                            methodLabel,
                            style: const TextStyle(
                              color: AppColors.inkSoft,
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                Row(children: [const Spacer(), ..._actions(tx)]),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _actions(CsoTransaction tx) {
    final widgets = <Widget>[];

    if (tx.canChangeDriver) {
      final wait = 10 - tx.minutesSinceBooking;
      final disabled = wait > 0;
      widgets.add(
        _actionBtn(
          label: disabled ? 'Tunggu ${wait}m' : 'Ganti Supir',
          icon: Icons.swap_horiz_rounded,
          color: AppColors.danger,
          onTap: disabled
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
    if (tx.hasProof) {
      widgets.add(
        _actionBtn(
          label: 'Bukti',
          icon: Icons.image_rounded,
          color: AppColors.skyBlue,
          onTap: () => _viewProof(tx.proofUrl!),
        ),
      );
    }
    widgets.add(
      _actionBtn(
        label: 'Struk',
        icon: Icons.receipt_long_rounded,
        color: AppColors.deepBlue,
        onTap: () => CsoReceiptSheet.show(context, tx.receiptData),
      ),
    );

    final spaced = <Widget>[];
    for (var i = 0; i < widgets.length; i++) {
      if (i > 0) spaced.add(const SizedBox(width: 6));
      spaced.add(widgets[i]);
    }
    return spaced;
  }

  Widget _actionBtn({
    required String label,
    required IconData icon,
    required Color color,
    required VoidCallback? onTap,
  }) {
    final enabled = onTap != null;
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
        decoration: BoxDecoration(
          color: (enabled ? color : AppColors.inkFaint).withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Row(
          children: [
            Icon(icon, size: 14, color: enabled ? color : AppColors.inkFaint),
            const SizedBox(width: 4),
            Text(
              label,
              style: TextStyle(
                color: enabled ? color : AppColors.inkFaint,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
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
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 76,
            height: 76,
            decoration: BoxDecoration(
              color: AppColors.paleBlue,
              shape: BoxShape.circle,
            ),
            child: const Icon(
              Icons.receipt_long_rounded,
              size: 36,
              color: AppColors.skyBlue,
            ),
          ),
          const SizedBox(height: 16),
          const Text(
            'Belum ada transaksi',
            style: TextStyle(
              color: AppColors.ink,
              fontSize: 16,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 4),
          const Text(
            'Coba ubah rentang tanggal di atas.',
            style: TextStyle(color: AppColors.inkSoft, fontSize: 13),
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
          'Sedang Menjemput',
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

class _StatusStyle {
  final String label;
  final Color fg;
  final Color bg;
  final Color accent;
  final IconData icon;
  const _StatusStyle(this.label, this.fg, this.bg, this.accent, this.icon);
}
