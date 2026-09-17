import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/notification_service.dart';
import '../theme/app_colors.dart';
import '../utils/api_error.dart';

/// Layar order masuk bergaya panggilan telepon.
///
/// Dibuka dari dering alarm order (layar penuh saat HP terkunci) atau saat
/// push order tiba ketika aplikasi terbuka. Satu tujuan saja: membuat supir
/// yang baru kembali ke mobil menekan "SAYA JEMPUT" secepat mungkin — tombol
/// besar di jangkauan jempol, sekali tekan, tanpa dialog.
class IncomingOrderScreen extends StatefulWidget {
  const IncomingOrderScreen({super.key});

  /// Hindari menumpuk layar yang sama bila push datang dua kali.
  static bool _isOpen = false;

  static Future<void> open(NavigatorState navigator) async {
    if (_isOpen) return;
    _isOpen = true;
    try {
      await navigator.push(
        PageRouteBuilder(
          opaque: true,
          transitionDuration: const Duration(milliseconds: 350),
          pageBuilder: (_, _, _) => const IncomingOrderScreen(),
          transitionsBuilder: (_, anim, _, child) => FadeTransition(
            opacity: anim,
            child: child,
          ),
        ),
      );
    } finally {
      _isOpen = false;
    }
  }

  @override
  State<IncomingOrderScreen> createState() => _IncomingOrderScreenState();
}

class _IncomingOrderScreenState extends State<IncomingOrderScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1600),
  )..repeat();

  bool _loading = true;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    HapticFeedback.heavyImpact();
    _refresh();
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  Map<String, dynamic>? get _booking {
    final b = Provider.of<AuthProvider>(context, listen: false)
        .user?['active_booking'];
    return b is Map ? b.cast<String, dynamic>() : null;
  }

  Future<void> _refresh() async {
    try {
      await Provider.of<AuthProvider>(context, listen: false).fetchProfile();
    } catch (_) {
      // Tetap tampilkan tombol — konfirmasi akan memberi pesan galat bila perlu.
    }
    if (!mounted) return;
    final b = _booking;
    // Tidak ada yang perlu dikonfirmasi lagi (sudah jemput / dialihkan).
    if (b == null || b['status'] != 'Assigned' || b['pickup_confirmed_at'] != null) {
      await NotificationService().cancelOrderAlarm();
      if (mounted) Navigator.of(context).maybePop();
      return;
    }
    setState(() => _loading = false);
  }

  Future<void> _confirm() async {
    final b = _booking;
    if (b == null || _sending) return;
    HapticFeedback.mediumImpact();
    setState(() => _sending = true);
    try {
      await ApiService().confirmPickup(b['id'] as int);
      await NotificationService().cancelOrderAlarm();
      if (!mounted) return;
      await Provider.of<AuthProvider>(context, listen: false).fetchProfile();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Karcis diterbitkan — silakan menuju penumpang'),
          backgroundColor: AppColors.success,
        ),
      );
      Navigator.of(context).pop();
    } catch (e) {
      if (!mounted) return;
      setState(() => _sending = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(apiErrorMessage(e)),
          backgroundColor: AppColors.danger,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = _booking;
    final zone = b?['zone_to']?['name'] ?? b?['manual_destination'] ?? '—';
    final price = double.tryParse('${b?['price'] ?? 0}') ?? 0;
    final rupiah = NumberFormat.currency(
      locale: 'id_ID',
      symbol: 'Rp ',
      decimalDigits: 0,
    ).format(price);
    var method = '${b?['transaction']?['method'] ?? '-'}';
    method = switch (method) {
      'CashCSO' => 'Tunai ke Kasir',
      'CashDriver' => 'Tunai ke Supir',
      _ => method,
    };

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        body: Container(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [Color(0xFF062B5C), AppColors.deepBlue, Color(0xFF0E7A57)],
              stops: [0, 0.55, 1],
            ),
          ),
          child: SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
              child: Column(
                children: [
                  Align(
                    alignment: Alignment.centerLeft,
                    child: IconButton(
                      onPressed: () => Navigator.of(context).maybePop(),
                      icon: const Icon(Icons.keyboard_arrow_down_rounded,
                          color: Colors.white70, size: 30),
                      tooltip: 'Kecilkan',
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'ORDER MASUK',
                    style: GoogleFonts.outfit(
                      color: Colors.white70,
                      fontSize: 14,
                      letterSpacing: 4,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const Spacer(),
                  _PulsingCar(animation: _pulse),
                  const Spacer(),
                  Text(
                    'Tujuan',
                    style: GoogleFonts.outfit(color: Colors.white60, fontSize: 14),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '$zone',
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.outfit(
                      color: Colors.white,
                      fontSize: 34,
                      height: 1.1,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 18),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      _Chip(icon: Icons.payments_rounded, label: rupiah),
                      const SizedBox(width: 10),
                      _Chip(icon: Icons.account_balance_wallet_rounded, label: method),
                    ],
                  ),
                  const Spacer(flex: 2),
                  Text(
                    'Tekan saat Anda mulai menuju penumpang.\nKarcis langsung dicetak di konter CSO.',
                    textAlign: TextAlign.center,
                    style: GoogleFonts.outfit(
                      color: Colors.white70,
                      fontSize: 13.5,
                      height: 1.4,
                    ),
                  ),
                  const SizedBox(height: 16),
                  _PickupButton(
                    loading: _loading || _sending,
                    onPressed: _loading ? null : _confirm,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _PulsingCar extends StatelessWidget {
  final Animation<double> animation;
  const _PulsingCar({required this.animation});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 220,
      height: 220,
      child: AnimatedBuilder(
        animation: animation,
        builder: (context, _) => Stack(
          alignment: Alignment.center,
          children: [
            for (final offset in const [0.0, 0.5])
              Builder(builder: (_) {
                final t = (animation.value + offset) % 1.0;
                return Container(
                  width: 110 + 110 * t,
                  height: 110 + 110 * t,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: AppColors.success.withValues(alpha: 0.35 * (1 - t)),
                  ),
                );
              }),
            Container(
              width: 110,
              height: 110,
              decoration: const BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(
                  colors: [Color(0xFF2BD49A), AppColors.success],
                ),
                boxShadow: [
                  BoxShadow(color: Color(0x6618B27E), blurRadius: 30),
                ],
              ),
              child: const Icon(Icons.local_taxi_rounded,
                  color: Colors.white, size: 56),
            ),
          ],
        ),
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  final IconData icon;
  final String label;
  const _Chip({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    return Flexible(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(30),
          border: Border.all(color: Colors.white24),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, color: Colors.white, size: 17),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                label,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.outfit(
                  color: Colors.white,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PickupButton extends StatelessWidget {
  final bool loading;
  final VoidCallback? onPressed;
  const _PickupButton({required this.loading, required this.onPressed});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 76,
      child: DecoratedBox(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(40),
          gradient: const LinearGradient(
            colors: [Color(0xFF2BD49A), AppColors.success],
          ),
          boxShadow: const [
            BoxShadow(
              color: Color(0x8018B27E),
              blurRadius: 24,
              offset: Offset(0, 10),
            ),
          ],
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(40),
            onTap: loading ? null : onPressed,
            child: Center(
              child: loading
                  ? const SizedBox(
                      width: 28,
                      height: 28,
                      child: CircularProgressIndicator(
                        color: Colors.white,
                        strokeWidth: 3,
                      ),
                    )
                  : Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(Icons.directions_car_filled_rounded,
                            color: Colors.white, size: 30),
                        const SizedBox(width: 12),
                        Text(
                          'SAYA JEMPUT',
                          style: GoogleFonts.outfit(
                            color: Colors.white,
                            fontSize: 24,
                            fontWeight: FontWeight.w800,
                            letterSpacing: 1.5,
                          ),
                        ),
                      ],
                    ),
            ),
          ),
        ),
      ),
    );
  }
}
