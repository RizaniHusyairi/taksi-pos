import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:geolocator/geolocator.dart';
import 'package:intl/intl.dart';
import '../../config/api_constants.dart';
import '../../services/api_service.dart';

class DashboardTab extends StatefulWidget {
  const DashboardTab({super.key});

  @override
  State<DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<DashboardTab>
    with WidgetsBindingObserver {
  // Config Lokasi Bandara
  static const double airportLat = -0.371975;
  static const double airportLng = 117.257919;
  static const double maxRadiusKm = 2.0;

  final _service = FlutterBackgroundService();
  final _api = ApiService();

  // State Data
  Map<String, dynamic>? _driverProfile;
  Map<String, dynamic>? _activeBooking;
  Position? _currentPosition;
  bool _isLoading = true;
  bool _isServiceRunning = false;
  Timer? _refreshTimer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _init();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _refreshTimer?.cancel();
    super.dispose();
  }

  // Auto refresh saat app resume
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _fetchData();
    }
  }

  Future<void> _init() async {
    // Cek status service background
    _isServiceRunning = await _service.isRunning();

    // Listen status service
    _service.on('stopService').listen((event) {
      if (mounted) setState(() => _isServiceRunning = false);
    });

    // Mulai listener lokasi UI
    _startLocationStream();

    await _fetchData();

    // Auto refresh data every 30s
    _refreshTimer = Timer.periodic(
      const Duration(seconds: 30),
      (_) => _fetchData(),
    );
  }

  void _startLocationStream() {
    Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 10,
      ),
    ).listen((pos) {
      if (mounted) setState(() => _currentPosition = pos);
    });
  }

  Future<void> _fetchData() async {
    if (!mounted) return;
    try {
      final res = await _api.client.get(ApiConstants.profile);
      if (mounted) {
        setState(() {
          _driverProfile = res.data['driver_profile'];
          _activeBooking = res.data['active_booking'];
          _isLoading = false;

          // Sinkronisasi status service dengan status 'on_trip' atau 'standby'
          // Jika status standby/ontrip tapi service mati -> nyalakan
          // Fitur ini optional, tapi membantu agar service tidak mati sendiri
        });
      }
    } catch (e) {
      print('Fetch Error: $e');
    }
  }

  Future<void> _handleQueueAction() async {
    if (_driverProfile == null) return;
    final status = _driverProfile!['status'];

    // 1. Logic Keluar Antrian
    if (status == 'standby') {
      _showLeaveDialog();
      return;
    }

    // 2. Logic Masuk Antrian / Tunggu GPS
    if (_currentPosition == null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Menunggu sinyal GPS...')));
      return;
    }

    final dist =
        Geolocator.distanceBetween(
          airportLat,
          airportLng,
          _currentPosition!.latitude,
          _currentPosition!.longitude,
        ) /
        1000;

    if (dist > maxRadiusKm) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Terlalu jauh (${dist.toStringAsFixed(1)} km). Ke bandara dulu.',
          ),
        ),
      );
      return;
    }

    // Eksekusi Join
    try {
      setState(() => _isLoading = true);

      // Nyalakan Service Background dulu
      if (!await _service.isRunning()) {
        await _service.startService();
        setState(() => _isServiceRunning = true);
      }

      await _api.client.post(
        ApiConstants.queueJoin,
        data: {
          'latitude': _currentPosition!.latitude,
          'longitude': _currentPosition!.longitude,
        },
      );

      await _fetchData(); // Refresh UI
    } on DioException catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.response?.data['message'] ?? 'Gagal masuk antrian'),
          backgroundColor: Colors.red,
        ),
      );
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void _showLeaveDialog() {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Keluar Antrian?'),
        content: const Text('Anda akan kehilangan posisi antrian.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Batal'),
          ),
          TextButton(
            child: const Text('Ya, Keluar'),
            onPressed: () async {
              Navigator.pop(context);
              try {
                setState(() => _isLoading = true);

                // Matikan Service Background
                _service.invoke('stopService');
                setState(() => _isServiceRunning = false);

                await _api.client.post(
                  ApiConstants.queueLeave,
                  data: {'reason': 'other'},
                );
                await _fetchData();
              } catch (e) {
                print(e);
              } finally {
                setState(() => _isLoading = false);
              }
            },
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading && _driverProfile == null) {
      return const Center(child: CircularProgressIndicator());
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Taksi POS'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () {
              setState(() => _isLoading = true);
              _fetchData();
            },
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _fetchData,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // 1. Active Order Section
            if (_activeBooking != null) _buildActiveOrderCard(),
            if (_activeBooking != null) const SizedBox(height: 20),

            // 2. Status Card
            const Text(
              'Antrian Bandara',
              style: TextStyle(fontWeight: FontWeight.bold, color: Colors.grey),
            ),
            const SizedBox(height: 8),
            _buildStatusCard(),

            // 3. Info Panel
            const SizedBox(height: 20),
            if (_isServiceRunning)
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.green.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: Colors.green.withOpacity(0.3)),
                ),
                child: Row(
                  children: const [
                    Icon(Icons.location_on, color: Colors.green),
                    SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        'Service Lokasi Aktif. Lokasi Anda terus dipantau di background.',
                        style: TextStyle(fontSize: 12),
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

  Widget _buildStatusCard() {
    final status = _driverProfile?['status'] ?? 'offline';
    final isStandby = status == 'standby';
    final isOnTrip = status == 'ontrip' || _activeBooking != null;

    String statusText = 'Offline';
    Color statusColor = Colors.grey;
    if (isStandby) {
      statusText = 'Standby (Siap Dipilih)';
      statusColor = Colors.green;
    } else if (isOnTrip) {
      statusText = 'Sedang Mengantar';
      statusColor = Colors.orange;
    }

    // Distance
    double distKm = 0;
    if (_currentPosition != null) {
      distKm =
          Geolocator.distanceBetween(
            airportLat,
            airportLng,
            _currentPosition!.latitude,
            _currentPosition!.longitude,
          ) /
          1000;
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Status Saat Ini',
                      style: TextStyle(fontSize: 12, color: Colors.grey),
                    ),
                    Text(
                      statusText,
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: statusColor,
                      ),
                    ),
                  ],
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    const Text(
                      'Jarak ke Bandara',
                      style: TextStyle(fontSize: 12, color: Colors.grey),
                    ),
                    Text(
                      _currentPosition == null
                          ? 'Mencari GPS...'
                          : '${distKm.toStringAsFixed(2)} km',
                      style: const TextStyle(
                        fontSize: 16,
                        fontFamily: 'monospace',
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF2563eb),
                      ),
                    ),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: isOnTrip || (_currentPosition == null && !isStandby)
                    ? null
                    : _handleQueueAction,
                style: ElevatedButton.styleFrom(
                  backgroundColor: isStandby
                      ? Colors.red
                      : const Color(0xFF2563eb),
                ),
                child: Text(
                  isOnTrip
                      ? 'Selesaikan Order Dulu'
                      : isStandby
                      ? 'Keluar Antrian'
                      : 'Masuk Antrian',
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
              ),
            ),
            const SizedBox(height: 8),
            const Text(
              '*Pastikan berada dalam radius 2 KM dari Bandara',
              style: TextStyle(
                fontSize: 10,
                color: Colors.grey,
                fontStyle: FontStyle.italic,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildActiveOrderCard() {
    final b = _activeBooking!;
    final price = NumberFormat.currency(
      locale: 'id',
      symbol: 'Rp ',
      decimalDigits: 0,
    ).format(b['price']);

    return Card(
      clipBehavior: Clip.antiAlias,
      elevation: 4,
      child: Column(
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(12),
            color: const Color(0xFF2563eb),
            child: Column(
              children: [
                const Icon(Icons.directions_car, color: Colors.white, size: 32),
                const SizedBox(height: 4),
                const Text(
                  'PESANAN BERJALAN',
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 1.2,
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                _buildRowInfo('Tuiuan', b['zone_to']['name'], isBig: true),
                const Divider(),
                _buildRowInfo('Penumpang', '${b['passenger_phone'] ?? '-'}'),
                const SizedBox(height: 16),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.grey[100],
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text('Tarif', style: TextStyle(color: Colors.grey)),
                      Text(
                        price,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: () {
                      // Implement finish order Logic (API Call)
                      // _finishOrder();
                    },
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.green,
                    ),
                    child: const Text('Selesaikan Order'),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildRowInfo(String label, String value, {bool isBig = false}) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 100,
          child: Text(
            label,
            style: const TextStyle(
              fontSize: 12,
              color: Colors.grey,
              fontWeight: FontWeight.bold,
            ),
          ),
        ),
        Expanded(
          child: Text(
            value,
            style: TextStyle(
              fontSize: isBig ? 16 : 14,
              fontWeight: isBig ? FontWeight.bold : FontWeight.normal,
            ),
          ),
        ),
      ],
    );
  }
}
