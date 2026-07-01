import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:google_fonts/google_fonts.dart';

import 'package:permission_handler/permission_handler.dart';
import 'package:intl/intl.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../utils/api_error.dart';
import '../theme/app_colors.dart';
import '../widgets/sky_header.dart';
import '../widgets/app_card.dart';
import '../widgets/gradient_button.dart';
import '../widgets/fade_in.dart';

import 'package:flutter_background_service/flutter_background_service.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  final ApiService _apiService = ApiService();
  Timer? _locationTimer;
  Timer? _countdownTimer; // Local countdown timer
  Timer? _windowTimer; // cek berkala: nyalakan ulang layanan saat masuk jam operasi
  bool _trackingClosed = false; // true = di luar jam operasi (GPS dimatikan)
  String _locationStatus = "Menunggu GPS...";
  bool _isInArea = false;
  int? _remainingTimeSeconds; // Grace Period Timer
  int? _pickupTimeSeconds; // Pickup Timer

  // --- Location State ---
  double? _lastLat;
  double? _lastLng;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _startLocationService();
    _startCountdownTimer();
    // Setiap 60 dtk: bila layanan mati TAPI sudah masuk jam operasi lagi,
    // nyalakan ulang (mis. app dibiarkan terbuka melewati jam buka).
    _windowTimer = Timer.periodic(
      const Duration(seconds: 60),
      (_) => _ensureTrackingIfWithinHours(),
    );
  }

  // Saat app kembali ke depan: evaluasi ulang jam operasi & nyalakan layanan
  // bila perlu (mis. supir buka app untuk memulai shift).
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _ensureTrackingIfWithinHours();
    }
  }

  // Nyalakan ulang layanan lokasi bila mati DAN sekarang di dalam jam operasi.
  Future<void> _ensureTrackingIfWithinHours() async {
    if (!mounted) return;
    if (!_withinLocalOperatingHours()) return; // masih di luar jam → biarkan mati
    final service = FlutterBackgroundService();
    if (await service.isRunning()) return; // sudah jalan
    await _startLocationService();
  }

  // Cek jam operasi memakai waktu LOKAL perangkat (WITA utk driver Samarinda),
  // berdasarkan jam yang dikirim server di profil. Tidak tahu / format aneh →
  // true (fail-open) agar tak pernah mengunci supir.
  bool _withinLocalOperatingHours() {
    try {
      final auth = Provider.of<AuthProvider>(context, listen: false);
      final oh = auth.user?['operating_hours'];
      if (oh is! Map) return true;
      final s = _hhmmToMin('${oh['start'] ?? ''}');
      final e = _hhmmToMin('${oh['end'] ?? ''}');
      if (s == null || e == null || s == e) return true;
      final now = TimeOfDay.now();
      final n = now.hour * 60 + now.minute;
      return s < e ? (n >= s && n < e) : (n >= s || n < e);
    } catch (_) {
      return true;
    }
  }

  int? _hhmmToMin(String v) {
    final p = v.split(':');
    if (p.length != 2) return null;
    final h = int.tryParse(p[0]);
    final m = int.tryParse(p[1]);
    if (h == null || m == null) return null;
    return h * 60 + m;
  }

  // Modifikasi _processLocationUpdate
  void _processLocationUpdate(Map<String, dynamic> data) {
    final authProvider = Provider.of<AuthProvider>(context, listen: false);

    final inArea = data['in_area'] ?? false;
    var remainingRaw = data['remaining_time'];
    int? remaining;
    if (remainingRaw != null) {
      remaining = int.tryParse(remainingRaw.toString());
    }
    final statusResp = data['status'];
    // Di luar jam operasi (& tidak mengantar) → layanan akan mati; tandai UI.
    final closed = data['tracking_open'] == false && statusResp != 'ontrip';

    // Simpan Lat/Lng
    double? lat, lng;
    if (data['latitude'] != null) {
      lat = double.tryParse(data['latitude'].toString());
    }
    if (data['longitude'] != null) {
      lng = double.tryParse(data['longitude'].toString());
    }

    if (mounted) {
      setState(() {
        _trackingClosed = closed;
        _locationStatus = closed
            ? "Di luar jam operasi — pelacakan nonaktif"
            : "GPS: ${TimeOfDay.now().format(context)} (Background)";
        _isInArea = inArea;
        _remainingTimeSeconds = remaining;
        _lastLat = lat;
        _lastLng = lng;
      });

      // Auto-refresh profile if auto-joined
      if (statusResp == 'standby' &&
          authProvider.user?['driver_profile']['status'] == 'offline') {
        authProvider.fetchProfile();
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text("Anda memasuki area. Otomatis masuk antrian!"),
          ),
        );
      }

      // Auto-kick notification
      if (statusResp == 'offline' &&
          authProvider.user?['driver_profile']['status'] == 'standby') {
        authProvider.fetchProfile();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(data['message'] ?? "Antrian Hangus"),
            backgroundColor: AppColors.danger,
          ),
        );
      }
    }
  }

  // --- LOGIC MANUAL JOIN ---
  Future<void> _joinQueue() async {
    if (_lastLat == null || _lastLng == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text("Lokasi belum ditemukan. Tunggu sebentar..."),
        ),
      );
      return;
    }

    final auth = Provider.of<AuthProvider>(context, listen: false);
    try {
      // 1. Panggil API Join
      await _apiService.setStatus(
        'join',
        latitude: _lastLat!,
        longitude: _lastLng!,
      );

      // 2. Refresh Profile
      await auth.fetchProfile();

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text("Berhasil masuk antrian!")),
        );
      }
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

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _locationTimer?.cancel();
    _countdownTimer?.cancel();
    _windowTimer?.cancel();
    _serviceSubscription?.cancel();
    super.dispose();
  }

  void _startCountdownTimer() {
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (mounted) {
        setState(() {
          // 1. Grace Period Timer
          if (_remainingTimeSeconds != null && _remainingTimeSeconds! > 0) {
            _remainingTimeSeconds = _remainingTimeSeconds! - 1;
          }
          // 2. Pickup Timer
          if (_pickupTimeSeconds != null && _pickupTimeSeconds! > 0) {
            _pickupTimeSeconds = _pickupTimeSeconds! - 1;
          }
        });
      }
    });
  }

  // Listen to background service
  StreamSubscription? _serviceSubscription;

  Future<void> _startLocationService() async {
    var status = await Permission.location.request();
    if (status.isGranted) {
      // OEM agresif (MIUI/Redmi) sering membunuh service latar → minta pengecualian
      // optimasi baterai agar tracking tetap hidup saat layar mati.
      if (!await Permission.ignoreBatteryOptimizations.isGranted) {
        await Permission.ignoreBatteryOptimizations.request();
      }

      final service = FlutterBackgroundService();

      // Ensure service is running
      if (!await service.isRunning()) {
        service.startService();
      }

      // Trigger start tracking (in case it paused)
      service.invoke('startTracking');

      // Listen for updates (batalkan langganan lama agar tak dobel saat
      // layanan dinyalakan ulang lintas jam operasi).
      _serviceSubscription?.cancel();
      _serviceSubscription = service.on('update').listen((data) {
        if (data != null) {
          _processLocationUpdate(data);
        }
      });
    } else {
      if (mounted) setState(() => _locationStatus = "Izin GPS Ditolak");
    }
  }

  Future<void> _startTrip(int bookingId) async {
    final auth = Provider.of<AuthProvider>(context, listen: false);
    try {
      await _apiService.startBooking(bookingId);
      await auth.fetchProfile();
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text("Perjalanan Dimulai!")));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(apiErrorMessage(e))),
        );
      }
    }
  }

  Future<void> _completeTrip(int bookingId) async {
    final auth = Provider.of<AuthProvider>(context, listen: false);
    try {
      await _apiService.completeBooking(bookingId);
      await auth.fetchProfile();
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text("Perjalanan Selesai!")));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(apiErrorMessage(e))),
        );
      }
    }
  }

  Future<void> _showSelfPassengerDialog() async {
    final destController = TextEditingController();
    final priceController = TextEditingController();
    bool isLoading = false;

    // Capture Providers from the parent context (HomeScreen)
    final api = Provider.of<ApiService>(context, listen: false);
    final auth = Provider.of<AuthProvider>(context, listen: false);

    await showDialog(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (stfContext, setState) {
          return AlertDialog(
            title: Row(
              children: [
                const Icon(Icons.person_pin_circle_rounded,
                    color: AppColors.cyan),
                const SizedBox(width: 8),
                Text(
                  "Penumpang Sendiri",
                  style: GoogleFonts.outfit(fontWeight: FontWeight.bold),
                ),
              ],
            ),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: destController,
                  decoration: const InputDecoration(
                    labelText: "Tujuan",
                    hintText: "Misal: Hotel A",
                  ),
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: priceController,
                  decoration: const InputDecoration(
                    labelText: "Harga (Rp)",
                    hintText: "100000",
                  ),
                  keyboardType: TextInputType.number,
                ),
                const SizedBox(height: 14),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.warning.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline_rounded,
                          color: AppColors.warning, size: 18),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          "Biaya admin Rp 10.000 dicatat sebagai hutang.",
                          style: GoogleFonts.outfit(
                            fontSize: 12,
                            color: AppColors.inkSoft,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            actions: [
              TextButton(
                onPressed: isLoading
                    ? null
                    : () => Navigator.pop(dialogContext),
                child: Text(
                  "Batal",
                  style: GoogleFonts.outfit(color: AppColors.inkSoft),
                ),
              ),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.skyBlue,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                onPressed: isLoading
                    ? null
                    : () async {
                        if (destController.text.isEmpty ||
                            priceController.text.isEmpty) {
                          return;
                        }
                        setState(() => isLoading = true);
                        try {
                          await api.setStatus(
                            'leave',
                            reason: 'self',
                            manualDestination: destController.text,
                            manualPrice:
                                int.tryParse(priceController.text) ?? 0,
                          );
                          if (mounted) {
                            Navigator.pop(
                              dialogContext,
                            ); // Close using dialogContext
                            await auth.fetchProfile();
                          }
                        } catch (e) {
                          setState(() => isLoading = false);
                          if (mounted) {
                            // Use PARENT context for SnackBar
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(content: Text(apiErrorMessage(e))),
                            );
                          }
                        }
                      },
                child: isLoading
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : const Text("Mulai Jalan"),
              ),
            ],
          );
        },
      ),
    );
  }

  String _formatDuration(int totalSeconds) {
    if (totalSeconds < 0) return "00:00";
    final minutes = totalSeconds ~/ 60;
    final seconds = totalSeconds % 60;
    return "${minutes.toString().padLeft(2, '0')}:${seconds.toString().padLeft(2, '0')}";
  }

  // Metadata status (warna, label, ikon) sesuai kondisi driver.
  ({Color color, String label, IconData icon}) _statusMeta(String status) {
    if (status == 'offline') {
      if (!_isInArea) {
        return (
          color: AppColors.warning,
          label: 'DI LUAR AREA',
          icon: Icons.location_off_rounded,
        );
      }
      return (
        color: AppColors.danger,
        label: 'TIDAK AKTIF',
        icon: Icons.pause_circle_filled_rounded,
      );
    } else if (status == 'standby') {
      if (!_isInArea) {
        return (
          color: const Color(0xFFE8590C),
          label: 'PERINGATAN',
          icon: Icons.warning_amber_rounded,
        );
      }
      return (
        color: AppColors.success,
        label: 'MENUNGGU ORDER',
        icon: Icons.flight_takeoff_rounded,
      );
    }
    return (
      color: AppColors.skyBlue,
      label: 'SEDANG SIBUK',
      icon: Icons.directions_car_filled_rounded,
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = Provider.of<AuthProvider>(context);
    final user = auth.user;
    final profile = user?['driver_profile'];
    final status = profile?['status'] ?? 'offline';
    final activeBooking = user?['active_booking'];

    // --- Parse Pickup Timer if Booking Exists ---
    if (activeBooking != null && activeBooking['status'] == 'Assigned') {
      if (_pickupTimeSeconds == null) {
        final createdAtStr = activeBooking['created_at'];
        if (createdAtStr != null) {
          final created = DateTime.parse(createdAtStr);
          final now = DateTime.now();
          final diff = now.difference(created).inSeconds;
          final totalTimeout = 600; // 10 minutes

          final left = totalTimeout - diff;
          _pickupTimeSeconds = left > 0 ? left : 0;
        }
      }
    } else {
      _pickupTimeSeconds = null; // Reset if no assigned booking
    }

    final currencyFormat = NumberFormat.currency(
      locale: 'id_ID',
      symbol: 'Rp ',
      decimalDigits: 0,
    );

    final name = (user?['name'] ?? 'Driver').toString();
    final firstName = name.split(' ').first;

    return Scaffold(
      backgroundColor: AppColors.background,
      body: Column(
        children: [
          SkyHeader(
            title: 'Halo, $firstName 👋',
            subtitle: 'Semoga lancar mengangkasa hari ini',
            actions: [
              HeaderIconButton(
                icon: Icons.refresh_rounded,
                onTap: () => auth.fetchProfile(),
                tooltip: 'Segarkan',
              ),
              HeaderIconButton(
                icon: Icons.logout_rounded,
                onTap: () => auth.logout(),
                tooltip: 'Keluar',
              ),
            ],
            child: _driverHeaderCard(name, profile, status),
          ),
          Expanded(
            child: RefreshIndicator(
              color: AppColors.skyBlue,
              onRefresh: auth.fetchProfile,
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(16, 20, 16, 24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (activeBooking != null) ...[
                      FadeInUp(
                        child: _buildOrderCard(activeBooking, currencyFormat),
                      ),
                    ] else ...[
                      FadeInUp(
                        child: _buildStatusCard(
                          status,
                          profile,
                          user?['queue_position'],
                        ),
                      ),
                      const SizedBox(height: 20),
                      FadeInUp(
                        delayMs: 120,
                        child: _buildActionButtons(status),
                      ),
                    ],
                    const SizedBox(height: 26),
                    Center(child: _locationChip()),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _driverHeaderCard(
    String name,
    Map<String, dynamic>? profile,
    String status,
  ) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.white.withValues(alpha: 0.25)),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 25,
            backgroundColor: Colors.white,
            child: const Icon(Icons.person_rounded,
                color: AppColors.deepBlue, size: 28),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.outfit(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                    fontSize: 16,
                  ),
                ),
                const SizedBox(height: 3),
                Row(
                  children: [
                    const Icon(Icons.directions_car_rounded,
                        color: Colors.white70, size: 14),
                    const SizedBox(width: 4),
                    Text(
                      profile?['plate_number'] ?? '-',
                      style: GoogleFonts.outfit(
                        color: Colors.white.withValues(alpha: 0.85),
                        fontSize: 13,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          _headerStatusPill(status),
        ],
      ),
    );
  }

  Widget _headerStatusPill(String status) {
    final meta = _statusMeta(status);
    String short;
    if (status == 'standby') {
      short = _isInArea ? 'AKTIF' : 'WASPADA';
    } else if (status == 'offline') {
      short = 'NONAKTIF';
    } else {
      short = 'SIBUK';
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(30),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(color: meta.color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Text(
            short,
            style: GoogleFonts.outfit(
              color: meta.color,
              fontWeight: FontWeight.w700,
              fontSize: 11,
              letterSpacing: 0.5,
            ),
          ),
        ],
      ),
    );
  }

  Widget _locationChip() {
    final closed = _trackingClosed;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(
        color: closed ? const Color(0xFFFFF4E5) : AppColors.paleBlue,
        borderRadius: BorderRadius.circular(30),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            closed ? Icons.bedtime_rounded : Icons.gps_fixed_rounded,
            size: 14,
            color: closed ? const Color(0xFFB45309) : AppColors.cyan,
          ),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              _locationStatus,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.outfit(
                color: closed ? const Color(0xFFB45309) : AppColors.inkSoft,
                fontSize: 11,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildOrderCard(Map<String, dynamic> booking, NumberFormat currency) {
    final zoneName = booking['zone_to']?['name'] ??
        booking['manual_destination'] ??
        'Tujuan Manual';
    final price = double.tryParse(booking['price'].toString()) ?? 0;
    final csoName = booking['cso']?['name'] ?? 'Sistem';
    final passengerPhone = booking['passenger_phone'] ?? '-';
    var paymentMethod = booking['transaction']?['method'] ?? 'Tunai';
    if (paymentMethod == 'CashCSO') {
      paymentMethod = 'Tunai ke Kasir (CSO)';
    } else if (paymentMethod == 'CashDriver') {
      paymentMethod = 'Tunai ke Supir';
    }
    final isOntrip = booking['status'] == 'OnTrip';
    final accent = isOntrip ? AppColors.skyBlue : AppColors.success;

    return AppCard(
      padding: EdgeInsets.zero,
      shadow: [
        BoxShadow(
          color: accent.withValues(alpha: 0.25),
          blurRadius: 26,
          offset: const Offset(0, 12),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header strip gradien
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: isOntrip
                    ? const [AppColors.deepBlue, AppColors.skyBlue]
                    : const [Color(0xFF12A06B), AppColors.success],
              ),
              borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
            ),
            child: Row(
              children: [
                Icon(
                  isOntrip
                      ? Icons.navigation_rounded
                      : Icons.notifications_active_rounded,
                  color: Colors.white,
                ),
                const SizedBox(width: 10),
                Text(
                  isOntrip ? "DALAM PERJALANAN" : "ORDERAN MASUK",
                  style: GoogleFonts.outfit(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: Colors.white,
                    letterSpacing: 0.5,
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (!isOntrip && _pickupTimeSeconds != null)
                  _pickupTimerBox(),
                _orderRow("Tujuan", zoneName, isBold: true),
                _orderRow("Tarif", currency.format(price), isHighlight: true),
                const Divider(height: 28),
                _orderRow("Penumpang (WA)", passengerPhone),
                _orderRow("CSO", csoName),
                _orderRow("Pembayaran", paymentMethod),
                const SizedBox(height: 24),
                if (!isOntrip)
                  GradientButton(
                    label: "MULAI PERJALANAN",
                    icon: Icons.play_arrow_rounded,
                    gradient: const LinearGradient(
                      colors: [Color(0xFF12A06B), AppColors.success],
                    ),
                    onPressed: () => _startTrip(booking['id']),
                  )
                else
                  GradientButton(
                    label: "SELESAIKAN PERJALANAN",
                    icon: Icons.check_circle_rounded,
                    gradient: const LinearGradient(
                      colors: [AppColors.deepBlue, AppColors.skyBlue],
                    ),
                    onPressed: () => _completeTrip(booking['id']),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _pickupTimerBox() {
    final urgent = _pickupTimeSeconds! < 60;
    final color = urgent ? AppColors.danger : AppColors.warning;
    return Container(
      margin: const EdgeInsets.only(bottom: 18),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Row(
        children: [
          Icon(Icons.timer_rounded, color: color),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  "Batas Waktu Jemput",
                  style: GoogleFonts.outfit(
                    color: AppColors.inkSoft,
                    fontSize: 12,
                  ),
                ),
                Text(
                  _formatDuration(_pickupTimeSeconds!),
                  style: GoogleFonts.robotoMono(
                    color: color,
                    fontWeight: FontWeight.bold,
                    fontSize: 22,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _orderRow(
    String label,
    String value, {
    bool isBold = false,
    bool isHighlight = false,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: GoogleFonts.outfit(color: AppColors.inkSoft)),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: GoogleFonts.outfit(
                color: isHighlight ? AppColors.cyan : AppColors.ink,
                fontWeight:
                    isBold || isHighlight ? FontWeight.bold : FontWeight.w500,
                fontSize: isHighlight ? 20 : 14,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStatusCard(
    String status,
    Map<String, dynamic>? profile,
    dynamic queuePosition,
  ) {
    final meta = _statusMeta(status);
    final color = meta.color;

    return AppCard(
      child: Column(
        children: [
          Row(
            children: [
              _StatusEmblem(
                color: color,
                icon: meta.icon,
                pulsing: status == 'standby' && _isInArea,
              ),
              const SizedBox(width: 18),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      "Status Anda",
                      style: GoogleFonts.outfit(
                        color: AppColors.inkSoft,
                        fontSize: 13,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      meta.label,
                      style: GoogleFonts.outfit(
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                        color: color,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),

          // Grace period countdown (standby di luar area)
          if (status == 'standby' && !_isInArea) ...[
            const Divider(height: 28),
            Text(
              "Kembali ke area dalam:",
              style: GoogleFonts.outfit(
                fontSize: 13,
                color: AppColors.inkSoft,
              ),
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 10),
              decoration: BoxDecoration(
                color: AppColors.danger.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(40),
                border: Border.all(color: AppColors.danger.withValues(alpha: 0.5)),
              ),
              child: Text(
                _remainingTimeSeconds != null
                    ? _formatDuration(_remainingTimeSeconds!)
                    : "--:--",
                style: GoogleFonts.robotoMono(
                  fontSize: 30,
                  color: AppColors.danger,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 2,
                ),
              ),
            ),
          ] else if (status == 'offline' && !_isInArea) ...[
            const Divider(height: 28),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.info_outline_rounded,
                    size: 16, color: AppColors.inkSoft),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    "Masuk area bandara untuk mulai antri",
                    style: GoogleFonts.outfit(
                      fontSize: 13,
                      color: AppColors.inkSoft,
                    ),
                  ),
                ),
              ],
            ),
          ],

          // Info antrian (saat standby)
          if (status == 'standby') ...[
            const SizedBox(height: 18),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 16),
              decoration: BoxDecoration(
                gradient: AppColors.skyGradient,
                borderRadius: BorderRadius.circular(18),
              ),
              child: Column(
                children: [
                  Text(
                    "NOMOR ANTRIAN",
                    style: GoogleFonts.outfit(
                      color: Colors.white.withValues(alpha: 0.85),
                      fontSize: 12,
                      letterSpacing: 1.5,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    "#${queuePosition ?? profile?['line_number'] ?? '-'}",
                    style: GoogleFonts.outfit(
                      color: Colors.white,
                      fontSize: 34,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildActionButtons(String status) {
    if (status == 'offline') {
      if (!_isInArea) {
        return const GradientButton(
          label: "TIDAK BISA MASUK ANTRIAN",
          icon: Icons.block_rounded,
          onPressed: null,
        );
      } else {
        return GradientButton(
          label: "GABUNG ANTRIAN (MANUAL)",
          icon: Icons.login_rounded,
          onPressed: _joinQueue,
        );
      }
    } else if (status == 'standby') {
      return GradientButton(
        label: "DAPAT PENUMPANG SENDIRI",
        icon: Icons.person_add_alt_1_rounded,
        gradient: const LinearGradient(
          colors: [AppColors.deepBlue, AppColors.royalBlue],
        ),
        onPressed: _showSelfPassengerDialog,
      );
    }
    return const SizedBox.shrink();
  }
}

/// Lambang status berbentuk lingkaran; menampilkan cincin berdenyut saat
/// driver aktif menunggu order (animasi kecil + RepaintBoundary -> ringan).
class _StatusEmblem extends StatelessWidget {
  final Color color;
  final IconData icon;
  final bool pulsing;

  const _StatusEmblem({
    required this.color,
    required this.icon,
    required this.pulsing,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 64,
      height: 64,
      child: Stack(
        alignment: Alignment.center,
        children: [
          if (pulsing) RepaintBoundary(child: _PulseRing(color: color)),
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.14),
              shape: BoxShape.circle,
            ),
            child: Icon(icon, color: color, size: 28),
          ),
        ],
      ),
    );
  }
}

class _PulseRing extends StatefulWidget {
  final Color color;
  const _PulseRing({required this.color});

  @override
  State<_PulseRing> createState() => _PulseRingState();
}

class _PulseRingState extends State<_PulseRing>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1600),
  )..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (context, _) {
        final v = _c.value;
        return Opacity(
          opacity: (1 - v) * 0.5,
          child: Transform.scale(
            scale: 0.7 + v * 0.7,
            child: Container(
              width: 60,
              height: 60,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(color: widget.color, width: 2.5),
              ),
            ),
          ),
        );
      },
    );
  }
}
