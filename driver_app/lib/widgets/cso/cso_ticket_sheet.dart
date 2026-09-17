import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../services/api_service.dart';
import '../../services/notification_service.dart';
import '../../services/receipt_service.dart';
import '../../services/thermal_printer_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/api_error.dart';
import '../../utils/format.dart';
import 'cso_change_driver_sheet.dart';
import 'cso_printer_picker_sheet.dart';

enum _Phase { waiting, late, ready }

/// Karcis order: terbit saat supir menekan "SAYA JEMPUT".
///
/// Tiga keadaan dalam satu sheet supaya CSO tidak pernah berpindah layar:
///  1. MENUNGGU  — supir belum berangkat; karcis terkunci. Nomor lambung &
///                 plat besar supaya bisa dipanggil lewat pengeras suara.
///  2. TERLAMBAT — lewat [lateAfter]; muncul "Telepon" & "Ganti Supir".
///  3. SIAP      — pratinjau karcis + dua cara menyerahkan: cetak struk kasir
///                 (utama, otomatis sekali bila printer tersimpan) atau kirim
///                 ke WhatsApp penumpang.
///
/// Pembaruan datang dari push `ticket_ready` dan, sebagai cadangan, polling
/// `/cso/pending-tickets` selama sheet terbuka.
class CsoTicketSheet extends StatefulWidget {
  final Map<String, dynamic> booking;
  const CsoTicketSheet({super.key, required this.booking});

  static const Duration lateAfter = Duration(seconds: 90);
  static const Duration _pollEvery = Duration(seconds: 5);

  static Future<void> show(BuildContext context, Map<String, dynamic> booking) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => CsoTicketSheet(booking: booking),
    );
  }

  @override
  State<CsoTicketSheet> createState() => _CsoTicketSheetState();
}

class _CsoTicketSheetState extends State<CsoTicketSheet>
    with SingleTickerProviderStateMixin {
  final _api = ApiService();
  late Map<String, dynamic> _b = widget.booking;
  late DateTime _waitingSince;

  Timer? _poll;
  Timer? _tick;
  StreamSubscription? _push;

  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1500),
  )..repeat();

  bool _printing = false;
  bool _printed = false;
  bool _autoPrintTried = false;
  bool _sendingWa = false;
  bool _announcedReady = false;

  @override
  void initState() {
    super.initState();
    _waitingSince =
        DateTime.tryParse('${_b['created_at'] ?? ''}')?.toLocal() ??
            DateTime.now();

    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && _phase != _Phase.ready) setState(() {});
    });
    _poll = Timer.periodic(CsoTicketSheet._pollEvery, (_) => _refresh());
    _push = NotificationService().events.listen((e) {
      if (e['type'] == 'ticket_ready' && e['booking_id'] == '${_b['id']}') {
        _refresh();
      }
    });
    if (_phase == _Phase.ready) _onReady();
  }

  @override
  void dispose() {
    _poll?.cancel();
    _tick?.cancel();
    _push?.cancel();
    _pulse.dispose();
    super.dispose();
  }

  // ---- data -------------------------------------------------------------

  Map<String, dynamic> _m(dynamic v) =>
      (v as Map?)?.cast<String, dynamic>() ?? const {};
  String _s(dynamic v, [String fb = '-']) =>
      (v == null || '$v'.isEmpty) ? fb : '$v';

  Map<String, dynamic> get _driver => _m(_b['driver']);
  Map<String, dynamic> get _profile => _m(_driver['driver_profile']);
  bool get _isReady => _b['pickup_confirmed_at'] != null;

  _Phase get _phase {
    if (_isReady) return _Phase.ready;
    return DateTime.now().difference(_waitingSince) >= CsoTicketSheet.lateAfter
        ? _Phase.late
        : _Phase.waiting;
  }

  Future<void> _refresh() async {
    try {
      final res = await _api.getCsoPendingTickets();
      final list = (res.data['data'] as List?) ?? const [];
      final fresh = list
          .map((e) => (e as Map).cast<String, dynamic>())
          .where((e) => e['id'] == _b['id'])
          .firstOrNull;
      if (fresh == null || !mounted) return;

      final wasReady = _isReady;
      final driverChanged = fresh['driver_id'] != _b['driver_id'];
      setState(() {
        _b = fresh;
        if (driverChanged) _waitingSince = DateTime.now();
      });
      if (!wasReady && _isReady) _onReady();
    } catch (_) {
      // Sinyal konter buruk: coba lagi di putaran polling berikutnya.
    }
  }

  Future<void> _onReady() async {
    _poll?.cancel();
    if (!_announcedReady) {
      _announcedReady = true;
      HapticFeedback.heavyImpact();
      SystemSound.play(SystemSoundType.alert);
    }
    if (_autoPrintTried) return;
    _autoPrintTried = true;
    final mac = await ThermalPrinterService.savedMac();
    if (mac != null && mac.isNotEmpty && mounted) {
      await _print(auto: true);
    }
  }

  // ---- aksi -------------------------------------------------------------

  Future<void> _print({bool auto = false}) async {
    if (_printing) return;
    final messenger = ScaffoldMessenger.of(context);
    try {
      final mac = await ThermalPrinterService.savedMac();
      if (mac == null || mac.isEmpty) {
        if (!mounted) return;
        final picked = await CsoPrinterPickerSheet.show(context);
        if (picked != true) return;
      }
      setState(() => _printing = true);
      await ThermalPrinterService.printReceipt(_b);
      if (!mounted) return;
      setState(() {
        _printing = false;
        _printed = true;
      });
      messenger.showSnackBar(SnackBar(
        content: Text(auto ? 'Karcis tercetak otomatis ✓' : 'Karcis tercetak ✓'),
        backgroundColor: AppColors.success,
      ));
    } catch (e) {
      if (!mounted) return;
      setState(() => _printing = false);
      messenger.showSnackBar(SnackBar(
        content: Text(e.toString()),
        backgroundColor: AppColors.danger,
      ));
    }
  }

  Future<void> _sendWa() async {
    if (_sendingWa) return;
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _sendingWa = true);
    try {
      final res = await _api.csoSendTicketWhatsApp(_b['id'] as int);
      final data = (res.data['data'] as Map?)?.cast<String, dynamic>();
      if (!mounted) return;
      setState(() {
        if (data != null) _b = data;
        _sendingWa = false;
      });
      messenger.showSnackBar(const SnackBar(
        content: Text('Karcis terkirim ke WhatsApp penumpang ✓'),
        backgroundColor: AppColors.success,
      ));
    } catch (e) {
      if (!mounted) return;
      setState(() => _sendingWa = false);
      messenger.showSnackBar(SnackBar(
        content: Text(apiErrorMessage(e)),
        backgroundColor: AppColors.danger,
      ));
    }
  }

  Future<void> _callDriver() async {
    final phone = _s(_driver['phone_number'], '');
    if (phone.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Nomor HP supir belum terdaftar.'),
      ));
      return;
    }
    await launchUrl(Uri(scheme: 'tel', path: phone));
  }

  Future<void> _changeDriver() async {
    final changed = await CsoChangeDriverSheet.show(context, _b['id'] as int);
    if (changed == true) {
      _poll?.cancel();
      _poll = Timer.periodic(CsoTicketSheet._pollEvery, (_) => _refresh());
      await _refresh();
    }
  }

  // ---- tampilan ---------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final phase = _phase;
    return DraggableScrollableSheet(
      initialChildSize: 0.9,
      minChildSize: 0.5,
      maxChildSize: 0.96,
      expand: false,
      builder: (context, controller) => Container(
        decoration: const BoxDecoration(
          color: AppColors.background,
          borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
        ),
        child: ListView(
          controller: controller,
          padding: const EdgeInsets.fromLTRB(18, 10, 18, 28),
          children: [
            Center(
              child: Container(
                width: 44,
                height: 5,
                decoration: BoxDecoration(
                  color: AppColors.inkFaint.withValues(alpha: 0.4),
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
            ),
            const SizedBox(height: 14),
            AnimatedSwitcher(
              duration: const Duration(milliseconds: 450),
              switchInCurve: Curves.easeOutBack,
              transitionBuilder: (child, anim) => FadeTransition(
                opacity: anim,
                child: ScaleTransition(
                  scale: Tween(begin: 0.96, end: 1.0).animate(anim),
                  child: child,
                ),
              ),
              child: phase == _Phase.ready
                  ? _readyView(key: const ValueKey('ready'))
                  : _waitingView(phase, key: const ValueKey('waiting')),
            ),
          ],
        ),
      ),
    );
  }

  // ---- 1 & 2: menunggu supir --------------------------------------------

  Widget _waitingView(_Phase phase, {Key? key}) {
    final isLate = phase == _Phase.late;
    final accent = isLate ? AppColors.warning : AppColors.skyBlue;
    final elapsed = DateTime.now().difference(_waitingSince);
    String two(int n) => n.toString().padLeft(2, '0');
    final timer = '${two(elapsed.inMinutes)}:${two(elapsed.inSeconds % 60)}';
    final line = _profile['line_number'];

    return Column(
      key: key,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          padding: const EdgeInsets.fromLTRB(20, 22, 20, 22),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: isLate
                  ? const [Color(0xFFE8890C), AppColors.warning]
                  : const [AppColors.deepBlue, AppColors.skyBlue],
            ),
            borderRadius: BorderRadius.circular(24),
            boxShadow: [
              BoxShadow(
                color: accent.withValues(alpha: 0.35),
                blurRadius: 22,
                offset: const Offset(0, 10),
              ),
            ],
          ),
          child: Column(
            children: [
              _pulsingIcon(
                isLate ? Icons.priority_high_rounded : Icons.local_taxi_rounded,
              ),
              const SizedBox(height: 14),
              Text(
                isLate ? 'Supir Belum Berangkat' : 'Menunggu Supir Berangkat',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 19,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                isLate
                    ? 'Panggil nomor lambung, telepon, atau ganti supir'
                    : 'Karcis terbit otomatis saat supir menekan SAYA JEMPUT',
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.85),
                  fontSize: 13,
                ),
              ),
              const SizedBox(height: 14),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.18),
                  borderRadius: BorderRadius.circular(30),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.timer_outlined,
                        color: Colors.white, size: 18),
                    const SizedBox(width: 6),
                    Text(
                      timer,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.w700,
                        fontFeatures: [FontFeature.tabularFigures()],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        _card(
          child: Row(
            children: [
              Container(
                width: 74,
                height: 74,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(18),
                ),
                child: FittedBox(
                  child: Padding(
                    padding: const EdgeInsets.all(8),
                    child: Text(
                      line == null ? '—' : '#L$line',
                      style: TextStyle(
                        color: isLate ? const Color(0xFFB86A00) : AppColors.deepBlue,
                        fontSize: 26,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _s(_driver['name']),
                      style: const TextStyle(
                        color: AppColors.ink,
                        fontSize: 17,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    _plateBadge(_s(_profile['plate_number'])),
                    const SizedBox(height: 6),
                    Text(
                      'Bandara → ${_s(_m(_b['zone_to'])['name'])}',
                      style: const TextStyle(
                        color: AppColors.inkSoft,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        _lockedTicketHint(),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: _outlineAction(
                icon: Icons.call_rounded,
                label: 'Telepon Supir',
                color: isLate ? const Color(0xFFB86A00) : AppColors.deepBlue,
                onTap: _callDriver,
              ),
            ),
            if (isLate) ...[
              const SizedBox(width: 10),
              Expanded(
                child: _filledAction(
                  icon: Icons.swap_horiz_rounded,
                  label: 'Ganti Supir',
                  colors: const [Color(0xFFE8890C), AppColors.warning],
                  onTap: _changeDriver,
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 10),
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          style: TextButton.styleFrom(foregroundColor: AppColors.inkSoft),
          child: const Text('Tutup — karcis tetap muncul di Dashboard'),
        ),
      ],
    );
  }

  Widget _pulsingIcon(IconData icon) {
    return SizedBox(
      width: 96,
      height: 96,
      child: AnimatedBuilder(
        animation: _pulse,
        builder: (_, _) => Stack(
          alignment: Alignment.center,
          children: [
            for (final o in const [0.0, 0.5])
              Builder(builder: (_) {
                final t = (_pulse.value + o) % 1.0;
                return Container(
                  width: 56 + 40 * t,
                  height: 56 + 40 * t,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Colors.white.withValues(alpha: 0.28 * (1 - t)),
                  ),
                );
              }),
            Container(
              width: 58,
              height: 58,
              decoration: const BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white,
              ),
              child: Icon(icon, color: AppColors.deepBlue, size: 32),
            ),
          ],
        ),
      ),
    );
  }

  Widget _lockedTicketHint() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.paleBlue,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.skyBlue.withValues(alpha: 0.2)),
      ),
      child: Row(
        children: [
          const Icon(Icons.lock_clock_rounded, color: AppColors.skyBlue),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              'Cetak struk & kirim WhatsApp terbuka setelah supir berangkat — '
              'supaya karcis pasti menunjuk mobil yang datang.',
              style: TextStyle(
                color: AppColors.ink.withValues(alpha: 0.75),
                fontSize: 12.5,
                height: 1.35,
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ---- 3: karcis siap -----------------------------------------------------

  Widget _readyView({Key? key}) {
    final zone = _m(_b['zone_to']);
    final tx = _m(_b['transaction']);
    final cso = _m(_b['cso']);
    final method = _s(tx['method'], 'QRIS');
    final methodLabel = method == 'CashDriver'
        ? 'Tunai (Supir)'
        : (method == 'CashCSO' ? 'Tunai (Kasir)' : 'QRIS');
    final price = num.tryParse('${_b['price'] ?? 0}') ?? 0;
    final pickup =
        DateTime.tryParse('${_b['pickup_confirmed_at'] ?? ''}')?.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    final pickupStr =
        pickup == null ? '-' : '${two(pickup.hour)}:${two(pickup.minute)}';
    final line = _profile['line_number'];
    final phone = _s(_b['passenger_phone'], '');
    final waSent = _b['ticket_wa_sent_at'] != null;

    return Column(
      key: key,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              padding: const EdgeInsets.all(6),
              decoration: const BoxDecoration(
                color: Color(0x2618B27E),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.check_rounded,
                  color: AppColors.success, size: 20),
            ),
            const SizedBox(width: 10),
            const Text(
              'Karcis Siap Diserahkan',
              style: TextStyle(
                color: AppColors.ink,
                fontSize: 19,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'Supir berangkat menjemput pukul $pickupStr',
          textAlign: TextAlign.center,
          style: const TextStyle(color: AppColors.inkSoft, fontSize: 13),
        ),
        const SizedBox(height: 16),

        // --- karcis ---
        PhysicalShape(
          clipper: const _TicketClipper(notchY: 218),
          color: AppColors.surface,
          elevation: 6,
          shadowColor: const Color(0x330B4DA2),
          child: Column(
            children: [
              Container(
                height: 218,
                padding: const EdgeInsets.fromLTRB(20, 18, 20, 16),
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [AppColors.deepBlue, AppColors.skyBlue],
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.local_taxi_rounded,
                            color: Colors.white, size: 18),
                        const SizedBox(width: 6),
                        const Text(
                          'KARCIS TAKSI',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 12,
                            letterSpacing: 2.5,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const Spacer(),
                        Text(
                          'No. ${_s(tx['id'] ?? _b['id'])}',
                          style: TextStyle(
                            color: Colors.white.withValues(alpha: 0.8),
                            fontSize: 12,
                          ),
                        ),
                      ],
                    ),
                    const Spacer(),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text(
                        _s(_profile['plate_number']).toUpperCase(),
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 38,
                          fontWeight: FontWeight.w900,
                          letterSpacing: 1.5,
                        ),
                      ),
                    ),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        if (line != null) ...[
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 3),
                            decoration: BoxDecoration(
                              color: AppColors.gold,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Text(
                              '#L$line',
                              style: const TextStyle(
                                color: AppColors.ink,
                                fontWeight: FontWeight.w900,
                                fontSize: 15,
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                        ],
                        Expanded(
                          child: Text(
                            _s(_driver['name']),
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const Spacer(),
                    Row(
                      children: [
                        const Text(
                          'BANDARA',
                          style: TextStyle(
                            color: Colors.white70,
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        Expanded(
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 8),
                            child: Row(
                              children: [
                                Expanded(
                                  child: CustomPaint(
                                    painter: _DashPainter(Colors.white54),
                                    size: const Size(double.infinity, 1),
                                  ),
                                ),
                                const Icon(Icons.flight_land_rounded,
                                    color: Colors.white70, size: 16),
                              ],
                            ),
                          ),
                        ),
                        Flexible(
                          child: Text(
                            _s(zone['name']).toUpperCase(),
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 14,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              // garis sobek
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 22),
                child: CustomPaint(
                  painter: _DashPainter(AppColors.inkFaint.withValues(alpha: 0.6)),
                  size: const Size(double.infinity, 1),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 18),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          _mini('Tarif', formatRupiah(price), big: true),
                          const SizedBox(height: 10),
                          Row(
                            children: [
                              Expanded(child: _mini('Bayar', methodLabel)),
                              Expanded(child: _mini('Jemput', pickupStr)),
                            ],
                          ),
                          const SizedBox(height: 10),
                          _mini('Kasir', _s(cso['name'])),
                        ],
                      ),
                    ),
                    const SizedBox(width: 12),
                    Container(
                      padding: const EdgeInsets.all(6),
                      decoration: BoxDecoration(
                        border: Border.all(
                            color: AppColors.inkFaint.withValues(alpha: 0.3)),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: QrImageView(
                        data: ReceiptService.publicUrlFor(_b),
                        size: 92,
                        padding: EdgeInsets.zero,
                        backgroundColor: Colors.white,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        const Text(
          'Serahkan karcis ke penumpang',
          style: TextStyle(
            color: AppColors.ink,
            fontSize: 15,
            fontWeight: FontWeight.w800,
          ),
        ),
        const SizedBox(height: 10),
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: _OptionCard(
                  icon: _printed
                      ? Icons.check_circle_rounded
                      : Icons.print_rounded,
                  title: 'Cetak Struk Kasir',
                  subtitle: _printing
                      ? 'Mencetak…'
                      : (_printed ? 'Tercetak · cetak lagi' : 'Printer thermal'),
                  colors: const [AppColors.deepBlue, AppColors.skyBlue],
                  loading: _printing,
                  onTap: () => _print(),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _OptionCard(
                  icon: waSent ? Icons.check_circle_rounded : Icons.chat_rounded,
                  title: 'Kirim ke WhatsApp',
                  subtitle: phone.isEmpty
                      ? 'Nomor tidak diisi'
                      : (_sendingWa
                          ? 'Mengirim…'
                          : (waSent ? 'Terkirim · kirim ulang' : phone)),
                  colors: const [Color(0xFF128C7E), Color(0xFF25D366)],
                  loading: _sendingWa,
                  onTap: phone.isEmpty ? null : _sendWa,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Wrap(
          alignment: WrapAlignment.center,
          spacing: 4,
          children: [
            _linkButton(Icons.picture_as_pdf_rounded, 'Simpan PDF',
                () => ReceiptService.printPdf(_b)),
            _linkButton(Icons.share_rounded, 'Bagikan',
                () => ReceiptService.sharePdf(_b)),
            _linkButton(Icons.bluetooth_rounded, 'Ganti printer',
                () => CsoPrinterPickerSheet.show(context)),
          ],
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: 52,
          child: ElevatedButton(
            onPressed: () => Navigator.of(context).pop(),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.success,
              foregroundColor: Colors.white,
              elevation: 0,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
              ),
            ),
            child: const Text(
              'Selesai',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5),
            ),
          ),
        ),
      ],
    );
  }

  // ---- komponen kecil -----------------------------------------------------

  Widget _card({required Widget child}) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        boxShadow: const [
          BoxShadow(
            color: Color(0x140B4DA2),
            blurRadius: 16,
            offset: Offset(0, 6),
          ),
        ],
      ),
      child: child,
    );
  }

  Widget _plateBadge(String plate) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: AppColors.ink,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        plate.toUpperCase(),
        style: const TextStyle(
          color: Colors.white,
          fontSize: 13,
          fontWeight: FontWeight.w800,
          letterSpacing: 1,
        ),
      ),
    );
  }

  Widget _mini(String label, String value, {bool big = false}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label.toUpperCase(),
          style: const TextStyle(
            color: AppColors.inkFaint,
            fontSize: 10.5,
            letterSpacing: 1,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: big ? AppColors.deepBlue : AppColors.ink,
            fontSize: big ? 22 : 13.5,
            fontWeight: big ? FontWeight.w900 : FontWeight.w700,
          ),
        ),
      ],
    );
  }

  Widget _outlineAction({
    required IconData icon,
    required String label,
    required Color color,
    required VoidCallback onTap,
  }) {
    return SizedBox(
      height: 52,
      child: OutlinedButton.icon(
        onPressed: onTap,
        icon: Icon(icon, size: 20),
        label: Text(label, style: const TextStyle(fontWeight: FontWeight.w700)),
        style: OutlinedButton.styleFrom(
          foregroundColor: color,
          side: BorderSide(color: color.withValues(alpha: 0.45)),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
        ),
      ),
    );
  }

  Widget _filledAction({
    required IconData icon,
    required String label,
    required List<Color> colors,
    required VoidCallback onTap,
  }) {
    return SizedBox(
      height: 52,
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(colors: colors),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: onTap,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon, color: Colors.white, size: 20),
                const SizedBox(width: 8),
                Text(
                  label,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _linkButton(IconData icon, String label, VoidCallback onTap) {
    return TextButton.icon(
      onPressed: onTap,
      icon: Icon(icon, size: 16),
      label: Text(label),
      style: TextButton.styleFrom(foregroundColor: AppColors.inkSoft),
    );
  }
}

/// Kartu opsi besar untuk dua cara menyerahkan karcis.
class _OptionCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final List<Color> colors;
  final bool loading;
  final VoidCallback? onTap;

  const _OptionCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.colors,
    required this.loading,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final disabled = onTap == null;
    return Opacity(
      opacity: disabled ? 0.45 : 1,
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: disabled
                ? const [AppColors.inkFaint, AppColors.inkFaint]
                : colors,
          ),
          borderRadius: BorderRadius.circular(20),
          boxShadow: disabled
              ? null
              : [
                  BoxShadow(
                    color: colors.last.withValues(alpha: 0.35),
                    blurRadius: 16,
                    offset: const Offset(0, 8),
                  ),
                ],
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(20),
            onTap: loading ? null : onTap,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 16, 14, 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.2),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: loading
                        ? const Padding(
                            padding: EdgeInsets.all(12),
                            child: CircularProgressIndicator(
                              color: Colors.white,
                              strokeWidth: 2.5,
                            ),
                          )
                        : Icon(icon, color: Colors.white, size: 24),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    title,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    subtitle,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.85),
                      fontSize: 12,
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

/// Bentuk karcis: sudut membulat + dua lekukan setengah lingkaran di garis sobek.
class _TicketClipper extends CustomClipper<Path> {
  final double notchY;
  const _TicketClipper({required this.notchY});

  @override
  Path getClip(Size size) {
    const r = 22.0;
    const notch = 12.0;
    final card = Path()
      ..addRRect(RRect.fromRectAndRadius(Offset.zero & size, const Radius.circular(r)));
    final notches = Path()
      ..addOval(Rect.fromCircle(center: Offset(0, notchY), radius: notch))
      ..addOval(Rect.fromCircle(center: Offset(size.width, notchY), radius: notch));
    return Path.combine(PathOperation.difference, card, notches);
  }

  @override
  bool shouldReclip(covariant _TicketClipper old) => old.notchY != notchY;
}

class _DashPainter extends CustomPainter {
  final Color color;
  _DashPainter(this.color);

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 1.4;
    const dash = 6.0, gap = 5.0;
    var x = 0.0;
    while (x < size.width) {
      canvas.drawLine(Offset(x, 0), Offset(x + dash, 0), paint);
      x += dash + gap;
    }
  }

  @override
  bool shouldRepaint(covariant _DashPainter old) => old.color != color;
}
