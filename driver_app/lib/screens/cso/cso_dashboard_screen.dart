import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import '../../models/cso_transaction.dart';
import '../../services/api_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/format.dart';
import '../../widgets/cso/cso_receipt_sheet.dart';

/// Dashboard CSO: ringkasan yang dikelola CSO — pendapatan & transaksi hari ini,
/// rincian Tunai/QRIS, peta posisi supir, dan transaksi terakhir.
/// Peta memakai OpenStreetMap (gratis, tanpa API key). Auto-refresh tiap 20 detik.
class CsoDashboardScreen extends StatefulWidget {
  const CsoDashboardScreen({super.key});

  @override
  State<CsoDashboardScreen> createState() => _CsoDashboardScreenState();
}

class _DriverLoc {
  final int id;
  final String name;
  final int? line;
  final String? car;
  final String? plate;
  final String status;
  final LatLng pos;

  _DriverLoc({
    required this.id,
    required this.name,
    required this.status,
    required this.pos,
    this.line,
    this.car,
    this.plate,
  });

  bool get isReady => status == 'standby' || status == 'available';

  factory _DriverLoc.fromJson(Map<String, dynamic> j) {
    num? n(dynamic v) => v is num ? v : num.tryParse(v?.toString() ?? '');
    int? i(dynamic v) => v == null ? null : int.tryParse(v.toString());
    return _DriverLoc(
      id: j['id'] as int,
      name: (j['name'] ?? '-').toString(),
      line: i(j['line_number']),
      car: j['car_model']?.toString(),
      plate: j['plate_number']?.toString(),
      status: (j['status'] ?? 'offline').toString(),
      pos: LatLng(
        (n(j['latitude']) ?? 0).toDouble(),
        (n(j['longitude']) ?? 0).toDouble(),
      ),
    );
  }
}

class _CsoDashboardScreenState extends State<CsoDashboardScreen> {
  final _api = ApiService();
  final _mapController = MapController();

  // Map
  List<_DriverLoc> _drivers = [];
  LatLng _base = const LatLng(-0.371975, 117.257919);
  double _radiusKm = 2.6;

  // Stats
  num _revenue = 0, _cash = 0, _qris = 0;
  int _count = 0, _queueReady = 0;
  List<CsoTransaction> _recent = [];

  bool _loading = true;
  String? _error;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load(initial: true);
    _timer = Timer.periodic(const Duration(seconds: 20), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool initial = false}) async {
    if (initial) setState(() => _loading = true);
    try {
      final results = await Future.wait([
        _api.getCsoDashboardStats(),
        _api.getCsoDriverLocations(),
      ]);
      if (!mounted) return;

      final stats = (results[0].data as Map).cast<String, dynamic>();
      final today =
          (stats['today'] as Map?)?.cast<String, dynamic>() ?? const {};
      final queue =
          (stats['queue'] as Map?)?.cast<String, dynamic>() ?? const {};
      final recent = ((stats['recent'] as List?) ?? [])
          .map(
            (e) => CsoTransaction.fromJson((e as Map).cast<String, dynamic>()),
          )
          .toList();

      final locs = (results[1].data as Map).cast<String, dynamic>();
      final base = (locs['base'] as Map?)?.cast<String, dynamic>() ?? const {};
      final drivers = ((locs['drivers'] as List?) ?? [])
          .map((e) => _DriverLoc.fromJson((e as Map).cast<String, dynamic>()))
          .toList();

      num toNum(dynamic v) => v is num ? v : num.tryParse('$v') ?? 0;
      int toInt(dynamic v) => int.tryParse('$v') ?? 0;

      setState(() {
        _revenue = toNum(today['revenue']);
        _cash = toNum(today['cash']);
        _qris = toNum(today['qris']);
        _count = toInt(today['count']);
        _queueReady = toInt(queue['ready']);
        _recent = recent;
        _drivers = drivers;
        if (base['latitude'] != null && base['longitude'] != null) {
          _base = LatLng(
            (base['latitude'] as num).toDouble(),
            (base['longitude'] as num).toDouble(),
          );
        }
        if (base['radius_km'] != null) {
          _radiusKm = (base['radius_km'] as num).toDouble();
        }
        _loading = false;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = 'Gagal memuat dashboard.';
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () => _load(initial: false),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
        children: [
          if (_error != null) _errorBanner(),
          _heroCard(),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _miniStat(
                  'Tunai',
                  _cash,
                  Icons.payments_rounded,
                  AppColors.success,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _miniStat(
                  'QRIS',
                  _qris,
                  Icons.qr_code_rounded,
                  AppColors.skyBlue,
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          _mapCard(),
          const SizedBox(height: 16),
          _recentCard(),
        ],
      ),
    );
  }

  Widget _errorBanner() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.cloud_off_rounded,
            color: AppColors.warning,
            size: 18,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              _error!,
              style: const TextStyle(color: AppColors.inkSoft, fontSize: 12.5),
            ),
          ),
          TextButton(
            onPressed: () => _load(initial: true),
            child: const Text('Coba lagi'),
          ),
        ],
      ),
    );
  }

  Widget _heroCard() {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: AppColors.skyGradient,
        borderRadius: BorderRadius.circular(20),
        boxShadow: const [
          BoxShadow(
            color: Color(0x330B4DA2),
            blurRadius: 16,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Pendapatan Hari Ini',
            style: TextStyle(
              color: Colors.white70,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            _loading ? '—' : formatRupiah(_revenue),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 30,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              _heroChip(Icons.receipt_long_rounded, '$_count transaksi'),
              const SizedBox(width: 8),
              _heroChip(Icons.groups_rounded, '$_queueReady supir ready'),
            ],
          ),
        ],
      ),
    );
  }

  Widget _heroChip(IconData icon, String text) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.2),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: Colors.white, size: 14),
          const SizedBox(width: 5),
          Text(
            text,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }

  Widget _miniStat(String label, num value, IconData icon, Color color) {
    return Container(
      padding: const EdgeInsets.all(14),
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 30,
                height: 30,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(9),
                ),
                child: Icon(icon, color: color, size: 17),
              ),
              const SizedBox(width: 8),
              Text(
                label,
                style: const TextStyle(
                  color: AppColors.inkSoft,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            _loading ? '—' : formatRupiah(value),
            style: const TextStyle(
              color: AppColors.ink,
              fontSize: 16,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }

  Widget _mapCard() {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0F0B4DA2),
            blurRadius: 12,
            offset: Offset(0, 5),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 12, 10),
            child: Row(
              children: [
                const Icon(
                  Icons.map_rounded,
                  color: AppColors.deepBlue,
                  size: 20,
                ),
                const SizedBox(width: 8),
                const Text(
                  'Lokasi Supir',
                  style: TextStyle(
                    color: AppColors.ink,
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 4,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.paleBlue,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    '${_drivers.length} supir · $_queueReady ready',
                    style: const TextStyle(
                      color: AppColors.deepBlue,
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ],
            ),
          ),
          SizedBox(
            height: 260,
            child: Stack(
              children: [
                FlutterMap(
                  mapController: _mapController,
                  options: MapOptions(initialCenter: _base, initialZoom: 13.2),
                  children: [
                    TileLayer(
                      // Tile resolusi tinggi (@2x) + gaya bersih (CARTO Voyager),
                      // jauh lebih tajam di layar HP berkepadatan tinggi.
                      urlTemplate:
                          'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
                      subdomains: const ['a', 'b', 'c', 'd'],
                      retinaMode: RetinaMode.isHighDensity(context),
                      userAgentPackageName: 'com.example.driver_app',
                    ),
                    CircleLayer(
                      circles: [
                        CircleMarker(
                          point: _base,
                          radius: _radiusKm * 1000,
                          useRadiusInMeter: true,
                          color: AppColors.skyBlue.withValues(alpha: 0.08),
                          borderColor: AppColors.skyBlue.withValues(
                            alpha: 0.45,
                          ),
                          borderStrokeWidth: 1.5,
                        ),
                      ],
                    ),
                    MarkerLayer(
                      markers: [
                        Marker(
                          point: _base,
                          width: 40,
                          height: 40,
                          child: _baseMarker(),
                        ),
                        ..._drivers.map(
                          (d) => Marker(
                            point: d.pos,
                            width: 36,
                            height: 36,
                            child: GestureDetector(
                              onTap: () => _showDriverInfo(d),
                              child: _driverMarker(d),
                            ),
                          ),
                        ),
                      ],
                    ),
                    const RichAttributionWidget(
                      attributions: [TextSourceAttribution('© OpenStreetMap, CARTO')],
                    ),
                  ],
                ),
                Positioned(
                  right: 10,
                  bottom: 10,
                  child: _circleBtn(
                    Icons.my_location_rounded,
                    () => _mapController.move(_base, 13.2),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _recentCard() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0F0B4DA2),
            blurRadius: 12,
            offset: Offset(0, 5),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.history_rounded, color: AppColors.deepBlue, size: 20),
              SizedBox(width: 8),
              Text(
                'Transaksi Terakhir',
                style: TextStyle(
                  color: AppColors.ink,
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          if (_recent.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 16),
              child: Center(
                child: Text(
                  'Belum ada transaksi.',
                  style: TextStyle(
                    color: AppColors.inkSoft,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            )
          else
            ..._recent.map(_recentTile),
        ],
      ),
    );
  }

  Widget _recentTile(CsoTransaction tx) {
    final time = tx.createdAt?.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    final timeStr = time == null
        ? ''
        : '${two(time.day)}/${two(time.month)} ${two(time.hour)}:${two(time.minute)}';
    final methodLabel = tx.method == 'CashDriver'
        ? 'Tunai (Supir)'
        : (tx.method == 'CashCSO' ? 'Tunai (Kasir)' : 'QRIS');

    return InkWell(
      onTap: () => CsoReceiptSheet.show(context, tx.receiptData),
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: AppColors.paleBlue,
                borderRadius: BorderRadius.circular(11),
              ),
              child: const Icon(
                Icons.local_taxi_rounded,
                color: AppColors.deepBlue,
                size: 19,
              ),
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
                      fontWeight: FontWeight.w700,
                      fontSize: 13.5,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    '$methodLabel · $timeStr',
                    style: const TextStyle(
                      color: AppColors.inkSoft,
                      fontSize: 11.5,
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
                fontSize: 13.5,
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _showDriverInfo(_DriverLoc d) {
    showModalBottomSheet(
      context: context,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => Padding(
        padding: const EdgeInsets.all(20),
        child: Row(
          children: [
            Container(
              width: 50,
              height: 50,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: d.isReady ? AppColors.success : AppColors.inkFaint,
                shape: BoxShape.circle,
              ),
              child: Text(
                d.line != null ? '#${d.line}' : '•',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              ),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    d.name,
                    style: const TextStyle(
                      color: AppColors.ink,
                      fontWeight: FontWeight.w800,
                      fontSize: 16,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    '${d.car ?? '-'} • ${d.plate ?? '-'}',
                    style: const TextStyle(
                      color: AppColors.inkSoft,
                      fontSize: 13,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 9,
                      vertical: 3,
                    ),
                    decoration: BoxDecoration(
                      color:
                          (d.isReady ? AppColors.success : AppColors.inkFaint)
                              .withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      d.isReady ? 'Ready' : d.status,
                      style: TextStyle(
                        color: d.isReady
                            ? AppColors.success
                            : AppColors.inkSoft,
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                      ),
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

  Widget _baseMarker() {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.deepBlue,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 2),
      ),
      child: const Icon(
        Icons.local_airport_rounded,
        color: Colors.white,
        size: 22,
      ),
    );
  }

  Widget _driverMarker(_DriverLoc d) {
    final color = d.isReady ? AppColors.success : AppColors.inkFaint;
    final label = d.line != null ? '${d.line}' : '•';
    return Container(
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 2),
        boxShadow: const [
          BoxShadow(
            color: Color(0x33000000),
            blurRadius: 5,
            offset: Offset(0, 2),
          ),
        ],
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w800,
          fontSize: 12,
        ),
      ),
    );
  }

  Widget _circleBtn(IconData icon, VoidCallback onTap) {
    return Material(
      color: AppColors.surface,
      shape: const CircleBorder(),
      elevation: 3,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Icon(icon, color: AppColors.deepBlue, size: 20),
        ),
      ),
    );
  }
}
