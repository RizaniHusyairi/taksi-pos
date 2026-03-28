import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:google_fonts/google_fonts.dart';

import 'package:permission_handler/permission_handler.dart';
import 'package:intl/intl.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';

import 'package:flutter_background_service/flutter_background_service.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final ApiService _apiService = ApiService();
  Timer? _locationTimer;
  Timer? _countdownTimer; // Local countdown timer
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
    _startLocationService();
    _startCountdownTimer();
  }

  // ... (dispose and timers remain same)

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
        _locationStatus =
            "GPS: ${TimeOfDay.now().format(context)} (Background)";
        _isInArea = inArea;
        _remainingTimeSeconds = remaining;
        _lastLat = lat;
        _lastLng = lng;
      });

      // ... (Auto-refresh/Auto-kick logic remains same) ...
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
            backgroundColor: Colors.red,
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
          SnackBar(content: Text("Gagal: $e"), backgroundColor: Colors.red),
        );
      }
    }
  }

  @override
  void dispose() {
    _locationTimer?.cancel();
    _countdownTimer?.cancel();
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
      final service = FlutterBackgroundService();

      // Ensure service is running
      if (!await service.isRunning()) {
        service.startService();
      }

      // Trigger start tracking (in case it paused)
      service.invoke('startTracking');

      // Listen for updates
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
          const SnackBar(content: Text("Gagal memulai perjalanan")),
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
          const SnackBar(content: Text("Gagal menyelesaikan perjalanan")),
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
            title: Text(
              "Dapat Penumpang Sendiri",
              style: GoogleFonts.outfit(fontWeight: FontWeight.bold),
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
                TextField(
                  controller: priceController,
                  decoration: const InputDecoration(
                    labelText: "Harga (Rp)",
                    hintText: "100000",
                  ),
                  keyboardType: TextInputType.number,
                ),
                const SizedBox(height: 10),
                const Text(
                  "Biaya admin Rp 10.000 akan dicatat sebagai hutang.",
                  style: TextStyle(fontSize: 12, color: Colors.grey),
                ),
              ],
            ),
            actions: [
              TextButton(
                onPressed: isLoading
                    ? null
                    : () => Navigator.pop(dialogContext),
                child: const Text("Batal"),
              ),
              ElevatedButton(
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
                            // Use PARENT context for SnackBar (dialog context might be tricky)
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(content: Text("Error: $e")),
                            );
                          }
                        }
                      },
                child: isLoading
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
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
        // Only set once to avoid reset on refresh
        final createdAtStr = activeBooking['created_at'];
        if (createdAtStr != null) {
          final created = DateTime.parse(createdAtStr); // UTC / Server Time
          // Assuming Server Time is synced roughly, we calculate diff
          // Better approach: Server sends 'seconds_left'. But here we calc manually.
          // Note: DateTime.parse might parse as Local if no timezone info.
          // Ideally backend sends 'expires_at'.
          // Simple Fix: We assume created time is recent.

          // If created is 10:00, now is 10:02. Diff is 2 mins. Remaining 8 mins (480s).
          final now = DateTime.now();
          // Adjust timezone if needed. For now assume same timezone.
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

    return Scaffold(
      backgroundColor: const Color(0xFF1A1A1A),
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: Text(
          'DASHBOARD',
          style: GoogleFonts.outfit(
            fontWeight: FontWeight.bold,
            color: Colors.white,
          ),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: () => auth.fetchProfile(),
          ),
          IconButton(
            icon: const Icon(Icons.logout, color: Colors.white),
            onPressed: () => auth.logout(),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: auth.fetchProfile,
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // User Info Card
              Card(
                color: Colors.white10,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    children: [
                      const CircleAvatar(
                        backgroundColor: Colors.white24,
                        child: Icon(Icons.person, color: Colors.white),
                      ),
                      const SizedBox(width: 12),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            "${user?['name'] ?? 'Driver'}",
                            style: GoogleFonts.outfit(
                              fontWeight: FontWeight.bold,
                              color: Colors.white,
                            ),
                          ),
                          Text(
                            profile?['plate_number'] ?? '-',
                            style: GoogleFonts.outfit(color: Colors.white70),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              if (activeBooking != null) ...[
                _buildOrderCard(activeBooking, currencyFormat),
              ] else ...[
                _buildStatusCard(status, profile, user?['queue_position']),
                const SizedBox(height: 24),
                _buildActionButtons(status),
              ],

              const SizedBox(height: 24),
              Center(
                child: Text(
                  _locationStatus,
                  style: const TextStyle(color: Colors.white30, fontSize: 10),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildOrderCard(Map<String, dynamic> booking, NumberFormat currency) {
    final zoneName =
        booking['zone_to']?['name'] ??
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

    return Card(
      color: isOntrip
          ? Colors.blue.withOpacity(0.1)
          : const Color(0xFFD4AF37).withOpacity(0.1),
      shape: RoundedRectangleBorder(
        side: BorderSide(
          color: isOntrip ? Colors.blue : const Color(0xFFD4AF37),
          width: 2,
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  Icons.directions_car,
                  color: isOntrip ? Colors.blue : const Color(0xFFD4AF37),
                ),
                const SizedBox(width: 8),
                Text(
                  isOntrip ? "DALAM PERJALANAN" : "ORDERAN MASUK",
                  style: GoogleFonts.outfit(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: isOntrip ? Colors.blue : const Color(0xFFD4AF37),
                  ),
                ),
              ],
            ),
            const Divider(color: Colors.white24, height: 32),

            if (!isOntrip && _pickupTimeSeconds != null)
              Container(
                margin: const EdgeInsets.only(bottom: 16),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: (_pickupTimeSeconds! < 60)
                      ? Colors.red.withOpacity(0.2)
                      : Colors.orange.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(
                    color: (_pickupTimeSeconds! < 60)
                        ? Colors.red
                        : Colors.orange.withOpacity(0.5),
                  ),
                ),
                child: Row(
                  children: [
                    Icon(
                      Icons.timer,
                      color: (_pickupTimeSeconds! < 60)
                          ? Colors.red
                          : Colors.orange,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            "Waktu Jemput",
                            style: GoogleFonts.outfit(
                              color: Colors.white70,
                              fontSize: 12,
                            ),
                          ),
                          Text(
                            _formatDuration(_pickupTimeSeconds!),
                            style: GoogleFonts.robotoMono(
                              color: Colors.white,
                              fontWeight: FontWeight.bold,
                              fontSize: 18,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),

            _orderRow("Tujuan", zoneName, isBold: true),
            _orderRow("Tarif", currency.format(price), isHighlight: true),
            const SizedBox(height: 16),
            _orderRow("Penumpang (WA)", passengerPhone),
            _orderRow("CSO", csoName),
            _orderRow("Pembayaran", paymentMethod),

            const SizedBox(height: 32),

            if (!isOntrip)
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFFD4AF37),
                  foregroundColor: Colors.black,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  minimumSize: const Size(double.infinity, 50),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                onPressed: () => _startTrip(booking['id']),
                child: Text(
                  "MULAI PERJALANAN",
                  style: GoogleFonts.outfit(fontWeight: FontWeight.bold),
                ),
              )
            else
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.blue,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  minimumSize: const Size(double.infinity, 50),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                onPressed: () => _completeTrip(booking['id']),
                child: Text(
                  "SELESAIKAN PERJALANAN",
                  style: GoogleFonts.outfit(fontWeight: FontWeight.bold),
                ),
              ),
          ],
        ),
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
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: GoogleFonts.outfit(color: Colors.white70)),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: GoogleFonts.outfit(
                color: isHighlight ? const Color(0xFFD4AF37) : Colors.white,
                fontWeight: isBold || isHighlight
                    ? FontWeight.bold
                    : FontWeight.normal,
                fontSize: isHighlight ? 18 : 14,
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
    Color color;
    String text;

    // LOGIKA STATUS TEXT
    if (status == 'offline') {
      if (!_isInArea) {
        color = Colors.orange;
        text = 'DILUAR AREA';
      } else {
        color = Colors.red;
        text = 'TIDAK AKTIF';
      }
    } else if (status == 'standby') {
      // Jika Standby tapi diluar area -> WARNING GRACE PERIOD
      if (!_isInArea) {
        color = Colors.deepOrange; // Lebih gelap
        text = 'PERINGATAN';
      } else {
        color = Colors.green;
        text = 'MENUNGGU';
      }
    } else {
      color = Colors.blue;
      text = 'SIBUK';
    }

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 24),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color, width: 2),
      ),
      child: Column(
        children: [
          Text(
            text,
            style: GoogleFonts.outfit(
              fontSize: 28,
              fontWeight: FontWeight.w900,
              color: color,
              letterSpacing: 2,
            ),
          ),

          // Subtext Warning Grace Period + Live Countdown
          if (status == 'standby' && !_isInArea) ...[
            const SizedBox(height: 16),
            Text(
              "Kembali ke area dalam:",
              style: GoogleFonts.outfit(fontSize: 14, color: Colors.white70),
            ),
            const SizedBox(height: 4),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 8),
              decoration: BoxDecoration(
                color: Colors.redAccent.withOpacity(0.2),
                borderRadius: BorderRadius.circular(50),
                border: Border.all(color: Colors.redAccent),
              ),
              child: Text(
                _remainingTimeSeconds != null
                    ? _formatDuration(_remainingTimeSeconds!)
                    : "--:--",
                textAlign: TextAlign.center,
                style: GoogleFonts.robotoMono(
                  // Monospace biar angkanya diam
                  fontSize: 32,
                  color: Colors.white,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 2,
                ),
              ),
            ),
          ] else if (status == 'standby' &&
              !_isInArea &&
              _remainingTimeSeconds == null) ...[
            // Fallback if null
            const SizedBox(height: 16),
            const CircularProgressIndicator(color: Colors.white),
          ] else if (status == 'offline' && !_isInArea) ...[
            const SizedBox(height: 8),
            Text(
              "Masuk area bandara untuk antri",
              style: GoogleFonts.outfit(fontSize: 14, color: Colors.white70),
            ),
          ],

          // Queue info
          if (status == 'standby') ...[
            const SizedBox(height: 16),
            Text(
              "Antrian #${queuePosition ?? profile?['line_number'] ?? '-'}",
              style: GoogleFonts.outfit(fontSize: 20, color: Colors.white),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildActionButtons(String status) {
    if (status == 'offline') {
      if (!_isInArea) {
        return ElevatedButton(
          style: ElevatedButton.styleFrom(
            backgroundColor: Colors.white10,
            foregroundColor: Colors.white30,
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
            ),
          ),
          onPressed: null,
          child: Text(
            "TIDAK BISA MASUK ANTRIAN",
            style: GoogleFonts.outfit(fontWeight: FontWeight.bold),
          ),
        );
      } else {
        return ElevatedButton.icon(
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFFD4AF37),
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
            ),
          ),
          icon: const Icon(Icons.login, color: Colors.black),
          label: Text(
            "GABUNG ANTRIAN (MANUAL)",
            style: GoogleFonts.outfit(
              fontWeight: FontWeight.bold,
              color: Colors.black,
            ),
          ),
          onPressed: () {
            _joinQueue();
          },
        );
      }
    } else if (status == 'standby') {
      return ElevatedButton.icon(
        style: ElevatedButton.styleFrom(
          backgroundColor: Colors.blueAccent, // Change to Blue to distinguish
          padding: const EdgeInsets.symmetric(vertical: 16),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
        icon: const Icon(Icons.person_add, color: Colors.white),
        label: Text(
          "DAPAT PENUMPANG SENDIRI",
          style: GoogleFonts.outfit(
            fontWeight: FontWeight.bold,
            color: Colors.white,
          ),
        ),
        onPressed: () {
          _showSelfPassengerDialog();
        },
      );
    }
    return const SizedBox.shrink();
  }
}
