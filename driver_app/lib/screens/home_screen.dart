import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:intl/intl.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import 'package:dio/dio.dart';

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
  int? _remainingTimeSeconds; // In Seconds

  @override
  void initState() {
    super.initState();
    _startLocationService();
    _startCountdownTimer();
  }

  @override
  void dispose() {
    _locationTimer?.cancel();
    _countdownTimer?.cancel();
    super.dispose();
  }

  void _startCountdownTimer() {
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (_remainingTimeSeconds != null && _remainingTimeSeconds! > 0) {
        if (mounted) {
          setState(() {
            _remainingTimeSeconds = _remainingTimeSeconds! - 1;
          });
        }
      }
    });
  }

  Future<void> _startLocationService() async {
    var status = await Permission.location.request();
    if (status.isGranted) {
      _locationTimer = Timer.periodic(const Duration(seconds: 30), (
        timer,
      ) async {
        await _sendLocationUpdate();
      });
      _sendLocationUpdate();
    } else {
      if (mounted) setState(() => _locationStatus = "Izin GPS Ditolak");
    }
  }

  Future<void> _sendLocationUpdate() async {
    try {
      Position position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );
      final authProvider = Provider.of<AuthProvider>(context, listen: false);

      final response = await _apiService.updateLocation(
        position.latitude,
        position.longitude,
      );

      final inArea = response.data['in_area'] ?? false;
      var remainingRaw = response.data['remaining_time'];
      int? remaining;
      if (remainingRaw != null) {
        remaining = int.tryParse(remainingRaw.toString());
      }

      if (mounted) {
        setState(() {
          _locationStatus = "GPS: ${TimeOfDay.now().format(context)}";
          _isInArea = inArea;
          _remainingTimeSeconds = remaining; // Sync with server
        });

        // Auto-refresh profile if auto-joined
        if (response.data['status'] == 'standby' &&
            authProvider.user?['driver_profile']['status'] == 'offline') {
          authProvider.fetchProfile();
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text("Anda memasuki area. Otomatis masuk antrian!"),
            ),
          );
        }

        // Auto-kick notification
        if (response.data['status'] == 'offline' &&
            authProvider.user?['driver_profile']['status'] == 'standby') {
          authProvider.fetchProfile();
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(response.data['message'] ?? "Antrian Hangus"),
              backgroundColor: Colors.red,
            ),
          );
        }
      }
    } catch (e) {
      // Silent error
    }
  }

  Future<void> _startTrip(int bookingId) async {
    final auth = Provider.of<AuthProvider>(context, listen: false);
    try {
      await _apiService.startBooking(bookingId);
      await auth.fetchProfile();
      if (mounted)
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text("Perjalanan Dimulai!")));
    } catch (e) {
      if (mounted)
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text("Gagal memulai perjalanan")),
        );
    }
  }

  Future<void> _completeTrip(int bookingId) async {
    final auth = Provider.of<AuthProvider>(context, listen: false);
    try {
      await _apiService.completeBooking(bookingId);
      await auth.fetchProfile();
      if (mounted)
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text("Perjalanan Selesai!")));
    } catch (e) {
      if (mounted)
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text("Gagal menyelesaikan perjalanan")),
        );
    }
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
                            user?['name'] ?? 'Driver',
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
                _buildStatusCard(status, profile),
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
    final paymentMethod = booking['transaction']?['method'] ?? 'Tunai';
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

  Widget _buildStatusCard(String status, Map<String, dynamic>? profile) {
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
              "Antrian #${profile?['line_number'] ?? '-'}",
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
            // TODO: Manual Join Logic
          },
        );
      }
    } else if (status == 'standby') {
      return ElevatedButton.icon(
        style: ElevatedButton.styleFrom(
          backgroundColor: Colors.redAccent,
          padding: const EdgeInsets.symmetric(vertical: 16),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
        icon: const Icon(Icons.logout, color: Colors.white),
        label: Text(
          "KELUAR ANTRIAN",
          style: GoogleFonts.outfit(
            fontWeight: FontWeight.bold,
            color: Colors.white,
          ),
        ),
        onPressed: () {
          // TODO: Leave Logic
        },
      );
    }
    return const SizedBox.shrink();
  }
}
