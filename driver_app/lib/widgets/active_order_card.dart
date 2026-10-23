import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../theme/app_colors.dart';
import 'app_card.dart';
import 'gradient_button.dart';

/// Kartu order aktif supir di beranda: "ORDERAN MASUK" (Assigned, menunggu
/// SAYA JEMPUT) dan "DALAM PERJALANAN" (OnTrip).
///
/// Order "Tunai ke Supir" sengaja dibuat paling mencolok: uangnya ditagih
/// supir sendiri, dan lupa menagih berarti supir menombok komisi dari
/// kantongnya. Metode lain diberi tanda "jangan menagih" supaya penumpang
/// yang sudah bayar di konter tidak ditagih dua kali.
class ActiveOrderCard extends StatelessWidget {
  final Map<String, dynamic> booking;
  final NumberFormat currency;

  /// Sisa batas waktu jemput (detik); null bila tidak ada hitung mundur.
  final int? pickupSecondsLeft;
  final VoidCallback onConfirmPickup;
  final VoidCallback onComplete;

  const ActiveOrderCard({
    super.key,
    required this.booking,
    required this.currency,
    required this.pickupSecondsLeft,
    required this.onConfirmPickup,
    required this.onComplete,
  });

  static const _cashGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFFFF8F00), Color(0xFFE53935)],
  );

  bool get _isOntrip => booking['status'] == 'OnTrip';
  String get _method => '${booking['transaction']?['method'] ?? ''}';
  bool get _isCashDriver => _method == 'CashDriver';
  double get _price => double.tryParse('${booking['price']}') ?? 0;

  @override
  Widget build(BuildContext context) {
    final accent = _isCashDriver
        ? const Color(0xFFF57C00)
        : (_isOntrip ? AppColors.skyBlue : AppColors.success);

    return AppCard(
      padding: EdgeInsets.zero,
      border: _isCashDriver
          ? Border.all(color: const Color(0xFFFF8F00), width: 2)
          : null,
      shadow: [
        BoxShadow(
          color: accent.withValues(alpha: 0.28),
          blurRadius: 26,
          offset: const Offset(0, 12),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _header(),
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 18, 18, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (!_isOntrip && pickupSecondsLeft != null) ...[
                  _pickupTimerBox(pickupSecondsLeft!),
                  const SizedBox(height: 16),
                ],
                _routeBlock(),
                const SizedBox(height: 16),
                _paymentBlock(),
                const SizedBox(height: 14),
                _passengerRow(),
                const SizedBox(height: 20),
                _actionButton(context),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // === Header ==============================================================

  Widget _header() {
    final created = DateTime.tryParse('${booking['created_at'] ?? ''}')?.toLocal();
    final csoName = booking['cso']?['name'] ?? 'Sistem';
    final subtitle = [
      'Order #${booking['id']}',
      if (created != null) DateFormat('HH:mm').format(created),
      'CSO $csoName',
    ].join(' · ');

    return Container(
      padding: const EdgeInsets.fromLTRB(18, 16, 14, 16),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: _isOntrip
              ? const [AppColors.deepBlue, AppColors.skyBlue]
              : const [Color(0xFF12A06B), AppColors.success],
        ),
        borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.18),
              shape: BoxShape.circle,
            ),
            child: Icon(
              _isOntrip
                  ? Icons.navigation_rounded
                  : Icons.notifications_active_rounded,
              color: Colors.white,
              size: 22,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _isOntrip ? 'DALAM PERJALANAN' : 'ORDERAN MASUK',
                  style: GoogleFonts.outfit(
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    color: Colors.white,
                    letterSpacing: 0.6,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.outfit(
                    fontSize: 12,
                    color: Colors.white.withValues(alpha: 0.85),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          _isOntrip
              ? _TripElapsedChip(
                  startedAt: DateTime.tryParse(
                    '${booking['pickup_confirmed_at'] ?? ''}',
                  )?.toLocal(),
                )
              : _headerChip(Icons.fiber_new_rounded, 'BARU'),
        ],
      ),
    );
  }

  // === Hitung mundur jemput ================================================

  Widget _pickupTimerBox(int seconds) {
    final urgent = seconds < 60;
    final color = urgent ? AppColors.danger : AppColors.warning;
    final mm = (seconds.clamp(0, 359999) ~/ 60).toString().padLeft(2, '0');
    final ss = (seconds.clamp(0, 359999) % 60).toString().padLeft(2, '0');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Row(
        children: [
          Icon(Icons.timer_rounded, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              urgent
                  ? 'Segera tekan SAYA JEMPUT!'
                  : 'Batas waktu jemput',
              style: GoogleFonts.outfit(
                color: urgent ? color : AppColors.inkSoft,
                fontWeight: urgent ? FontWeight.w700 : FontWeight.w500,
                fontSize: 13,
              ),
            ),
          ),
          Text(
            '$mm:$ss',
            style: GoogleFonts.robotoMono(
              color: color,
              fontWeight: FontWeight.bold,
              fontSize: 22,
            ),
          ),
        ],
      ),
    );
  }

  // === Rute ================================================================

  Widget _routeBlock() {
    final zoneName = booking['zone_to']?['name'] ??
        booking['manual_destination'] ??
        'Tujuan Manual';
    final isManual = booking['zone_to'] == null;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.paleBlue,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Column(
            children: [
              _dot(AppColors.success),
              ...List.generate(
                4,
                (_) => Container(
                  width: 2,
                  height: 5,
                  margin: const EdgeInsets.symmetric(vertical: 2),
                  color: AppColors.inkFaint,
                ),
              ),
              const Icon(Icons.location_on_rounded,
                  color: AppColors.danger, size: 20),
            ],
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _label('JEMPUT'),
                Text(
                  'Konter CSO pangkalan',
                  style: GoogleFonts.outfit(
                    fontSize: 14,
                    color: AppColors.ink,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 14),
                _label(isManual ? 'TUJUAN (MANUAL)' : 'TUJUAN'),
                Text(
                  '$zoneName',
                  style: GoogleFonts.outfit(
                    fontSize: 20,
                    height: 1.15,
                    color: AppColors.ink,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // === Pembayaran ==========================================================

  Widget _paymentBlock() {
    if (_isCashDriver) {
      return _CashCollectBanner(
        amount: currency.format(_price),
        gradient: _cashGradient,
        isOntrip: _isOntrip,
      );
    }

    final (IconData icon, String title, String note) = switch (_method) {
      'QRIS' => (
          Icons.qr_code_2_rounded,
          'Lunas via QRIS',
          'Penumpang sudah membayar — jangan ditagih lagi.',
        ),
      'CashCSO' => (
          Icons.storefront_rounded,
          'Lunas di kasir CSO',
          'Penumpang sudah membayar di konter — jangan ditagih lagi.',
        ),
      _ => (
          Icons.payments_rounded,
          'Tunai',
          'Tanyakan ke CSO bila ragu soal pembayaran.',
        ),
    };

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.success.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.success.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.success.withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: AppColors.success),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        title,
                        style: GoogleFonts.outfit(
                          fontWeight: FontWeight.w700,
                          color: AppColors.ink,
                          fontSize: 14,
                        ),
                      ),
                    ),
                    const SizedBox(width: 6),
                    const Icon(Icons.verified_rounded,
                        color: AppColors.success, size: 16),
                  ],
                ),
                Text(
                  note,
                  style: GoogleFonts.outfit(
                    fontSize: 12,
                    color: AppColors.inkSoft,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Flexible(
            child: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerRight,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  _label('TARIF'),
                  Text(
                    currency.format(_price),
                    style: GoogleFonts.outfit(
                      fontSize: 17,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  // === Penumpang ===========================================================

  Widget _passengerRow() {
    final phone = '${booking['passenger_phone'] ?? ''}'.trim();
    final hasPhone = phone.isNotEmpty && phone != '-';

    return Row(
      children: [
        Container(
          width: 40,
          height: 40,
          decoration: const BoxDecoration(
            color: AppColors.paleBlue,
            shape: BoxShape.circle,
          ),
          child: const Icon(Icons.person_rounded, color: AppColors.deepBlue),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _label('PENUMPANG'),
              Text(
                hasPhone ? phone : 'Nomor tidak dicatat',
                style: GoogleFonts.outfit(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: hasPhone ? AppColors.ink : AppColors.inkFaint,
                ),
              ),
            ],
          ),
        ),
        if (hasPhone) ...[
          _roundAction(
            Icons.call_rounded,
            AppColors.skyBlue,
            () => launchUrl(Uri(scheme: 'tel', path: phone)),
          ),
          const SizedBox(width: 8),
          _roundAction(
            Icons.chat_rounded,
            const Color(0xFF25D366),
            () => launchUrl(
              Uri.parse('https://wa.me/${_waNumber(phone)}'),
              mode: LaunchMode.externalApplication,
            ),
          ),
        ],
      ],
    );
  }

  /// 0812… / +62812… → 62812… (format wa.me).
  static String _waNumber(String phone) {
    final digits = phone.replaceAll(RegExp(r'\D'), '');
    return digits.startsWith('0') ? '62${digits.substring(1)}' : digits;
  }

  // === Tombol aksi =========================================================

  Widget _actionButton(BuildContext context) {
    if (!_isOntrip) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Sekali tekan, tanpa dialog: supir memang tidak bisa menolak
          // order, jadi tidak ada salah tekan yang perlu dicegah.
          GradientButton(
            label: 'SAYA JEMPUT',
            icon: Icons.directions_car_filled_rounded,
            height: 58,
            gradient: const LinearGradient(
              colors: [Color(0xFF12A06B), AppColors.success],
            ),
            onPressed: onConfirmPickup,
          ),
          const SizedBox(height: 8),
          Text(
            'Tekan saat berangkat — perjalanan dimulai & karcis dicetak di konter CSO',
            textAlign: TextAlign.center,
            style: GoogleFonts.outfit(fontSize: 12, color: AppColors.inkSoft),
          ),
        ],
      );
    }

    return GradientButton(
      label: _isCashDriver ? 'TUNAI DITERIMA · SELESAI' : 'SELESAIKAN PERJALANAN',
      icon: _isCashDriver ? Icons.payments_rounded : Icons.check_circle_rounded,
      height: 58,
      gradient: _isCashDriver
          ? _cashGradient
          : const LinearGradient(
              colors: [AppColors.deepBlue, AppColors.skyBlue],
            ),
      onPressed: () => _isCashDriver ? _confirmCashThenComplete(context) : onComplete(),
    );
  }

  /// Pengingat terakhir sebelum order tunai ditutup: setelah "Selesai" supir
  /// biasanya langsung kembali ke antrian, penumpang sudah pergi.
  Future<void> _confirmCashThenComplete(BuildContext context) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        icon: const Icon(Icons.payments_rounded,
            color: Color(0xFFF57C00), size: 40),
        title: Text(
          'Sudah terima uang tunai?',
          textAlign: TextAlign.center,
          style: GoogleFonts.outfit(fontWeight: FontWeight.w700),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              currency.format(_price),
              style: GoogleFonts.outfit(
                fontSize: 30,
                fontWeight: FontWeight.w800,
                color: const Color(0xFFE53935),
              ),
            ),
            const SizedBox(height: 6),
            Text(
              'Pastikan penumpang sudah membayar sebelum turun.',
              textAlign: TextAlign.center,
              style: GoogleFonts.outfit(color: AppColors.inkSoft),
            ),
          ],
        ),
        actionsAlignment: MainAxisAlignment.spaceEvenly,
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Belum'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: const Color(0xFFF57C00),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Sudah, selesaikan'),
          ),
        ],
      ),
    );
    if (ok == true) onComplete();
  }

  // === Kecil-kecil =========================================================

  Widget _label(String text) => Text(
        text,
        style: GoogleFonts.outfit(
          fontSize: 10.5,
          letterSpacing: 0.8,
          fontWeight: FontWeight.w700,
          color: AppColors.inkFaint,
        ),
      );

  Widget _dot(Color color) => Container(
        width: 12,
        height: 12,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: Colors.white,
          border: Border.all(color: color, width: 3),
        ),
      );

  Widget _roundAction(IconData icon, Color color, VoidCallback onTap) {
    return Material(
      color: color.withValues(alpha: 0.12),
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Icon(icon, color: color, size: 20),
        ),
      ),
    );
  }
}

Widget _headerChip(IconData icon, String text) {
  return Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
    decoration: BoxDecoration(
      color: Colors.white.withValues(alpha: 0.2),
      borderRadius: BorderRadius.circular(20),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, color: Colors.white, size: 16),
        const SizedBox(width: 4),
        Text(
          text,
          style: GoogleFonts.outfit(
            color: Colors.white,
            fontWeight: FontWeight.w700,
            fontSize: 12,
          ),
        ),
      ],
    ),
  );
}

/// Lama perjalanan sejak SAYA JEMPUT. Punya timer sendiri (30 detik) supaya
/// beranda — yang memuat peta — tidak ikut dibangun ulang.
class _TripElapsedChip extends StatefulWidget {
  final DateTime? startedAt;
  const _TripElapsedChip({required this.startedAt});

  @override
  State<_TripElapsedChip> createState() => _TripElapsedChipState();
}

class _TripElapsedChipState extends State<_TripElapsedChip> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final start = widget.startedAt;
    if (start == null) return _headerChip(Icons.timer_outlined, 'Jalan');
    final m = DateTime.now().difference(start).inMinutes.clamp(0, 5999);
    final text = m < 60 ? '$m mnt' : '${m ~/ 60} j ${m % 60} m';
    return _headerChip(Icons.timer_outlined, text);
  }
}

/// Banner "TAGIH TUNAI" untuk order Tunai ke Supir — ikon berdenyut pelan
/// supaya tertangkap mata walau supir hanya melirik ponsel di dasbor.
class _CashCollectBanner extends StatefulWidget {
  final String amount;
  final Gradient gradient;
  final bool isOntrip;

  const _CashCollectBanner({
    required this.amount,
    required this.gradient,
    required this.isOntrip,
  });

  @override
  State<_CashCollectBanner> createState() => _CashCollectBannerState();
}

class _CashCollectBannerState extends State<_CashCollectBanner>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: BoxDecoration(
        gradient: widget.gradient,
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFFE53935).withValues(alpha: 0.35),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              RepaintBoundary(
                child: ScaleTransition(
                  scale: Tween(begin: 0.88, end: 1.08).animate(
                    CurvedAnimation(parent: _pulse, curve: Curves.easeInOut),
                  ),
                  child: Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.15),
                          blurRadius: 8,
                        ),
                      ],
                    ),
                    child: const Icon(
                      Icons.payments_rounded,
                      color: Color(0xFFE53935),
                      size: 26,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'TAGIH TUNAI KE PENUMPANG',
                      style: GoogleFonts.outfit(
                        color: Colors.white,
                        fontWeight: FontWeight.w900,
                        fontSize: 15,
                        letterSpacing: 0.5,
                      ),
                    ),
                    Text(
                      'Belum dibayar — uang diterima oleh Anda',
                      style: GoogleFonts.outfit(
                        color: Colors.white.withValues(alpha: 0.9),
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              widget.amount,
              style: GoogleFonts.outfit(
                color: Colors.white,
                fontWeight: FontWeight.w900,
                fontSize: 34,
                height: 1.1,
              ),
            ),
          ),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: Colors.black.withValues(alpha: 0.18),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(
              children: [
                const Icon(Icons.info_outline_rounded,
                    color: Colors.white, size: 16),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    widget.isOntrip
                        ? 'Terima uangnya sebelum penumpang turun.'
                        : 'Ingatkan penumpang: bayar tunai ke supir.',
                    style: GoogleFonts.outfit(
                      color: Colors.white,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
